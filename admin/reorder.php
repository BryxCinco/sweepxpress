<?php
// admin/reorder.php — mga produktong kailangang i-reorder, naka-grupo ayon sa supplier (Phase 7e).
// Isang click: gumawa ng draft PO para sa napiling items. Ang logic: sx_reorder_items / sx_po_create (purchasing_ops.php).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php';   // sx_flash, sx_csrf_*
require_once __DIR__ . '/../includes/purchasing_ops.php';
require_once __DIR__ . '/../includes/forecast_ops.php';
require_once __DIR__ . '/../includes/supplier_ops.php';     // sx_supplier_initials

// =================================================================================
// ACTION: gumawa ng draft PO mula sa napiling items ng isang supplier
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        if (!sx_csrf_ok()) throw new RuntimeException('Your session expired. Please try again.');
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $lines = [];
        foreach ((array)($_POST['pick'] ?? []) as $pid) {
            $pid = (int)$pid;
            $lines[] = ['product_id' => $pid, 'qty' => $_POST['qty'][$pid] ?? 0, 'unit_cost' => $_POST['cost'][$pid] ?? ''];
        }
        if (!$lines) throw new RuntimeException('Tick at least one product to order.');
        $id = sx_po_create($pdo, $supplierId, $lines, ['notes' => 'Created from reorder suggestions.'],
                           (int)($_SESSION['user']['id'] ?? 0), (string)($_SESSION['user']['name'] ?? 'Admin'));
        sx_flash(['success', sx_po_number($id) . ' was created as a draft. Check it, then mark it as sent.']);
        header("Location: purchase_order.php?id={$id}");
        exit;
    } catch (RuntimeException $e) {
        sx_flash(['error', $e->getMessage()]);
    } catch (PDOException $e) {
        error_log('Reorder PO failed: ' . $e->getMessage());
        sx_flash(['error', 'Could not create the purchase order. Please try again.']);
    }
    header("Location: reorder.php");
    exit;
}

// =================================================================================
// DISPLAY
// =================================================================================
$items   = sx_reorder_items($pdo);
$window   = sx_forecast_window();
$velocity = sx_sales_velocity($pdo, $window);
$need    = array_values(array_filter($items, fn($r) => $r['suggest'] > 0));
$covered = array_values(array_filter($items, fn($r) => $r['suggest'] === 0));

// Grupo ayon sa supplier; walang supplier → hiwalay
$groups = [];
$noSupplier = [];
foreach ($need as $r) {
    if (!$r['supplier_id']) { $noSupplier[] = $r; continue; }
    $groups[$r['supplier_id']] ??= ['id' => (int)$r['supplier_id'], 'name' => $r['supplier_name'], 'active' => (int)$r['supplier_active'],
                                    'lead' => (int)$r['lead_time_days'], 'items' => [], 'cost' => 0.0, 'missingCost' => 0];
    $groups[$r['supplier_id']]['items'][] = $r;
    if ($r['cost_price'] === null) $groups[$r['supplier_id']]['missingCost']++;
    else $groups[$r['supplier_id']]['cost'] += $r['suggest'] * (float)$r['cost_price'];
}
uasort($groups, fn($a, $b) => [$b['active'], count($b['items'])] <=> [$a['active'], count($a['items'])]);

// Mga draft na hindi pa naipapadala (hindi pa kasama sa "on order")
$drafts = [];
foreach ($pdo->query("SELECT supplier_id, id, po_number FROM purchase_orders WHERE status = 'draft' ORDER BY created_at DESC") as $d) {
    $drafts[$d['supplier_id']] ??= $d;
}

$estCost = array_sum(array_column($groups, 'cost'));
$outCount = count(array_filter($need, fn($r) => (int)$r['stock'] === 0));
$flash = sx_flash();
$csrf  = sx_csrf_token();
$placeholder = '/sweepxpress/assets/placeholder.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   REORDER (Phase 7e) — bento + supplier cards; tokens mula sa assets/style.css
   ============================================================ */
