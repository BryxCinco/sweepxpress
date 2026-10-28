<?php
// admin/purchase_orders.php — lahat ng restock PO bilang cards (Phase 7d). Detail: purchase_order.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php';   // sx_flash
require_once __DIR__ . '/../includes/purchasing_ops.php';
require_once __DIR__ . '/../includes/supplier_ops.php';     // sx_supplier_initials

$tabs = ['open' => 'Open', 'draft' => 'Drafts', 'sent' => 'Sent', 'partial' => 'Partly received', 'received' => 'Received', 'cancelled' => 'Cancelled', 'all' => 'All'];
$tab      = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'open';
$supplier = (int)($_GET['supplier'] ?? 0);
$search   = trim($_GET['q'] ?? '');
$limit    = 24;
$page     = max(1, (int)($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($supplier) { $where[] = 'po.supplier_id = ?'; $params[] = $supplier; }
if ($search !== '') {
    $where[] = '(po.po_number LIKE ? OR s.name LIKE ? OR po.notes LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
$baseWhere = $where ? implode(' AND ', $where) : '1=1';

// Bilang bawat tab (sumusunod sa supplier/search)
$c = $pdo->prepare("SELECT po.status, COUNT(*) FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE {$baseWhere} GROUP BY po.status");
$c->execute($params);
$counts = $c->fetchAll(PDO::FETCH_KEY_PAIR) + array_fill_keys(['draft', 'sent', 'partial', 'received', 'cancelled'], 0);
$counts['open'] = $counts['draft'] + $counts['sent'] + $counts['partial'];
$counts['all']  = array_sum(array_intersect_key($counts, array_flip(['draft', 'sent', 'partial', 'received', 'cancelled'])));

$statusWhere = match ($tab) {
    'open'  => " AND po.status IN ('draft','sent','partial')",
    'all'   => '',
    default => ' AND po.status = ' . $pdo->quote($tab),
};
$total = (int)$counts[$tab];
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT po.id, po.po_number, po.status, po.expected_date, po.total, po.created_at, po.sent_at, po.received_at,
           s.id AS supplier_id, s.name AS supplier_name,
           COUNT(poi.id) AS line_count, COALESCE(SUM(poi.qty_ordered), 0) AS units, COALESCE(SUM(poi.qty_received), 0) AS received
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN purchase_order_items poi ON poi.po_id = po.id
    WHERE {$baseWhere}{$statusWhere}
    GROUP BY po.id
    ORDER BY FIELD(po.status, 'partial', 'sent', 'draft', 'received', 'cancelled'),
             CASE WHEN po.status IN ('sent','partial') THEN po.expected_date END ASC,
             po.created_at DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bento (lahat, hindi apektado ng filter)
$sum = $pdo->query("
    SELECT
        (SELECT COALESCE(SUM((poi.qty_ordered - poi.qty_received) * poi.unit_cost), 0)
           FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.po_id
          WHERE po.status IN ('sent','partial')) AS incoming_value,
        (SELECT COALESCE(SUM(poi.qty_ordered - poi.qty_received), 0)
           FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.po_id
          WHERE po.status IN ('sent','partial')) AS incoming_units,
        (SELECT COUNT(*) FROM purchase_orders WHERE status = 'draft') AS drafts,
        (SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','partial')) AS awaiting,
        (SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','partial') AND expected_date < CURDATE()) AS late,
        (SELECT COALESCE(SUM(sm.quantity), 0) FROM stock_movements sm
          WHERE sm.ref_type = 'po' AND sm.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS received_month
")->fetch(PDO::FETCH_ASSOC);

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE id IN (SELECT DISTINCT supplier_id FROM purchase_orders) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$flash = sx_flash();
$today = date('Y-m-d');
$url = function (array $changes = []) use ($tab, $supplier, $search, $page) {
    $q = array_filter(array_merge(['tab' => $tab === 'open' ? null : $tab, 'supplier' => $supplier ?: null, 'q' => $search, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'purchase_orders.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   PURCHASE ORDERS (Phase 7d) — bento + cards; tokens mula sa assets/style.css
   ============================================================ */
.pl { max-width: 1300px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.pl-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.pl-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 108px; padding: var(--sx-space-4); color: inherit; text-decoration: none; }
.pl-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.pl-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.pl-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.pl-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.pl-tile.hero span, .pl-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.pl-tile.hero strong { color: #fff; font-size: 2.2rem; }
.pl-tile.bad strong { color: var(--sx-status-cancelled); }
a.pl-tile { transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
a.pl-tile:hover { transform: translateY(-2px); box-shadow: var(--sx-shadow-md); }

.pl-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.pl-tools form { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.pl-tools .sx-input { flex: 1 1 220px; min-width: 0; }
.pl-tools .sx-select { flex: 0 1 220px; }
.pl-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.pl-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }

.pl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--sx-space-4); }
.pl-card { display: grid; gap: var(--sx-space-4); padding: var(--sx-space-5); color: inherit; text-decoration: none; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.pl-card:hover { transform: translateY(-3px); box-shadow: var(--sx-shadow-lg); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.pl-card.late { border-color: color-mix(in srgb, var(--sx-status-cancelled) 45%, transparent); }
.pl-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--sx-space-2); }
.pl-card-top strong { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: var(--sx-text-sm); color: var(--sx-dark); }
.pl-sup { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.pl-sup b { display: grid; place-items: center; flex-shrink: 0; width: 40px; height: 40px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: var(--sx-text-sm); }
.pl-sup div { min-width: 0; }
.pl-sup span { display: block; overflow: hidden; font-weight: var(--sx-weight-bold); color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.pl-sup small { color: var(--sx-muted); }
.pl-progress { display: grid; gap: 6px; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.pl-progress div { height: 6px; overflow: hidden; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.pl-progress i { display: block; height: 100%; border-radius: inherit; background: var(--sx-accent); }
.pl-foot { display: flex; align-items: baseline; justify-content: space-between; gap: var(--sx-space-2); padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); font-size: var(--sx-text-xs); color: var(--sx-muted); }
.pl-foot strong { font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.pl-foot .late { color: var(--sx-status-cancelled); font-weight: var(--sx-weight-bold); }
@media (min-width: 768px) {
    .pl-bento { grid-template-columns: 2fr repeat(2, 1fr); }
    .pl-tile.hero { grid-column: span 1; grid-row: span 2; }
}
@media (min-width: 1100px) { .pl-bento { grid-template-columns: 2fr repeat(4, 1fr); } .pl-tile.hero { grid-row: span 1; } }
</style>

<div class="pl sx-container">
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Purchase orders</h1>
            <p>Restock orders to suppliers. Stock goes up only when you receive them.</p>
        </div>
        <a href="purchase_order.php" class="sx-btn sx-btn-primary"><i class="fa-solid fa-plus"></i> New purchase order</a>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="pl-bento">
        <div class="sx-card pl-tile hero">
            <span>Incoming stock value</span>
            <strong>₱<?= number_format((float)$sum['incoming_value'], 2) ?></strong>
            <small><?= number_format((int)$sum['incoming_units']) ?> units still to arrive</small>
        </div>
        <a href="<?= h($url(['tab' => 'sent', 'page' => null])) ?>" class="sx-card pl-tile">
            <span>Awaiting delivery</span><strong><?= (int)$sum['awaiting'] ?></strong><small>Sent or partly received</small>
        </a>
        <div class="sx-card pl-tile<?= $sum['late'] ? ' bad' : '' ?>">
            <span>Late</span><strong><?= (int)$sum['late'] ?></strong><small>Past expected date</small>
        </div>
        <a href="<?= h($url(['tab' => 'draft', 'page' => null])) ?>" class="sx-card pl-tile">
            <span>Drafts</span><strong><?= (int)$sum['drafts'] ?></strong><small>Not sent yet</small>
        </a>
        <div class="sx-card pl-tile">
            <span>Received this month</span><strong><?= number_format((int)$sum['received_month']) ?></strong><small>Units into stock</small>
        </div>
    </div>

    <div class="pl-tools">
        <form method="get" role="search">
            <?php if ($tab !== 'open'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="PO number, supplier or notes…" value="<?= h($search) ?>" aria-label="Search purchase orders">
            <?php if ($suppliers): ?>
                <select name="supplier" class="sx-select" aria-label="Supplier" onchange="this.form.submit()">
                    <option value="">All suppliers</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $supplier === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
        <nav class="pl-chips" aria-label="Filter by status">
            <?php foreach ($tabs as $k => $label): ?>
                <a href="<?= h($url(['tab' => $k === 'open' ? null : $k, 'page' => null])) ?>" class="sx-chip<?= $tab === $k ? ' active' : '' ?>"><?= $label ?> <span><?= (int)$counts[$k] ?></span></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if ($orders): ?>
        <div class="pl-grid">
            <?php foreach ($orders as $o):
                $isOpen = in_array($o['status'], ['sent', 'partial'], true);
                $late = $isOpen && $o['expected_date'] && $o['expected_date'] < $today;
                $pct = $o['units'] ? (int)round($o['received'] / $o['units'] * 100) : 0; ?>
                <a href="purchase_order.php?id=<?= (int)$o['id'] ?>" class="sx-card pl-card<?= $late ? ' late' : '' ?>">
                    <div class="pl-card-top">
                        <strong><?= h($o['po_number']) ?></strong>
                        <?= sx_po_status_pill($o['status']) ?>
                    </div>
                    <div class="pl-sup">
                        <b aria-hidden="true"><?= h(sx_supplier_initials($o['supplier_name'])) ?></b>
                        <div><span><?= h($o['supplier_name']) ?></span><small><?= (int)$o['line_count'] ?> product<?= (int)$o['line_count'] === 1 ? '' : 's' ?> · <?= number_format((int)$o['units']) ?> units</small></div>
                    </div>
                    <?php if ($o['status'] !== 'draft' && $o['status'] !== 'cancelled'): ?>
                        <div class="pl-progress">
                            <div role="img" aria-label="<?= $pct ?>% received"><i style="width: <?= $pct ?>%"></i></div>
                            <span><?= number_format((int)$o['received']) ?> of <?= number_format((int)$o['units']) ?> received</span>
                        </div>
                    <?php endif; ?>
                    <div class="pl-foot">
                        <span class="<?= $late ? 'late' : '' ?>">
                            <?php if ($o['status'] === 'received'): ?>Received <?= $o['received_at'] ? date('M j', strtotime($o['received_at'])) : '' ?>
                            <?php elseif ($o['status'] === 'cancelled'): ?>Cancelled
                            <?php elseif ($late): ?><i class="fa-solid fa-triangle-exclamation"></i> Late · due <?= date('M j', strtotime($o['expected_date'])) ?>
                            <?php else: ?><?= $o['expected_date'] ? 'Expected ' . date('M j', strtotime($o['expected_date'])) : 'Created ' . date('M j', strtotime($o['created_at'])) ?><?php endif; ?>
                        </span>
                        <strong>₱<?= number_format((float)$o['total'], 2) ?></strong>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($orders) ?> of <?= $total ?></span>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Pages">
                    <?php if ($page > 1): ?><a href="<?= h($url(['page' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span>
                        <?php else: ?><a href="<?= h($url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="<?= h($url(['page' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-file-invoice"></i>
            <?php if ($counts['all'] || $search !== '' || $supplier): ?>
                <h3>No purchase orders here</h3>
                <p>Try another tab or filter.</p>
            <?php else: ?>
                <h3>No purchase orders yet</h3>
                <p>Create one to restock from a supplier.</p>
                <a href="purchase_order.php" class="sx-btn sx-btn-primary"><i class="fa-solid fa-plus"></i> New purchase order</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
