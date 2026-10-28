<?php
// admin/reorder_points.php — mungkahing reorder point at reorder qty mula sa totoong benta (Phase 8c).
// Ang kuwenta: includes/forecast_ops.php (sx_reorder_suggestions). Ikaw ang magpapasya kung ia-apply.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php';   // sx_flash, sx_csrf_*
require_once __DIR__ . '/../includes/forecast_ops.php';
require_once __DIR__ . '/../includes/log_helper.php';

$window = sx_forecast_window();

// =================================================================================
// ACTION: i-apply ang napiling mungkahi
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    try {
        if (!sx_csrf_ok()) throw new RuntimeException('Your session expired. Please try again.');
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['pick'] ?? []))));
        if (!$ids) throw new RuntimeException('Tick at least one product to update.');
        $n = sx_apply_reorder_points($pdo, $ids, $window);
        if ($n === 0) throw new RuntimeException('Nothing changed. The suggestions may have moved; review the list and try again.');
        $adminId = (int)($_SESSION['user']['id'] ?? 0);
        log_user_action($pdo, $adminId, 'REORDER_POINTS', "Applied suggested reorder points to {$n} product(s) using the last {$window} days of sales.", $adminId);
        sx_flash(['success', "Updated the reorder point and quantity of {$n} product" . ($n === 1 ? '' : 's') . '.']);
    } catch (RuntimeException $e) {
        sx_flash(['error', $e->getMessage()]);
    } catch (PDOException $e) {
        error_log('Apply reorder points failed: ' . $e->getMessage());
        sx_flash(['error', 'Could not update the products. Please try again.']);
    }
    header('Location: reorder_points.php?window=' . $window);
    exit;
}

// =================================================================================
// DISPLAY
// =================================================================================
$rows = sx_reorder_suggestions($pdo, $window);
$count = ['raise' => 0, 'lower' => 0, 'same' => 0, 'no_data' => 0];
foreach ($rows as $r) $count[$r['status']]++;
// Mga may pagbabago muna, pinakamalaking pagkakaiba ang una
$order = ['raise' => 0, 'lower' => 1, 'same' => 2, 'no_data' => 3];
$gap = fn($r) => $r['new_rp'] === null ? 0 : abs($r['new_rp'] - (int)$r['reorder_point']);
usort($rows, fn($a, $b) => [$order[$a['status']], $gap($b), $a['name']] <=> [$order[$b['status']], $gap($a), $b['name']]);
$changeable = $count['raise'] + $count['lower'];
$flash = sx_flash();
$csrf  = sx_csrf_token();
$placeholder = '/sweepxpress/assets/placeholder.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   SMART REORDER POINTS (Phase 8c) — tokens mula sa assets/style.css
   ============================================================ */
