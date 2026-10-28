<?php
// admin/purchase_order.php — isang restock PO (Phase 7d).
// Draft: form (supplier, petsa, mga linya). Sent/Partial: tanggapin ang dumating, close short, cancel.
// Received/Cancelled: basahin lang. Walang ?id= → bagong draft (?supplier= para may napili na).
// Lahat ng aksyon: POST + CSRF + PRG; ang logic ay nasa includes/purchasing_ops.php.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php';   // sx_flash, sx_csrf_*
require_once __DIR__ . '/../includes/purchasing_ops.php';
require_once __DIR__ . '/../includes/supplier_ops.php';     // sx_supplier_initials

$id       = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$userId   = (int)($_SESSION['user']['id'] ?? 0);
$userName = (string)($_SESSION['user']['name'] ?? 'Admin');
$po = $id ? sx_po_get($pdo, $id) : null;
if ($id && !$po) {
    sx_flash(['error', 'Purchase order not found.']);
    header("Location: purchase_orders.php");
    exit;
}
$errors = [];
$form = null; // tinype ng admin kapag may error sa draft form

// =================================================================================
// ACTIONS
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sx_csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'save' || $action === 'save_send') {
        $form = [
            'supplier_id'   => (int)($_POST['supplier_id'] ?? 0),
            'expected_date' => trim($_POST['expected_date'] ?? ''),
            'notes'         => trim($_POST['notes'] ?? ''),
            'lines'         => sx_po_lines_from_post(),
        ];
        try {
            if (mb_strlen($form['notes']) > 2000) throw new RuntimeException('Notes are too long (2,000 characters max).');
            $meta = ['expected_date' => $form['expected_date'], 'notes' => $form['notes']];
            if ($id) {
                sx_po_update_draft($pdo, $id, $form['supplier_id'], $form['lines'], $meta);
            } else {
                $id = sx_po_create($pdo, $form['supplier_id'], $form['lines'], $meta, $userId, $userName);
            }
            $number = sx_po_number($id);
            if ($action === 'save_send') {
                sx_po_send($pdo, $id);
                sx_flash(['success', "{$number} was sent. Receive the stock here when it arrives."]);
            } else {
                sx_flash(['success', "{$number} was saved as a draft."]);
            }
            header("Location: purchase_order.php?id={$id}");
            exit;
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            error_log("PO save failed: " . $e->getMessage());
            $errors[] = 'Could not save the purchase order. Please try again.';
        }
        // may error: ipakita ulit ang form (walang redirect)
    } elseif ($po) {
        try {
            if ($action === 'receive') {
                $qtys = [];
                foreach ((array)($_POST['receive'] ?? []) as $pid => $q) {
                    $q = trim((string)$q);
                    if ($q === '') continue;
                    if (!ctype_digit($q)) throw new RuntimeException('Received quantities must be whole numbers.');
                    $qtys[(int)$pid] = (int)$q;
                }
                $r = sx_po_receive($pdo, $id, $qtys, $userName);
                sx_flash(['success', number_format($r['units']) . ' unit' . ($r['units'] === 1 ? '' : 's') . ' added to stock. '
                    . ($r['status'] === 'received' ? "{$po['po_number']} is complete." : 'The rest is still on order.')]);
            } elseif ($action === 'close_short') {
                sx_po_close_short($pdo, $id, (string)($_POST['reason'] ?? ''));
                sx_flash(['success', "{$po['po_number']} was closed. The missing units are no longer expected."]);
            } elseif ($action === 'cancel') {
                sx_po_cancel($pdo, $id, (string)($_POST['reason'] ?? ''));
                sx_flash(['success', "{$po['po_number']} was cancelled."]);
            } elseif ($action === 'delete') {
                sx_po_delete_draft($pdo, $id);
                sx_flash(['success', "Draft {$po['po_number']} was deleted."]);
                header("Location: purchase_orders.php");
                exit;
            }
        } catch (RuntimeException $e) {
            sx_flash(['error', $e->getMessage()]);
        } catch (PDOException $e) {
            error_log("PO {$action} failed for #{$id}: " . $e->getMessage());
            sx_flash(['error', 'Could not save the change. Please try again.']);
        }
        header("Location: purchase_order.php?id={$id}");
        exit;
    }
}

