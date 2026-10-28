<?php
// admin/allorders.php — iisang listahan ng orders (dati: allorders hub + order_all, orders_pending,
// orders_completed, orders_archive_all at orders.php — redirect na lang sila rito na may filter).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/order_ops.php';

// Status tabs → mga status sa DB
$tabs = [
    'all'       => ['All', null],
    'pending'   => ['To process', ['pending', 'Pending PO']],
    'preparing' => ['Preparing', ['preparing', 'shipped']],
    'delivered' => ['Delivered', ['delivered']],
    'completed' => ['Completed', ['completed']],
    'requests'  => ['Cancel requests', ['cancellation_requested']],
    'cancelled' => ['Cancelled', ['cancelled']],
];
$types = ['all' => 'All types', 'b2c' => 'Retail', 'b2b' => 'Business / PO'];

$tab    = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';
$type   = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : 'all';
$search = trim($_GET['q'] ?? '');
$year   = ctype_digit($_GET['year'] ?? '') ? (int)$_GET['year'] : 0;
$month  = ctype_digit($_GET['month'] ?? '') && (int)$_GET['month'] >= 1 && (int)$_GET['month'] <= 12 ? (int)$_GET['month'] : 0;
$limit  = 20;
$page   = max(1, (int)($_GET['page'] ?? 1));

// Business = B2B order, PO, o account na business (lumang PO ay naka-save na B2C)
$b2bSql = "(o.order_type = 'B2B' OR o.status = 'Pending PO' OR COALESCE(u.role, '') = 'business')";

// WHERE na hindi kasama ang tab (para sa bilang bawat tab)
$where = [];
$params = [];
if ($type === 'b2b') $where[] = $b2bSql;
if ($type === 'b2c') $where[] = "NOT {$b2bSql}";
if ($search !== '') {
    $where[] = '(o.id = ? OR o.customer_name LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    array_push($params, ctype_digit(ltrim($search, '#')) ? (int)ltrim($search, '#') : 0, "%{$search}%", "%{$search}%", "%{$search}%");
}
if ($year)  { $where[] = 'YEAR(o.created_at) = ?';  $params[] = $year; }
if ($month) { $where[] = 'MONTH(o.created_at) = ?'; $params[] = $month; }
$baseWhere = $where ? implode(' AND ', $where) : '1=1';
$from = "FROM orders o LEFT JOIN users u ON u.id = o.user_id";

// Bilang bawat status (para sa tabs)
$countStmt = $pdo->prepare("SELECT o.status, COUNT(*) {$from} WHERE {$baseWhere} GROUP BY o.status");
$countStmt->execute($params);
$byStatus = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$tabCounts = [];
foreach ($tabs as $k => [, $statuses]) {
    $tabCounts[$k] = $statuses === null ? array_sum($byStatus) : array_sum(array_map(fn($s) => (int)($byStatus[$s] ?? 0), $statuses));
}

// Listahan
$listWhere = $baseWhere;
$listParams = $params;
if ($tabs[$tab][1] !== null) {
    $listWhere .= ' AND o.status IN (' . implode(',', array_fill(0, count($tabs[$tab][1]), '?')) . ')';
    $listParams = array_merge($listParams, $tabs[$tab][1]);
}
$total = $tabCounts[$tab];
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT o.id, o.status, o.total, o.created_at, o.order_type, o.payment_terms, o.customer_name,
           u.name AS account_name, u.email, u.role,
           (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) AS units,
           (SELECT MAX(delivery_date) FROM deliveries WHERE order_id = o.id) AS delivery_date
    {$from}
    WHERE {$listWhere}
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($listParams);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = $pdo->query("SELECT DISTINCT YEAR(created_at) FROM orders ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
$requestCount = (int)($byStatus['cancellation_requested'] ?? 0);

$url = function (array $changes = []) use ($tab, $type, $search, $year, $month, $page) {
    $q = array_filter(array_merge([
        'tab' => $tab === 'all' ? null : $tab, 'type' => $type === 'all' ? null : $type,
        'q' => $search, 'year' => $year ?: null, 'month' => $month ?: null, 'page' => $page > 1 ? $page : null,
    ], $changes), fn($v) => $v !== null && $v !== '');
    return 'allorders.php' . ($q ? '?' . http_build_query($q) : '');
};

// PDF report: generate_pdf.php ay tumatanggap lang ng ilang status
$pdfStatus = ['preparing' => 'preparing', 'delivered' => 'delivered', 'completed' => 'completed', 'requests' => 'cancellation_requested', 'cancelled' => 'cancelled'][$tab] ?? 'all';
$pdfUrl = 'generate_pdf.php?' . http_build_query(array_filter(['status' => $pdfStatus, 'year' => $year ?: null, 'month' => $month ?: null]));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ADMIN ORDERS (Phase 9f) — tokens + sx-table / sx-chip / sx-pill mula sa assets/style.css
   ============================================================ */
.ol { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ol-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ol-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.ol-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.ol-tabs { display: flex; gap: var(--sx-space-2); margin-bottom: var(--sx-space-3); overflow-x: auto; scrollbar-width: none; }
.ol-tabs::-webkit-scrollbar { display: none; }
.ol-tabs .sx-chip { flex-shrink: 0; }
.ol-tabs .sx-chip span { margin-left: 4px; opacity: 0.7; }
.ol-tabs .sx-chip.hot:not(.active) { border-color: var(--sx-status-pending); color: var(--sx-status-pending); }
.ol-filters { display: grid; gap: var(--sx-space-2); margin-bottom: var(--sx-space-4); padding: var(--sx-space-3) var(--sx-space-4); }
.ol-filters form { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); align-items: center; }
.ol-filters .sx-input { flex: 1 1 220px; min-width: 0; }
.ol-filters .sx-select { width: auto; flex: 0 0 auto; }
.ol-table tbody tr { cursor: pointer; }
.ol-table .num { text-align: right; white-space: nowrap; }
.ol-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); white-space: nowrap; }
.ol-table .who strong { display: block; color: var(--sx-dark); }
.ol-table .who small { color: var(--sx-muted); }
.ol-id { font-weight: var(--sx-weight-bold); color: var(--sx-dark); text-decoration: none; }
.ol-id:hover { color: var(--sx-primary); }
.ol-tag { margin-left: 6px; padding: 1px 6px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 10px; font-weight: var(--sx-weight-bold); }
@media (max-width: 767.98px) { .ol-table .hide-sm { display: none; } }
</style>