.ro { max-width: 1240px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ro-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-6); }
.ro-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 104px; padding: var(--sx-space-4); }
.ro-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.ro-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.ro-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ro-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.ro-tile.hero span, .ro-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.ro-tile.hero strong { color: #fff; font-size: 2.3rem; }
.ro-tile.bad strong { color: var(--sx-status-cancelled); }
.ro-tile.warn strong { color: var(--sx-status-pending); }

.ro-groups { display: grid; gap: var(--sx-space-5); }
.ro-group { padding: 0; overflow: hidden; }
.ro-group-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-3); padding: var(--sx-space-4) var(--sx-space-5); border-bottom: 1px solid var(--sx-border-light); }
.ro-group-head .av { display: grid; place-items: center; flex-shrink: 0; width: 44px; height: 44px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); font-weight: var(--sx-weight-extrabold); }
.ro-group-head h2 { margin: 0; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); }
.ro-group-head h2 a { color: var(--sx-dark); text-decoration: none; }
.ro-group-head h2 a:hover { color: var(--sx-primary); }
.ro-group-head small { display: block; color: var(--sx-muted); }
.ro-group-head .ro-sum { margin-left: auto; text-align: right; }
.ro-group-head .ro-sum strong { display: block; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ro-note { display: flex; gap: var(--sx-space-2); align-items: center; margin: 0; padding: var(--sx-space-3) var(--sx-space-5); background: var(--sx-status-pending-bg); color: var(--sx-status-pending); font-size: var(--sx-text-sm); }
.ro-note a { color: inherit; font-weight: var(--sx-weight-bold); }

.ro-items { display: grid; }
.ro-item { display: grid; grid-template-columns: auto 48px minmax(0, 1fr); gap: var(--sx-space-2) var(--sx-space-3); align-items: center; padding: var(--sx-space-3) var(--sx-space-5); border-bottom: 1px solid var(--sx-border-light); }
.ro-item:last-child { border-bottom: none; }
.ro-item input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--sx-primary); }
.ro-item img { width: 48px; height: 48px; border-radius: var(--sx-radius-sm); background: var(--sx-surface-alt); object-fit: cover; }
.ro-item .info { min-width: 0; }
.ro-item .info a { display: block; overflow: hidden; font-weight: var(--sx-weight-bold); color: var(--sx-dark); text-decoration: none; text-overflow: ellipsis; white-space: nowrap; }
.ro-item .info a:hover { color: var(--sx-primary); }
.ro-bar { position: relative; height: 6px; margin: 6px 0 4px; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.ro-bar i { position: absolute; inset: 0 auto 0 0; border-radius: inherit; background: var(--sx-status-pending); }
.ro-bar i.out { background: var(--sx-status-cancelled); }
.ro-days.low, .ro-days.out { color: var(--sx-status-cancelled); font-weight: var(--sx-weight-semibold); }
.ro-bar b { position: absolute; inset: 0 auto 0 0; border-radius: inherit; background: color-mix(in srgb, var(--sx-primary) 45%, transparent); }
.ro-item .info small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ro-item .info small .out { color: var(--sx-status-cancelled); font-weight: var(--sx-weight-bold); }
.ro-fields { grid-column: 2 / -1; display: flex; flex-wrap: wrap; gap: var(--sx-space-2); align-items: center; }
.ro-fields label { display: flex; align-items: center; gap: 6px; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ro-fields .sx-input { width: 96px; padding: 8px 10px; text-align: right; }
.ro-fields .line { margin-left: auto; font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.ro-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-3); padding: var(--sx-space-4) var(--sx-space-5); background: var(--sx-surface-alt); }
.ro-foot span { font-size: var(--sx-text-sm); color: var(--sx-muted); }
.ro-foot strong { color: var(--sx-dark); }
.ro-section { margin: var(--sx-space-7) 0 var(--sx-space-3); font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ro-section small { display: block; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-regular); color: var(--sx-muted); }
.ro-mini { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: var(--sx-space-3); }
.ro-mini a { display: flex; gap: var(--sx-space-3); align-items: center; padding: var(--sx-space-3); color: inherit; text-decoration: none; }
.ro-mini img { width: 40px; height: 40px; flex-shrink: 0; border-radius: var(--sx-radius-sm); background: var(--sx-surface-alt); object-fit: cover; }
.ro-mini div { min-width: 0; }
.ro-mini strong { display: block; overflow: hidden; font-size: var(--sx-text-sm); color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.ro-mini small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
@media (min-width: 768px) {
    .ro-bento { grid-template-columns: 1.6fr repeat(4, 1fr); }
    .ro-tile.hero { grid-column: span 1; }
    .ro-item { grid-template-columns: auto 48px minmax(0, 1fr) auto; }
    .ro-fields { grid-column: auto; flex-wrap: nowrap; }
    .ro-fields .line { min-width: 96px; text-align: right; }
}
</style>

<div class="ro sx-container">
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Reorder</h1>
            <p>Products at or below their reorder point, minus what is already on order. Tick what to buy and create a draft PO per supplier.</p>
        </div>
        <div style="display: flex; align-items: center; gap: var(--sx-space-3); flex-wrap: wrap">
            <?= sx_forecast_picker($window) ?>
            <a href="purchase_orders.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-file-invoice"></i> Purchase orders</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="ro-bento">
        <div class="sx-card ro-tile hero">
            <span>Estimated reorder cost</span>
            <strong>₱<?= number_format($estCost, 2) ?></strong>
            <small>Suggested quantities × cost price</small>
        </div>
        <div class="sx-card ro-tile<?= $need ? ' warn' : '' ?>"><span>Need reordering</span><strong><?= count($need) ?></strong><small>Products</small></div>
        <div class="sx-card ro-tile<?= $outCount ? ' bad' : '' ?>"><span>Already out</span><strong><?= $outCount ?></strong><small>Customers can't order these</small></div>
        <div class="sx-card ro-tile"><span>Suppliers to contact</span><strong><?= count($groups) ?></strong><small><?= count($covered) ?> more covered by open POs</small></div>
        <a href="products.php?supplier=none" class="sx-card ro-tile<?= $noSupplier ? ' warn' : '' ?>" style="color: inherit; text-decoration: none"><span>No supplier</span><strong><?= count($noSupplier) ?></strong><small>Set one to reorder</small></a>
    </div>

    <?php if (!$need && !$covered): ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-circle-check"></i>
            <h3>Everything is well stocked</h3>
            <p>No product is at or below its reorder point.</p>
        </div>
    <?php endif; ?>

    <div class="ro-groups">
        <?php foreach ($groups as $g): $draft = $drafts[$g['id']] ?? null; ?>
            <form method="post" class="sx-card ro-group" data-ro-group>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="supplier_id" value="<?= $g['id'] ?>">
                <div class="ro-group-head">
                    <span class="av" aria-hidden="true"><?= h(sx_supplier_initials($g['name'])) ?></span>
                    <div>
                        <h2><a href="supplier.php?id=<?= $g['id'] ?>"><?= h($g['name']) ?></a></h2>
                        <small><?= count($g['items']) ?> product<?= count($g['items']) === 1 ? '' : 's' ?> · <?= $g['lead'] ?>-day lead time · arrives about <?= date('M j', strtotime("+{$g['lead']} days")) ?></small>
                    </div>
                    <div class="ro-sum"><small>Estimated</small><strong data-ro-total>₱<?= number_format($g['cost'], 2) ?></strong></div>
                </div>
                <?php if (!$g['active']): ?>
                    <p class="ro-note"><i class="fa-solid fa-pause"></i> This supplier is inactive. <a href="supplier.php?id=<?= $g['id'] ?>">Reactivate it</a> or move these products to another supplier.</p>
                <?php elseif ($draft): ?>
                    <p class="ro-note"><i class="fa-solid fa-file-pen"></i> You already have an unsent draft for this supplier: <a href="purchase_order.php?id=<?= (int)$draft['id'] ?>"><?= h($draft['po_number']) ?></a>. Drafts don't count as on order.</p>
                <?php endif; ?>
                <div class="ro-items">
                    <?php foreach ($g['items'] as $r):
                        $stock = (int)$r['stock']; $rp = max(1, (int)$r['reorder_point']); $oo = $r['on_order'];
                        [$dLabel, $dTone] = sx_days_label(sx_days_of_stock($stock, $velocity[(int)$r['id']]['per_day'] ?? 0.0), $stock, (int)$r['lead_time_days']);
                        $max = $rp * 2; // buong bar = 2× ng reorder point ?>
                        <div class="ro-item">
                            <input type="checkbox" name="pick[]" value="<?= (int)$r['id'] ?>" checked aria-label="Order <?= h($r['name']) ?>" <?= $g['active'] ? '' : 'disabled' ?>>
                            <img src="<?= h($r['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                            <div class="info">
                                <a href="edit_product.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
                                <div class="ro-bar" role="img" aria-label="<?= $stock ?> in stock, <?= $oo ?> on order, reorder at <?= (int)$r['reorder_point'] ?>">
                                    <b style="width: <?= min(100, round(($stock + $oo) / $max * 100)) ?>%"></b>
                                    <i class="<?= $stock === 0 ? 'out' : '' ?>" style="width: <?= min(100, round($stock / $max * 100)) ?>%"></i>
                                </div>
                                <small><span class="<?= $stock === 0 ? 'out' : '' ?>"><?= $stock === 0 ? 'Out of stock' : number_format($stock) . ' in stock' ?></span><?= $oo ? ' · ' . number_format($oo) . ' on order' : '' ?> · reorder at <?= (int)$r['reorder_point'] ?> · <span class="ro-days <?= $dTone ?>"><?= h($dLabel) ?></span></small>
                            </div>
                            <div class="ro-fields">
                                <label>Qty <input type="number" name="qty[<?= (int)$r['id'] ?>]" class="sx-input" min="1" max="100000" step="1" value="<?= (int)$r['suggest'] ?>" <?= $g['active'] ? '' : 'disabled' ?>></label>
                                <label>₱ <input type="number" name="cost[<?= (int)$r['id'] ?>]" class="sx-input" min="0" step="0.01" value="<?= $r['cost_price'] === null ? '' : h(number_format((float)$r['cost_price'], 2, '.', '')) ?>" placeholder="Cost" <?= $g['active'] ? '' : 'disabled' ?>></label>
                                <span class="line" data-ro-line></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="ro-foot">
                    <span><?= $g['missingCost'] ? '<strong>' . $g['missingCost'] . '</strong> product' . ($g['missingCost'] === 1 ? ' has' : 's have') . ' no cost price yet — enter one to include it.' : 'Quantities and costs can still be changed on the draft.' ?></span>
                    <button type="submit" class="sx-btn sx-btn-primary" <?= $g['active'] ? '' : 'disabled' ?>><i class="fa-solid fa-file-circle-plus"></i> Create draft PO</button>
                </div>
            </form>
        <?php endforeach; ?>
    </div>

    <?php if ($noSupplier): ?>
        <h2 class="ro-section">No supplier set<small>These need reordering but have no supplier. Set one on the product page, then they appear above.</small></h2>
        <div class="ro-mini">
            <?php foreach ($noSupplier as $r): ?>
                <a href="edit_product.php?id=<?= (int)$r['id'] ?>" class="sx-card">
                    <img src="<?= h($r['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                    <div><strong><?= h($r['name']) ?></strong><small><?= (int)$r['stock'] ?> in stock · reorder at <?= (int)$r['reorder_point'] ?> · suggest <?= (int)$r['suggest'] ?></small></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($covered): ?>
        <h2 class="ro-section">Covered by open orders<small>Low now, but enough is already on order.</small></h2>
        <div class="ro-mini">
            <?php foreach ($covered as $r): ?>
                <a href="purchase_orders.php<?= $r['supplier_id'] ? '?supplier=' . (int)$r['supplier_id'] : '' ?>" class="sx-card">
                    <img src="<?= h($r['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                    <div><strong><?= h($r['name']) ?></strong><small><?= (int)$r['stock'] ?> in stock · <?= (int)$r['on_order'] ?> on order · reorder at <?= (int)$r['reorder_point'] ?></small></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Total bawat supplier habang binabago ang dami, presyo o tsek
document.querySelectorAll('[data-ro-group]').forEach(function (form) {
    const peso = (n) => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function update() {
        let total = 0;
        form.querySelectorAll('.ro-item').forEach(function (row) {
            const on = row.querySelector('[name="pick[]"]').checked;
            const q = parseFloat(row.querySelector('[name^="qty["]').value) || 0, c = parseFloat(row.querySelector('[name^="cost["]').value);
            const line = isNaN(c) ? null : q * c;
            row.querySelector('[data-ro-line]').textContent = line === null ? '—' : peso(line);
            row.style.opacity = on ? '' : '0.5';
            if (on && line !== null) total += line;
        });
        form.querySelector('[data-ro-total]').textContent = peso(total);
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