// =================================================================================
// DISPLAY
// =================================================================================
$isDraft = !$po || $po['status'] === 'draft';
$flash = sx_flash();
$csrf  = sx_csrf_token();
$money = fn($n) => '₱' . number_format((float)$n, 2);

if ($isDraft) {
    // Mga supplier: aktibo lang (kasama ang kasalukuyan ng draft kahit inactive na, para makita ang mali)
    $sup = $pdo->prepare("SELECT id, name, lead_time_days, is_active FROM suppliers WHERE is_active = 1 OR id = ? ORDER BY name");
    $sup->execute([(int)($po['supplier_id'] ?? 0)]);
    $suppliers = $sup->fetchAll(PDO::FETCH_ASSOC);

    $onOrder = sx_po_on_order($pdo);
    $catalog = [];
    foreach ($pdo->query("
        SELECT p.id, p.name, p.sku, p.supplier_id, p.cost_price, p.reorder_point, p.reorder_qty, COALESCE(SUM(i.quantity), 0) AS stock
        FROM products p LEFT JOIN inventory i ON i.product_id = p.id
        GROUP BY p.id ORDER BY p.name
    ") as $p) {
        $oo = (int)($onOrder[$p['id']] ?? 0);
        $catalog[] = [
            'id' => (int)$p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'supplier' => (int)$p['supplier_id'],
            'cost' => $p['cost_price'] === null ? '' : number_format((float)$p['cost_price'], 2, '.', ''),
            'stock' => (int)$p['stock'], 'onOrder' => $oo, 'reorder' => (int)$p['reorder_point'],
            'suggest' => sx_po_suggest_qty((int)$p['stock'], $oo, (int)$p['reorder_point'], $p['reorder_qty'] === null ? null : (int)$p['reorder_qty']),
        ];
    }

    // Mga value ng form: tinype (may error) → laman ng draft → bago
    if ($form) {
        $f = $form;
    } elseif ($po) {
        $f = ['supplier_id' => (int)$po['supplier_id'], 'expected_date' => (string)$po['expected_date'], 'notes' => (string)$po['notes'],
              'lines' => array_map(fn($it) => ['product_id' => $it['product_id'], 'qty' => $it['qty_ordered'], 'unit_cost' => $it['unit_cost']], $po['items'])];
    } else {
        $f = ['supplier_id' => (int)($_GET['supplier'] ?? 0), 'expected_date' => '', 'notes' => '', 'lines' => []];
    }
    $f['lines'] = array_values(array_filter($f['lines'], fn($l) => (int)($l['product_id'] ?? 0) || (int)($l['qty'] ?? 0)));
} else {
    $units = array_sum(array_column($po['items'], 'qty_ordered'));
    $got   = array_sum(array_column($po['items'], 'qty_received'));
    $late  = in_array($po['status'], ['sent', 'partial'], true) && $po['expected_date'] && $po['expected_date'] < date('Y-m-d');
    $canReceive = in_array($po['status'], ['sent', 'partial'], true);
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   PURCHASE ORDER (Phase 7d) — tokens + sx-card / sx-table / co-dialog mula sa assets/style.css
   ============================================================ */
.po { max-width: 1240px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.po-head { display: flex; flex-wrap: wrap; align-items: flex-start; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.po-head h1 { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); margin: 0; }
.po-head p { margin: 4px 0 0; color: var(--sx-muted); }
.po-head .po-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); margin-left: auto; }
.po-layout { display: grid; gap: var(--sx-space-5); align-items: start; }
.po-main, .po-side { display: grid; gap: var(--sx-space-5); min-width: 0; }

/* Draft form: mga linya */
.po-lines { display: grid; gap: var(--sx-space-3); }
.po-line { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sx-space-2) var(--sx-space-3); padding: var(--sx-space-3); border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-md); background: var(--sx-surface); }
.po-line .sx-field { margin: 0; }
.po-line .po-prod { grid-column: 1 / -1; }
.po-line .sx-label { font-size: var(--sx-text-xs); }
.po-line-info { grid-column: 1 / -1; display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--sx-space-2); font-size: var(--sx-text-xs); color: var(--sx-muted); }
.po-line-info .low { color: var(--sx-status-pending); font-weight: var(--sx-weight-semibold); }
.po-line-info strong { color: var(--sx-dark); }
.po-line-remove { justify-self: end; }
.po-line-tools { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); margin-top: var(--sx-space-3); }
.po-total { display: flex; justify-content: space-between; align-items: baseline; margin-top: var(--sx-space-4); padding-top: var(--sx-space-4); border-top: 1px solid var(--sx-border-light); }
.po-total span { color: var(--sx-muted); }
.po-total strong { font-size: 1.5rem; font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }

/* View: bento */
.po-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.po-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 100px; padding: var(--sx-space-4); }
.po-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.po-tile strong { font-size: 1.4rem; font-weight: var(--sx-weight-extrabold); line-height: 1.15; color: var(--sx-dark); }
.po-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.po-tile.bad strong { color: var(--sx-status-cancelled); }
.po-progress { height: 8px; overflow: hidden; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.po-progress i { display: block; height: 100%; border-radius: inherit; background: var(--sx-accent); }
.po-supplier { display: flex; align-items: center; gap: var(--sx-space-3); color: inherit; text-decoration: none; }
.po-supplier .sp-av { display: grid; place-items: center; flex-shrink: 0; width: 44px; height: 44px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); font-weight: var(--sx-weight-extrabold); }
.po-supplier strong { display: block; color: var(--sx-dark); }
.po-supplier small { color: var(--sx-muted); }
.po-table td { vertical-align: middle; }
.po-table .num { text-align: right; white-space: nowrap; }
.po-table .po-recv { width: 110px; }
.po-table .po-recv .sx-input { padding: 8px 10px; text-align: right; }
.po-table .done { color: var(--sx-accent-dark); font-weight: var(--sx-weight-semibold); }
.po-table small { display: block; color: var(--sx-muted); }
.po-timeline { margin: 0; padding: 0; list-style: none; }
.po-timeline li { position: relative; padding: 0 0 var(--sx-space-4) var(--sx-space-5); font-size: var(--sx-text-sm); }
.po-timeline li::before { content: ''; position: absolute; left: 4px; top: 6px; width: 9px; height: 9px; border-radius: 50%; background: var(--sx-border); }
.po-timeline li::after { content: ''; position: absolute; left: 8px; top: 18px; bottom: 0; width: 1px; background: var(--sx-border-light); }
.po-timeline li:last-child::after { display: none; }
.po-timeline li.on::before { background: var(--sx-accent); }
.po-timeline strong { display: block; color: var(--sx-dark); }
.po-timeline small { color: var(--sx-muted); }
.po-note { margin: 0 0 var(--sx-space-3); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.po-notes { margin: 0; padding: var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); font-size: var(--sx-text-sm); white-space: pre-wrap; }
@media (min-width: 640px) {
    .po-line { grid-template-columns: minmax(0, 3fr) 1fr 1fr auto; align-items: end; }
    .po-line .po-prod { grid-column: auto; }
    .po-line-info { grid-column: 1 / -1; }
}
@media (min-width: 768px) { .po-bento { grid-template-columns: 1.4fr repeat(3, 1fr); } }
@media (min-width: 992px) { .po-layout { grid-template-columns: 1fr 340px; } }
@media (max-width: 767.98px) { .po-table .hide-sm { display: none; } }
</style>