.rp { max-width: 1100px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.rp-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.rp-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 96px; padding: var(--sx-space-4); }
.rp-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.rp-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.rp-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.rp-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.rp-tile.hero span, .rp-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.rp-tile.hero strong { color: #fff; font-size: 2.2rem; }
.rp-how { margin-bottom: var(--sx-space-5); padding: var(--sx-space-4) var(--sx-space-5); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.rp-how b { color: var(--sx-dark); }

.rp-list { padding: 0; overflow: hidden; }
.rp-item { display: grid; grid-template-columns: auto 48px minmax(0, 1fr); gap: var(--sx-space-2) var(--sx-space-3); align-items: center; padding: var(--sx-space-3) var(--sx-space-5); border-bottom: 1px solid var(--sx-border-light); }
.rp-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--sx-primary); }
.rp-item img { width: 48px; height: 48px; border-radius: var(--sx-radius-sm); background: var(--sx-surface-alt); object-fit: cover; }
.rp-item .info { min-width: 0; }
.rp-item .info strong { display: block; overflow: hidden; color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.rp-item .info small { display: block; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.rp-item .info .warnnote { color: var(--sx-status-pending); }
.rp-change { grid-column: 2 / -1; display: flex; flex-wrap: wrap; gap: var(--sx-space-2) var(--sx-space-5); align-items: baseline; }
.rp-change div { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.rp-change b { font-size: var(--sx-text-base); color: var(--sx-dark); }
.rp-change .arrow { margin-inline: 4px; color: var(--sx-muted); }
.rp-change .up b.new { color: var(--sx-status-pending); }
.rp-change .down b.new { color: var(--sx-primary); }
.rp-item.dim { opacity: 0.6; }
.rp-sec { padding: var(--sx-space-2) var(--sx-space-5); background: var(--sx-surface-alt); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); letter-spacing: 0.04em; text-transform: uppercase; color: var(--sx-muted); }
.rp-foot { position: sticky; bottom: 0; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-3); padding: var(--sx-space-4) var(--sx-space-5); background: var(--sx-surface); border-top: 1px solid var(--sx-border-light); }
.rp-foot span { font-size: var(--sx-text-sm); color: var(--sx-muted); }
@media (min-width: 768px) {
    .rp-bento { grid-template-columns: 1.6fr repeat(3, 1fr); }
    .rp-tile.hero { grid-column: span 1; }
    .rp-item { grid-template-columns: auto 48px minmax(0, 1.2fr) minmax(0, 2fr); }
    .rp-change { grid-column: auto; }
}
</style>

<div class="rp sx-container">
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Smart reorder points</h1>
            <p>Suggested reorder points and quantities from your real sales and each supplier's lead time. Nothing changes until you apply it.</p>
        </div>
        <div style="display: flex; align-items: center; gap: var(--sx-space-3); flex-wrap: wrap">
            <?= sx_forecast_picker($window) ?>
            <a href="reorder.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-cart-flatbed"></i> Reorder</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="rp-bento">
        <div class="sx-card rp-tile hero">
            <span>Suggested changes</span>
            <strong><?= $changeable ?></strong>
            <small>of <?= count($rows) ?> products, from the last <?= (int)$window ?> days</small>
        </div>
        <div class="sx-card rp-tile"><span>Raise</span><strong><?= $count['raise'] ?></strong><small>Selling faster than the current point</small></div>
        <div class="sx-card rp-tile"><span>Lower</span><strong><?= $count['lower'] ?></strong><small>Too much held back</small></div>
        <div class="sx-card rp-tile"><span>Not enough sales</span><strong><?= $count['no_data'] ?></strong><small>Fewer than <?= SX_RP_MIN_ORDERS ?> orders</small></div>
    </div>

    <div class="sx-card rp-how">
        <b>How it is worked out:</b> sales per day × the supplier's lead time, plus a safety buffer for days when sales jump
        (enough to cover about 95% of the cases). The suggested order quantity is about <?= SX_RP_CYCLE_DAYS ?> days of sales.
        A single huge order is capped so it does not distort the result. Products without a supplier assume a <?= SX_RP_DEFAULT_LEAD ?>-day lead time.
    </div>

    <form method="post" class="sx-card rp-list" id="rpForm">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="apply">
        <?php
        $heads = ['raise' => 'Raise the reorder point', 'lower' => 'Lower the reorder point', 'same' => 'Already about right', 'no_data' => 'Not enough sales to suggest'];
        $last = null;
        foreach ($rows as $r):
            $st = $r['status'];
            if ($st !== $last) { echo '<div class="rp-sec">' . h($heads[$st]) . '</div>'; $last = $st; }
            $s = $r['stats'];
        ?>
            <div class="rp-item<?= in_array($st, ['same', 'no_data'], true) ? ' dim' : '' ?>">
                <input type="checkbox" name="pick[]" value="<?= (int)$r['id'] ?>" <?= in_array($st, ['raise', 'lower'], true) ? 'checked' : 'disabled' ?> aria-label="Update <?= h($r['name']) ?>">
                <img src="<?= h($r['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                <div class="info">
                    <strong><a href="edit_product.php?id=<?= (int)$r['id'] ?>" style="color: inherit; text-decoration: none"><?= h($r['name']) ?></a></strong>
                    <small><?= number_format((int)$r['stock']) ?> in stock · <?= h($r['supplier_name'] ?: 'No supplier') ?> · lead <?= (int)$r['lead'] ?> day<?= $r['lead'] === 1 ? '' : 's' ?><?= $r['lead_assumed'] ? ' (assumed)' : '' ?></small>
                    <?php if ($s && $s['capped']): ?><small class="warnnote"><i class="fa-solid fa-scissors"></i> A bulk order day was capped</small><?php endif; ?>
                </div>
                <div class="rp-change">
                    <?php if ($st === 'no_data'): ?>
                        <div>Reorder point <b><?= (int)$r['reorder_point'] ?></b> · <?= $s ? (int)$s['orders'] . ' order' . ($s['orders'] === 1 ? '' : 's') . ' in this window' : 'No sales in this window' ?></div>
                    <?php else: $dir = $st === 'raise' ? 'up' : ($st === 'lower' ? 'down' : ''); ?>
                        <div class="<?= $dir ?>">Reorder point <b><?= (int)$r['reorder_point'] ?></b><?php if ($st !== 'same'): ?><span class="arrow"><?= $st === 'raise' ? '↑' : '↓' ?></span><b class="new"><?= (int)$r['new_rp'] ?></b><?php endif; ?></div>
                        <div>Order qty <b><?= $r['reorder_qty'] === null ? '—' : (int)$r['reorder_qty'] ?></b><?php if ($st !== 'same'): ?><span class="arrow">→</span><b><?= (int)$r['new_rq'] ?></b><?php endif; ?></div>
                        <div>Sells <b><?= rtrim(rtrim(number_format($s['mean'], 2), '0'), '.') ?></b>/day · safety <b><?= (int)$r['safety'] ?></b></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <div class="sx-empty"><i class="fa-solid fa-box-open"></i><h3>No products yet</h3></div>
        <?php endif; ?>
        <div class="rp-foot">
            <span><strong data-rp-count><?= $changeable ?></strong> selected</span>
            <button type="submit" class="sx-btn sx-btn-primary" <?= $changeable ? '' : 'disabled' ?>><i class="fa-solid fa-wand-magic-sparkles"></i> Apply selected</button>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('rpForm');
    if (!form) return;
    var out = form.querySelector('[data-rp-count]');
    var btn = form.querySelector('button[type="submit"]');
    function sync() {
        var n = form.querySelectorAll('input[name="pick[]"]:checked').length;
        out.textContent = n;
        btn.disabled = n === 0;
    }
    form.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
