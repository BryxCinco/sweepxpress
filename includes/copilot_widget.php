<?php
// includes/copilot_widget.php — floating chat ng Admin Copilot (Phase 8e-3). Isinasama ng includes/footer.php.
// Lumilitaw LANG para sa admin, at LANG sa mga page sa /admin/. Ang logic ng chat: assets/copilot.js,
// ang backend: admin/copilot_api.php. Kapag wala pang GROQ_API_KEY, nagpapakita ng paalala sa loob ng panel.
if (!function_exists('is_admin') || !is_admin() || !str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/')) return;

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));   // parehong key ng sx_csrf_token()
$sxCpReady = trim((string)env('GROQ_API_KEY', '')) !== '';

// Mga mungkahing tanong ayon sa page na kinaroroonan
$sxCpPage = basename($_SERVER['SCRIPT_NAME'], '.php');
$sxCpChips = match (true) {
    $sxCpPage === 'dashboard' => ["Give me today's brief", 'What needs my attention right now?', 'How are sales this month compared with last month?'],
    in_array($sxCpPage, ['reorder', 'reorder_points'], true) => ['What should I order today?', 'Ano ang dapat kong i-reorder ngayon?', 'Explain the suggested reorder points'],
    str_starts_with($sxCpPage, 'inventory') || str_starts_with($sxCpPage, 'stock_') || $sxCpPage === 'product_history'
        => ['Which items will run out before the supplier can deliver?', 'What is out of stock?', 'Which products are not selling?'],
    str_starts_with($sxCpPage, 'purchase_order') || str_starts_with($sxCpPage, 'supplier')
        => ['Which purchase orders are overdue?', 'Who is my most reliable supplier?', 'Which supplier raised their prices?'],
    str_starts_with($sxCpPage, 'order') || in_array($sxCpPage, ['allorders', 'deliveries', 'deliveries_edit'], true)
        => ['Summarize the orders waiting to be processed', 'Are there stale pending orders?', 'What were the best sellers this month?'],
    str_contains($sxCpPage, 'product')
        => ['Which products have the best margin?', 'Which products are not selling?', 'What are my top 5 products this month?'],
    default => ["Give me today's brief", 'What should I reorder?', 'Which suppliers are late?'],
};
?>
<style>
/* ============================================================
   ADMIN COPILOT (Phase 8e-3) — tokens mula sa assets/style.css
   ============================================================ */