<div class="po sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="purchase_orders.php">Purchase orders</a> <span aria-hidden="true">/</span>
        <span><?= $po ? h($po['po_number']) : 'New' ?></span>
    </nav>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('h', $errors)) ?></div>
        </div>
    <?php endif; ?>

<?php if ($isDraft): ?>
    <!-- ===================== DRAFT FORM ===================== -->
    <div class="po-head">
        <div>
            <h1 class="sx-heading-lg"><?= $po ? h($po['po_number']) . ' ' . sx_po_status_pill('draft') : 'New purchase order' ?></h1>
            <p>Order stock from a supplier. Nothing changes in inventory until you receive it.</p>
        </div>
    </div>

    <?php if (!$suppliers): ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-truck-field"></i>
            <h3>Add a supplier first</h3>
            <p>Purchase orders are sent to an active supplier.</p>
            <a href="supplier.php" class="sx-btn sx-btn-primary"><i class="fa-solid fa-plus"></i> Add supplier</a>
        </div>
    <?php else: ?>
    <form method="post" class="po-layout" id="poForm">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <?php if ($po): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

        <section class="sx-card">
            <h2 class="pf-title">Items</h2>
            <div class="po-lines" id="poLines"></div>
            <div class="po-line-tools">
                <button type="button" class="sx-btn sx-btn-outline sx-btn-sm" id="poAdd"><i class="fa-solid fa-plus"></i> Add item</button>
                <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" id="poSuggest"><i class="fa-solid fa-wand-magic-sparkles"></i> Add low-stock items from this supplier</button>
            </div>
            <p class="po-note" id="poSuggestNote" style="margin: var(--sx-space-2) 0 0" aria-live="polite"></p>
            <div class="po-total"><span>Total</span><strong id="poTotal">₱0.00</strong></div>
        </section>

        <aside class="po-side">
            <section class="sx-card">
                <h2 class="pf-title">Order details</h2>
                <div class="sx-field">
                    <label class="sx-label" for="po-supplier">Supplier</label>
                    <select id="po-supplier" name="supplier_id" class="sx-select" required>
                        <option value="">Choose a supplier</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" data-lead="<?= (int)$s['lead_time_days'] ?>" <?= $f['supplier_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                                <?= h($s['name']) ?><?= $s['is_active'] ? '' : ' (inactive)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sx-field">
                    <label class="sx-label" for="po-date">Expected delivery</label>
                    <input type="date" id="po-date" name="expected_date" class="sx-input" value="<?= h($f['expected_date']) ?>">
                    <span class="sx-hint" id="po-date-hint">Leave blank to use the supplier's lead time.</span>
                </div>
                <div class="sx-field" style="margin-bottom: 0">
                    <label class="sx-label" for="po-notes">Notes <span class="sx-hint">(optional)</span></label>
                    <textarea id="po-notes" name="notes" class="sx-textarea" rows="3" maxlength="2000" placeholder="Shown on the printed order"><?= h($f['notes']) ?></textarea>
                </div>
            </section>
            <div class="pf-actions" style="margin-top: 0">
                <button type="submit" name="action" value="save" class="sx-btn sx-btn-outline">Save draft</button>
                <button type="submit" name="action" value="save_send" class="sx-btn sx-btn-primary"><i class="fa-solid fa-paper-plane"></i> Save &amp; mark as sent</button>
            </div>
            <?php if ($po): ?>
                <section class="sx-card">
                    <h2 class="pf-title">Delete draft</h2>
                    <p class="po-note">Drafts were never sent, so nothing else changes.</p>
                    <button type="submit" form="poDelete" class="sx-btn sx-btn-danger sx-btn-block">Delete draft</button>
                </section>
            <?php endif; ?>
        </aside>
    </form>
    <?php if ($po): ?>
        <form method="post" id="poDelete" onsubmit="return confirm('Delete this draft?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="delete">
        </form>
    <?php endif; ?>

    <template id="poLineTpl">
        <div class="po-line">
            <div class="sx-field po-prod">
                <label class="sx-label">Product</label>
                <select name="product_id[]" class="sx-select" required></select>
            </div>
            <div class="sx-field">
                <label class="sx-label">Quantity</label>
                <input type="number" name="qty[]" class="sx-input" min="1" max="100000" step="1" required>
            </div>
            <div class="sx-field">
                <label class="sx-label">Unit cost (₱)</label>
                <input type="number" name="unit_cost[]" class="sx-input" min="0" step="0.01" inputmode="decimal" required>
            </div>
            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm po-line-remove" aria-label="Remove item"><i class="fa-solid fa-xmark"></i></button>
            <div class="po-line-info"><span class="stock"></span><strong class="line-total"></strong></div>
        </div>
    </template>

    <script>
    // Draft form: mga linya, presyo galing sa cost price, total, at mungkahi para sa low stock
    document.addEventListener('DOMContentLoaded', function () {
        const catalog = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const initial = <?= json_encode(array_map(fn($l) => ['product_id' => (int)$l['product_id'], 'qty' => (string)$l['qty'], 'unit_cost' => (string)$l['unit_cost']], $f['lines']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const byId = Object.fromEntries(catalog.map((p) => [p.id, p]));
        const lines = document.getElementById('poLines');
        const tpl = document.getElementById('poLineTpl');
        const supplier = document.getElementById('po-supplier');
        const peso = (n) => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        function options(selected) {
            const sid = parseInt(supplier.value, 10) || 0;
            const mine = catalog.filter((p) => sid && p.supplier === sid), other = catalog.filter((p) => !(sid && p.supplier === sid));
            const opt = (p) => '<option value="' + p.id + '"' + (p.id === selected ? ' selected' : '') + '>' + escapeHtml(p.name) + (p.sku ? ' · ' + escapeHtml(p.sku) : '') + '</option>';
            let html = '<option value="">Choose a product</option>';
            if (mine.length) html += '<optgroup label="From this supplier">' + mine.map(opt).join('') + '</optgroup><optgroup label="Other products">' + other.map(opt).join('') + '</optgroup>';
            else html += other.map(opt).join('');
            return html;
        }
        function escapeHtml(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

        function info(row) {
            const p = byId[row.querySelector('select').value];
            const stock = row.querySelector('.stock');
            if (!p) { stock.textContent = ''; stock.className = 'stock'; }
            else {
                stock.textContent = p.stock + ' in stock' + (p.onOrder ? ' · ' + p.onOrder + ' on order' : '') + ' · reorder at ' + p.reorder;
                stock.className = 'stock' + (p.stock <= p.reorder ? ' low' : '');
            }
            const q = parseFloat(row.querySelector('[name="qty[]"]').value) || 0, c = parseFloat(row.querySelector('[name="unit_cost[]"]').value) || 0;
            row.querySelector('.line-total').textContent = q && c ? peso(q * c) : '';
            total();
        }
        function total() {
            let t = 0;
            lines.querySelectorAll('.po-line').forEach((row) => {
                t += (parseFloat(row.querySelector('[name="qty[]"]').value) || 0) * (parseFloat(row.querySelector('[name="unit_cost[]"]').value) || 0);
            });
            document.getElementById('poTotal').textContent = peso(t);
        }
        function addLine(data = {}) {
            const row = tpl.content.firstElementChild.cloneNode(true);
            const sel = row.querySelector('select');
            sel.innerHTML = options(data.product_id || 0);
            row.querySelector('[name="qty[]"]').value = data.qty || '';
            row.querySelector('[name="unit_cost[]"]').value = data.unit_cost || '';
            sel.addEventListener('change', () => {
                const p = byId[sel.value], cost = row.querySelector('[name="unit_cost[]"]'), qty = row.querySelector('[name="qty[]"]');
                if (p && p.cost !== '' && cost.value === '') cost.value = p.cost;
                if (p && p.suggest && qty.value === '') qty.value = p.suggest;
                info(row);
            });
            row.querySelectorAll('input').forEach((el) => el.addEventListener('input', () => info(row)));
            row.querySelector('.po-line-remove').addEventListener('click', () => {
                row.remove();
                if (!lines.children.length) addLine();
                total();
            });
            lines.appendChild(row);
            info(row);
            return row;
        }

        supplier.addEventListener('change', () => {
            lines.querySelectorAll('select').forEach((sel) => { sel.innerHTML = options(parseInt(sel.value, 10) || 0); });
            const lead = supplier.selectedOptions[0] && supplier.selectedOptions[0].dataset.lead;
            document.getElementById('po-date-hint').textContent = lead ? 'Leave blank for ' + lead + ' days from today (supplier lead time).' : "Leave blank to use the supplier's lead time.";
        });
        document.getElementById('poAdd').addEventListener('click', () => addLine().querySelector('select').focus());
        document.getElementById('poSuggest').addEventListener('click', () => {
            const note = document.getElementById('poSuggestNote');
            const sid = parseInt(supplier.value, 10) || 0;
            if (!sid) { note.textContent = 'Choose a supplier first.'; return; }
            const have = new Set([...lines.querySelectorAll('select')].map((s) => parseInt(s.value, 10)));
            const add = catalog.filter((p) => p.supplier === sid && p.suggest > 0 && !have.has(p.id));
            // Alisin ang blangkong linya bago magdagdag
            lines.querySelectorAll('.po-line').forEach((row) => { if (!row.querySelector('select').value && !row.querySelector('[name="qty[]"]').value) row.remove(); });
            add.forEach((p) => addLine({ product_id: p.id, qty: p.suggest, unit_cost: p.cost }));
            if (!lines.children.length) addLine();
            note.textContent = add.length ? 'Added ' + add.length + ' item' + (add.length === 1 ? '' : 's') + ' at or below the reorder point (minus what is already on order).'
                                          : 'No products from this supplier need reordering right now.';
        });

        (initial.length ? initial : [{}]).forEach(addLine);
        supplier.dispatchEvent(new Event('change'));
    });
    </script>
    <?php endif; ?>

<?php else: ?>
    <!-- ===================== SENT / PARTIAL / RECEIVED / CANCELLED ===================== -->
    <div class="po-head">
        <div>
            <h1 class="sx-heading-lg"><?= h($po['po_number']) ?> <?= sx_po_status_pill($po['status']) ?></h1>
            <p>Created <?= date('M j, Y', strtotime($po['created_at'])) ?><?= $po['created_by_name'] ? ' by ' . h($po['created_by_name']) : '' ?> · delivers to <?= h($po['location_name'] ?: 'Main Warehouse') ?></p>
        </div>
        <div class="po-actions">
            <a href="purchase_order_print.php?id=<?= $id ?>" target="_blank" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-print"></i> Print / PDF</a>
        </div>
    </div>

    <?php if ($po['status_note']): ?>
        <div class="sx-alert sx-alert-<?= $po['status'] === 'cancelled' ? 'error' : 'warning' ?>" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-info"></i><div><?= $po['status'] === 'cancelled' ? 'Cancelled: ' : '' ?><?= h($po['status_note']) ?></div>
        </div>
    <?php endif; ?>

    <div class="po-bento">
        <a href="supplier.php?id=<?= (int)$po['supplier_id'] ?>" class="sx-card po-tile po-supplier">
            <span class="sp-av" aria-hidden="true"><?= h(sx_supplier_initials($po['supplier_name'])) ?></span>
            <div>
                <strong><?= h($po['supplier_name']) ?></strong>
                <small><?= h($po['contact_person'] ?: ($po['supplier_email'] ?: ($po['supplier_phone'] ?: 'Supplier'))) ?><?= $po['payment_terms'] ? ' · ' . h($po['payment_terms']) : '' ?></small>
            </div>
        </a>
        <div class="sx-card po-tile">
            <span>Order total</span>
            <strong><?= $money($po['total']) ?></strong>
            <small><?= count($po['items']) ?> product<?= count($po['items']) === 1 ? '' : 's' ?> · <?= number_format($units) ?> units</small>
        </div>
        <div class="sx-card po-tile">
            <span>Received</span>
            <strong><?= number_format($got) ?> / <?= number_format($units) ?></strong>
            <div class="po-progress" role="img" aria-label="<?= $units ? round($got / $units * 100) : 0 ?>% received"><i style="width: <?= $units ? round($got / $units * 100) : 0 ?>%"></i></div>
        </div>
        <div class="sx-card po-tile<?= $late ? ' bad' : '' ?>">
            <span><?= $po['status'] === 'received' ? 'Received' : 'Expected' ?></span>
            <strong><?= $po['status'] === 'received' && $po['received_at'] ? date('M j', strtotime($po['received_at'])) : ($po['expected_date'] ? date('M j', strtotime($po['expected_date'])) : '—') ?></strong>
            <small>
                <?php if ($late): ?><?= (int)((strtotime(date('Y-m-d')) - strtotime($po['expected_date'])) / 86400) ?> day(s) late
                <?php elseif ($po['status'] === 'received' && $po['expected_date']): ?><?= date('Y-m-d', strtotime($po['received_at'])) <= $po['expected_date'] ? 'On time' : 'Late' ?> · expected <?= date('M j', strtotime($po['expected_date'])) ?>
                <?php else: ?><?= $po['expected_date'] ? date('l', strtotime($po['expected_date'])) : '' ?><?php endif; ?>
            </small>
        </div>
    </div>

    <div class="po-layout">
        <div class="po-main">
            <form method="post" class="sx-card" style="padding: 0; overflow: hidden">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="receive">
                <div style="padding: var(--sx-space-5) var(--sx-space-5) 0">
                    <h2 class="pf-title" style="margin-bottom: var(--sx-space-2)"><?= $canReceive ? 'Receive stock' : 'Items' ?></h2>
                    <?php if ($canReceive): ?><p class="po-note">Enter what arrived. Each unit is added to <?= h($po['location_name'] ?: 'Main Warehouse') ?> and logged as stock in.</p><?php endif; ?>
                </div>
                <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                    <table class="sx-table po-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="num hide-sm">Unit cost</th>
                                <th class="num">Ordered</th>
                                <th class="num">Received</th>
                                <?php if ($canReceive): ?><th class="num">Receiving now</th><?php else: ?><th class="num hide-sm">Line total</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($po['items'] as $it): $left = (int)$it['qty_remaining']; ?>
                                <tr>
                                    <td><a href="product_history.php?product_id=<?= (int)$it['product_id'] ?>"><?= h($it['name']) ?></a><small><?= h($it['sku'] ?: '—') ?></small></td>
                                    <td class="num hide-sm"><?= $money($it['unit_cost']) ?></td>
                                    <td class="num"><?= number_format((int)$it['qty_ordered']) ?></td>
                                    <td class="num<?= $left === 0 ? ' done' : '' ?>"><?= number_format((int)$it['qty_received']) ?><?= $left === 0 ? ' <i class="fa-solid fa-check"></i>' : '' ?></td>
                                    <?php if ($canReceive): ?>
                                        <td class="num po-recv">
                                            <?php if ($left > 0): ?>
                                                <input type="number" name="receive[<?= (int)$it['product_id'] ?>]" class="sx-input" min="0" max="<?= $left ?>" step="1" value="<?= $left ?>" aria-label="Receiving now for <?= h($it['name']) ?>">
                                            <?php else: ?>—<?php endif; ?>
                                        </td>
                                    <?php else: ?>
                                        <td class="num hide-sm"><?= $money($it['qty_ordered'] * $it['unit_cost']) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canReceive): ?>
                    <div class="pf-actions" style="padding: 0 var(--sx-space-5) var(--sx-space-5)">
                        <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid fa-truck-ramp-box"></i> Receive stock</button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($po['notes']): ?>
                <section class="sx-card">
                    <h2 class="pf-title">Notes</h2>
                    <p class="po-notes"><?= h($po['notes']) ?></p>
                </section>
            <?php endif; ?>
        </div>

        <aside class="po-side">
            <section class="sx-card">
                <h2 class="pf-title">Timeline</h2>
                <ul class="po-timeline">
                    <li class="on"><strong>Created</strong><small><?= date('M j, Y g:i A', strtotime($po['created_at'])) ?></small></li>
                    <li class="<?= $po['sent_at'] ? 'on' : '' ?>"><strong>Sent to supplier</strong><small><?= $po['sent_at'] ? date('M j, Y g:i A', strtotime($po['sent_at'])) : '—' ?></small></li>
                    <?php if ($po['status'] === 'cancelled'): ?>
                        <li class="on"><strong>Cancelled</strong><small><?= $po['cancelled_at'] ? date('M j, Y g:i A', strtotime($po['cancelled_at'])) : '' ?></small></li>
                    <?php else: ?>
                        <li class="<?= $got > 0 ? 'on' : '' ?>"><strong>First delivery</strong><small><?= $got > 0 ? number_format($got) . ' of ' . number_format($units) . ' units so far' : 'Waiting' ?></small></li>
                        <li class="<?= $po['status'] === 'received' ? 'on' : '' ?>"><strong>Completed</strong><small><?= $po['received_at'] ? date('M j, Y g:i A', strtotime($po['received_at'])) : '—' ?></small></li>
                    <?php endif; ?>
                </ul>
            </section>

            <?php if ($po['status'] === 'partial'): ?>
                <section class="sx-card">
                    <h2 class="pf-title">Close short</h2>
                    <p class="po-note">The supplier won't send the rest? Close the order so the missing units stop counting as "on order".</p>
                    <button type="button" class="sx-btn sx-btn-outline sx-btn-block" data-open="closeDialog">Close short…</button>
                </section>
            <?php endif; ?>
            <?php if ($po['status'] === 'sent'): ?>
                <section class="sx-card">
                    <h2 class="pf-title">Cancel order</h2>
                    <p class="po-note">Only possible before anything is received.</p>
                    <button type="button" class="sx-btn sx-btn-danger sx-btn-block" data-open="cancelDialog">Cancel order…</button>
                </section>
            <?php endif; ?>
        </aside>
    </div>

    <?php foreach (['closeDialog' => ['close_short', 'Close ' . $po['po_number'] . ' short?', 'Why won\'t the rest arrive?', 'Close order', 'sx-btn-primary'],
                    'cancelDialog' => ['cancel', 'Cancel ' . $po['po_number'] . '?', 'Reason for cancelling', 'Cancel order', 'sx-btn-danger']] as $dlgId => [$act, $title, $label, $btn, $cls]):
        if (($act === 'close_short' && $po['status'] !== 'partial') || ($act === 'cancel' && $po['status'] !== 'sent')) continue; ?>
        <dialog class="co-dialog" id="<?= $dlgId ?>" aria-labelledby="<?= $dlgId ?>T">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="<?= $act ?>">
                <div class="co-dialog-head">
                    <h3 id="<?= $dlgId ?>T"><?= h($title) ?></h3>
                    <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="co-dialog-body">
                    <label class="sx-label" for="<?= $dlgId ?>R"><?= h($label) ?></label>
                    <input type="text" id="<?= $dlgId ?>R" name="reason" class="sx-input" required maxlength="200">
                </div>
                <div class="co-dialog-foot">
                    <button type="button" class="sx-btn sx-btn-ghost" data-close>Back</button>
                    <button type="submit" class="sx-btn <?= $cls ?>"><?= h($btn) ?></button>
                </div>
            </form>
        </dialog>
    <?php endforeach; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => document.getElementById(b.dataset.open).showModal()));
        document.querySelectorAll('dialog.co-dialog').forEach((d) => {
            d.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', () => d.close()));
            d.addEventListener('click', (e) => { if (e.target === d) d.close(); });
        });
    });
    </script>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