<div class="ol sx-container">
    <div class="ol-head">
        <div>
            <span class="sx-eyebrow">Sales</span>
            <h1 class="sx-heading-lg">Orders</h1>
            <p><?= number_format(array_sum($byStatus)) ?> order<?= array_sum($byStatus) === 1 ? '' : 's' ?><?= $search || $year || $type !== 'all' ? ' match your filters' : ' in total' ?></p>
        </div>
        <div class="ol-head-actions">
            <a href="deliveries.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-truck-fast"></i> Deliveries</a>
            <a href="orders_cancellation.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-ban"></i> Cancel requests<?= $requestCount ? " ({$requestCount})" : '' ?></a>
            <a href="<?= h($pdfUrl) ?>" target="_blank" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-file-pdf"></i> PDF report</a>
        </div>
    </div>

    <nav class="ol-tabs" aria-label="Filter by status">
        <?php foreach ($tabs as $k => [$label]): ?>
            <a href="<?= h($url(['tab' => $k === 'all' ? null : $k, 'page' => null])) ?>"
               class="sx-chip<?= $tab === $k ? ' active' : '' ?><?= $k === 'requests' && $tabCounts[$k] ? ' hot' : '' ?>"><?= $label ?> <span><?= $tabCounts[$k] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <div class="sx-card ol-filters">
        <form method="get" role="search">
            <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="Order #, customer name or email…" value="<?= h($search) ?>" aria-label="Search orders">
            <select name="type" class="sx-select" aria-label="Order type">
                <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
            <select name="year" class="sx-select" aria-label="Year">
                <option value="">Any year</option>
                <?php foreach ($years as $y): ?><option value="<?= (int)$y ?>" <?= $year === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?>
            </select>
            <select name="month" class="sx-select" aria-label="Month">
                <option value="">Any month</option>
                <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?>
            </select>
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
            <?php if ($search || $year || $month || $type !== 'all'): ?>
                <a href="<?= h($url(['q' => null, 'type' => null, 'year' => null, 'month' => null, 'page' => null])) ?>" class="sx-btn sx-btn-ghost sx-btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($orders): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table ol-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th class="num hide-sm">Units</th>
                            <th class="num">Total</th>
                            <th>Status</th>
                            <th class="hide-sm">Delivery</th>
                            <th class="hide-sm">Placed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $isB2B = $o['order_type'] === 'B2B' || $o['status'] === 'Pending PO' || $o['role'] === 'business';
                            $link = 'order_details.php?id=' . (int)$o['id'];
                        ?>
                            <tr data-href="<?= $link ?>">
                                <td style="white-space: nowrap">
                                    <a href="<?= $link ?>" class="ol-id">#<?= (int)$o['id'] ?></a>
                                    <?php if ($isB2B): ?><span class="ol-tag"><?= $o['payment_terms'] && str_starts_with($o['payment_terms'], 'Net') ? h($o['payment_terms']) : 'B2B' ?></span><?php endif; ?>
                                </td>
                                <td class="who">
                                    <strong><?= h($o['account_name'] ?: ($o['customer_name'] ?: 'Guest')) ?></strong>
                                    <small><?= h($o['email'] ?: 'Guest order') ?></small>
                                </td>
                                <td class="num hide-sm"><?= (int)$o['units'] ?></td>
                                <td class="num"><strong>₱<?= number_format((float)$o['total'], 2) ?></strong></td>
                                <td><?= sx_order_status_pill($o['status']) ?></td>
                                <td class="muted hide-sm"><?= $o['delivery_date'] ? date('M j, Y', strtotime($o['delivery_date'])) : '—' ?></td>
                                <td class="muted hide-sm"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($orders) ?> of <?= number_format($total) ?></span>
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
            <i class="fa-solid fa-receipt"></i>
            <h3>No orders found</h3>
            <p>Try another tab or clear the filters.</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.ol-table tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
    if (!e.target.closest('a')) window.location.href = tr.dataset.href;
}));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