.cp-fab { position: fixed; right: 20px; bottom: 20px; z-index: 1060; display: grid; place-items: center; width: 56px; height: 56px; padding: 0; border: 0; border-radius: 50%;
          background: var(--sx-gradient-brand); color: #fff; font-size: 1.25rem; box-shadow: var(--sx-shadow-lg); cursor: pointer; transition: transform var(--sx-transition); }
.cp-fab:hover { transform: scale(1.06); }
.cp-fab:focus-visible, .cp-btn:focus-visible, .cp-chip:focus-visible, .cp-send:focus-visible { outline: 3px solid color-mix(in srgb, var(--sx-primary) 45%, transparent); outline-offset: 2px; }
.cp-open .cp-fab { visibility: hidden; }
.cp-backdrop { position: fixed; inset: 0; z-index: 1104; display: none; background: rgba(15, 23, 42, 0.45); }
.cp-open .cp-backdrop { display: block; }
.cp-panel { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1105; display: flex; flex-direction: column; width: min(420px, 100vw); background: var(--sx-surface); color: var(--sx-dark);
            border-left: 1px solid var(--sx-border-light); box-shadow: var(--sx-shadow-lg); transform: translateX(100%); visibility: hidden; transition: transform 0.25s ease, visibility 0.25s; }
.cp-open .cp-panel { transform: none; visibility: visible; }
.cp-head { display: flex; align-items: center; gap: var(--sx-space-3); padding: var(--sx-space-4); border-bottom: 1px solid var(--sx-border-light); }
.cp-logo { display: grid; place-items: center; flex-shrink: 0; width: 38px; height: 38px; border-radius: var(--sx-radius-md); background: var(--sx-gradient-brand); color: #fff; }
.cp-head h2 { margin: 0; font-size: var(--sx-text-base); font-weight: var(--sx-weight-extrabold); }
.cp-head small { display: block; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.cp-head .grow { flex: 1; min-width: 0; }
.cp-btn { display: grid; place-items: center; width: 34px; height: 34px; padding: 0; border: 0; border-radius: var(--sx-radius-md); background: transparent; color: var(--sx-muted); cursor: pointer; }
.cp-btn:hover { background: var(--sx-surface-alt); color: var(--sx-dark); }
.cp-log { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: var(--sx-space-3); padding: var(--sx-space-4); }
.cp-welcome { display: grid; gap: var(--sx-space-3); margin: auto 0; text-align: center; }
.cp-welcome .cp-logo { width: 52px; height: 52px; margin: 0 auto; font-size: 1.3rem; }
.cp-welcome h3 { margin: 0; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); }
.cp-welcome p { margin: 0; font-size: var(--sx-text-sm); color: var(--sx-muted); }
.cp-chips { display: grid; gap: var(--sx-space-2); text-align: left; }
.cp-chip { padding: 10px 14px; border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-md); background: var(--sx-surface); color: var(--sx-dark); font: inherit; font-size: var(--sx-text-sm); text-align: left; cursor: pointer; }
.cp-chip:hover { border-color: var(--sx-primary); background: var(--sx-primary-soft); }
.cp-msg { max-width: 88%; padding: 10px 14px; font-size: var(--sx-text-sm); line-height: 1.5; overflow-wrap: anywhere; }
.cp-msg.user { align-self: flex-end; border-radius: 16px 16px 4px 16px; background: var(--sx-primary); color: #fff; white-space: pre-wrap; }
.cp-msg.assistant { align-self: flex-start; border-radius: 16px 16px 16px 4px; background: var(--sx-surface-alt); }
.cp-msg.error { align-self: stretch; max-width: 100%; border-radius: var(--sx-radius-md); background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.cp-msg p { margin: 0 0 8px; }
.cp-msg p:last-child, .cp-msg ul:last-child, .cp-msg ol:last-child { margin-bottom: 0; }
.cp-msg ul, .cp-msg ol { margin: 0 0 8px; padding-left: 20px; }
.cp-msg a { color: var(--sx-primary); font-weight: var(--sx-weight-semibold); }
.cp-table { margin: 0 0 8px; overflow-x: auto; }
.cp-table table { border-collapse: collapse; font-size: var(--sx-text-xs); }
.cp-table th, .cp-table td { padding: 4px 8px; border: 1px solid var(--sx-border-light); text-align: left; white-space: nowrap; }
.cp-table th { background: var(--sx-surface); }
.cp-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--sx-border-light); }
.cp-tool { padding: 2px 8px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 0.7rem; font-weight: var(--sx-weight-semibold); }
.cp-copy { margin-left: auto; padding: 2px 8px; border: 0; border-radius: var(--sx-radius-sm); background: transparent; color: var(--sx-muted); font: inherit; font-size: 0.7rem; cursor: pointer; }
.cp-copy:hover { color: var(--sx-dark); }
.cp-typing { display: inline-flex; gap: 4px; padding: 4px 0; }
.cp-typing i { width: 7px; height: 7px; border-radius: 50%; background: var(--sx-muted); animation: cp-bounce 1.1s infinite ease-in-out; }
.cp-typing i:nth-child(2) { animation-delay: 0.15s; }
.cp-typing i:nth-child(3) { animation-delay: 0.3s; }
@keyframes cp-bounce { 0%, 80%, 100% { opacity: 0.3; transform: translateY(0); } 40% { opacity: 1; transform: translateY(-3px); } }
.cp-form { display: flex; align-items: flex-end; gap: var(--sx-space-2); padding: var(--sx-space-3) var(--sx-space-4) var(--sx-space-2); border-top: 1px solid var(--sx-border-light); }
.cp-form textarea { flex: 1; min-height: 40px; max-height: 120px; padding: 9px 12px; border: 1px solid var(--sx-border); border-radius: var(--sx-radius-md); background: var(--sx-surface); color: var(--sx-dark); font: inherit; font-size: var(--sx-text-sm); resize: none; }
.cp-form textarea:focus { outline: none; border-color: var(--sx-primary); }
.cp-send { display: grid; place-items: center; flex-shrink: 0; width: 40px; height: 40px; border: 0; border-radius: 50%; background: var(--sx-primary); color: #fff; cursor: pointer; }
.cp-send:disabled { background: var(--sx-surface-alt); color: var(--sx-muted); cursor: not-allowed; }
.cp-foot { padding: 0 var(--sx-space-4) var(--sx-space-3); font-size: 0.7rem; color: var(--sx-muted); }
@media (max-width: 767px) { .cp-fab { right: 16px; bottom: 16px; } .cp-panel { width: 100vw; } }
@media (min-width: 768px) { .cp-backdrop { display: none !important; } }
@media (prefers-reduced-motion: reduce) { .cp-panel, .cp-fab { transition: none; } .cp-typing i { animation: none; opacity: 0.6; } }
@media print { #sx-copilot { display: none !important; } }
</style>

<div id="sx-copilot" data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>" data-ready="<?= $sxCpReady ? '1' : '0' ?>"
     data-chips="<?= htmlspecialchars(json_encode($sxCpChips, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
    <button type="button" class="cp-fab" aria-label="Open Copilot assistant" aria-expanded="false" aria-controls="cp-panel"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></button>
    <div class="cp-backdrop" data-cp-close></div>
    <section class="cp-panel" id="cp-panel" role="dialog" aria-label="Copilot assistant" aria-modal="false">
        <header class="cp-head">
            <span class="cp-logo" aria-hidden="true"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
            <div class="grow"><h2>Copilot</h2><small>Reads your store data · changes nothing</small></div>
            <button type="button" class="cp-btn" data-cp-clear title="Clear conversation" aria-label="Clear conversation"><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" class="cp-btn" data-cp-close title="Close" aria-label="Close Copilot"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="cp-log" role="log" aria-live="polite" aria-relevant="additions"></div>
        <form class="cp-form" autocomplete="off">
            <textarea rows="1" maxlength="500" placeholder="Ask about stock, sales, suppliers…" aria-label="Your question"></textarea>
            <button type="submit" class="cp-send" aria-label="Send" disabled><i class="fa-solid fa-paper-plane"></i></button>
        </form>
        <div class="cp-foot"><span data-cp-hint>AI can make mistakes. Check important numbers.</span></div>
    </section>
</div>
<script src="/sweepxpress/assets/copilot.js?v=<?= filemtime(__DIR__ . '/../assets/copilot.js') ?>" defer></script>
