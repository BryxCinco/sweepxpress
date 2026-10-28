<?php
// admin/deliveries.php — iskedyul ng delivery ng mga aktibong order.
// Binabasa mula sa orders + deliveries; hindi na nagsusulat sa DB habang binubuksan
// (dati: "failsafe sync" na gumagawa ng deliveries row tuwing load). Ang pagpalit ng
// status/petsa ay sa order_details.php (includes/order_ops.php).
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/order_ops.php';

$today = date('Y-m-d');
$week  = date('Y-m-d', strtotime('+7 days'));

// Aktibo = may kailangan pang i-deliver
$activeSql = "o.status IN ('pending', 'Pending PO', 'preparing', 'shipped')";
$views = [
    'overdue'     => ['Overdue',     "{$activeSql} AND d.delivery_date < ?",                 [$today]],
    'today'       => ['Today',       "{$activeSql} AND d.delivery_date = ?",                 [$today]],
    'upcoming'    => ['Next 7 days', "{$activeSql} AND d.delivery_date > ? AND d.delivery_date <= ?", [$today, $week]],
    'later'       => ['Later',       "{$activeSql} AND d.delivery_date > ?",                 [$week]],
    'unscheduled' => ['No date yet', "{$activeSql} AND d.delivery_date IS NULL",             []],
    'delivered'   => ['Delivered',   "o.status IN ('delivered', 'completed')",               []],
];
$view   = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : 'overdue';
$search = trim($_GET['q'] ?? '');
$limit  = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));

// Isang delivery row bawat order (pinakaluma), para walang doble
$from = "
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    LEFT JOIN (SELECT order_id, MAX(delivery_date) AS delivery_date FROM deliveries GROUP BY order_id) d ON d.order_id = o.id
";
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = ' AND (o.id = ? OR o.customer_name LIKE ? OR u.name LIKE ? OR o.address LIKE ?)';
    $searchParams = [ctype_digit(ltrim($search, '#')) ? (int)ltrim($search, '#') : 0, "%{$search}%", "%{$search}%", "%{$search}%"];
}

// Bilang bawat view
$counts = [];
foreach ($views as $k => [, $sql, $p]) {
    $c = $pdo->prepare("SELECT COUNT(*) {$from} WHERE {$sql}{$searchSql}");
    $c->execute(array_merge($p, $searchParams));
    $counts[$k] = (int)$c->fetchColumn();
}
// Kapag walang overdue, buksan ang unang may laman
if (!isset($_GET['view']) && $counts['overdue'] === 0) {
    foreach (['today', 'upcoming', 'unscheduled', 'later', 'delivered'] as $k) {
        if ($counts[$k]) { $view = $k; break; }
    }
}

[$viewLabel, $viewSql, $viewParams] = $views[$view];
$total = $counts[$view];
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;
$order = $view === 'delivered' ? 'd.delivery_date DESC, o.id DESC' : ($view === 'unscheduled' ? 'o.created_at ASC' : 'd.delivery_date ASC, o.id ASC');

$stmt = $pdo->prepare("
    SELECT o.id, o.status, o.total, o.address, o.customer_name, o.phone_number, o.created_at, o.payment_terms, o.order_type,
           u.name AS account_name, u.role, d.delivery_date,
           (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) AS units
    {$from}
    WHERE {$viewSql}{$searchSql}
    ORDER BY {$order}
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute(array_merge($viewParams, $searchParams));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$url = function (array $changes = []) use ($view, $search, $page) {
    $q = array_filter(array_merge(['view' => $view, 'q' => $search, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'deliveries.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   DELIVERIES (Phase 9f) — tokens + sx-table / sx-chip / sx-pill mula sa assets/style.css
   ============================================================ */
.dl { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.dl-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.dl-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.dl-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.dl-kpi { padding: var(--sx-space-4); color: inherit; text-decoration: none; border: 2px solid transparent; }
.dl-kpi span { display: block; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.dl-kpi strong { display: block; margin-top: 4px; font-size: 1.6rem; font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.dl-kpi.active { border-color: var(--sx-primary); }
.dl-kpi.danger strong { color: var(--sx-status-cancelled); }
.dl-kpi.warn strong { color: var(--sx-status-pending); }
.dl-bar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.dl-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.dl-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }
.dl-search { display: flex; gap: var(--sx-space-2); flex: 1 1 280px; max-width: 420px; }
.dl-search .sx-input { flex: 1; min-width: 0; }
.dl-table tbody tr { cursor: pointer; }
.dl-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); }
.dl-table .num { text-align: right; white-space: nowrap; }
.dl-date { font-weight: var(--sx-weight-bold); white-space: nowrap; }
.dl-date.late { color: var(--sx-status-cancelled); }
.dl-date small { display: block; font-weight: var(--sx-weight-medium); color: var(--sx-muted); }
.dl-id { font-weight: var(--sx-weight-bold); color: var(--sx-dark); text-decoration: none; }
.dl-id:hover { color: var(--sx-primary); }
.dl-tag { margin-left: 6px; padding: 1px 6px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 10px; font-weight: var(--sx-weight-bold); }
.dl-who strong { display: block; color: var(--sx-dark); }
.dl-who small { display: block; max-width: 340px; overflow: hidden; color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
@media (min-width: 768px) { .dl-kpis { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 767.98px) { .dl-table .hide-sm { display: none; } }
</style>

<div class="dl sx-container">
    <div class="dl-head">
        <div>
            <span class="sx-eyebrow">Logistics</span>
            <h1 class="sx-heading-lg">Deliveries</h1>
            <p>Open orders by delivery date. Set dates and statuses on each order.</p>
        </div>
        <a href="allorders.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-bag-shopping"></i> All orders</a>
    </div>

    <div class="dl-kpis">
        <a href="<?= h($url(['view' => 'overdue', 'page' => null])) ?>" class="sx-card dl-kpi danger<?= $view === 'overdue' ? ' active' : '' ?>"><span>Overdue</span><strong><?= $counts['overdue'] ?></strong></a>
        <a href="<?= h($url(['view' => 'today', 'page' => null])) ?>" class="sx-card dl-kpi warn<?= $view === 'today' ? ' active' : '' ?>"><span>Due today</span><strong><?= $counts['today'] ?></strong></a>
        <a href="<?= h($url(['view' => 'upcoming', 'page' => null])) ?>" class="sx-card dl-kpi<?= $view === 'upcoming' ? ' active' : '' ?>"><span>Next 7 days</span><strong><?= $counts['upcoming'] ?></strong></a>
        <a href="<?= h($url(['view' => 'unscheduled', 'page' => null])) ?>" class="sx-card dl-kpi<?= $view === 'unscheduled' ? ' active' : '' ?>"><span>No date yet</span><strong><?= $counts['unscheduled'] ?></strong></a>
    </div>

    <div class="dl-bar">
        <nav class="dl-chips" aria-label="Delivery views">
            <?php foreach ($views as $k => [$label]): ?>
                <a href="<?= h($url(['view' => $k, 'page' => null])) ?>" class="sx-chip<?= $view === $k ? ' active' : '' ?>"><?= $label ?> <span><?= $counts[$k] ?></span></a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="dl-search" role="search">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <input type="search" name="q" class="sx-input" placeholder="Order #, customer or address…" value="<?= h($search) ?>" aria-label="Search deliveries">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
    </div>

    <?php if ($rows): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table dl-table">
                    <thead>
                        <tr>
                            <th>Delivery</th>
                            <th>Order</th>
                            <th>Customer &amp; address</th>
                            <th class="num hide-sm">Units</th>
                            <th class="num hide-sm">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $link = 'order_details.php?id=' . (int)$r['id'] . '&from=deliveries';
                            $late = $r['delivery_date'] && $r['delivery_date'] < $today && $view !== 'delivered';
                            $days = $r['delivery_date'] ? (int)round((strtotime($r['delivery_date']) - strtotime($today)) / 86400) : null;
                        ?>
                            <tr data-href="<?= h($link) ?>">
                                <td class="dl-date<?= $late ? ' late' : '' ?>">
                                    <?php if ($r['delivery_date']): ?>
                                        <?= date('M j, Y', strtotime($r['delivery_date'])) ?>
                                        <?php if ($view !== 'delivered'): ?>
                                            <small><?= $days === 0 ? 'Today' : ($days < 0 ? abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's')) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">Not set</span>
                                        <small>Ordered <?= date('M j', strtotime($r['created_at'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap">
                                    <a href="<?= h($link) ?>" class="dl-id">#<?= (int)$r['id'] ?></a>
                                    <?php if ($r['order_type'] === 'B2B' || $r['status'] === 'Pending PO' || $r['role'] === 'business'): ?><span class="dl-tag">PO</span><?php endif; ?>
                                </td>
                                <td class="dl-who">
                                    <strong><?= h($r['customer_name'] ?: ($r['account_name'] ?: 'Guest')) ?></strong>
                                    <small title="<?= h($r['address']) ?>"><?= h($r['address'] ?: 'No address') ?><?= $r['phone_number'] ? ' · ' . h($r['phone_number']) : '' ?></small>
                                </td>
                                <td class="num hide-sm"><?= (int)$r['units'] ?></td>
                                <td class="num hide-sm">₱<?= number_format((float)$r['total'], 2) ?></td>
                                <td><?= sx_order_status_pill($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($rows) ?> of <?= $total ?></span>
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
            <i class="fa-solid fa-truck"></i>
            <h3>Nothing in “<?= h($viewLabel) ?>”</h3>
            <p><?= $search ? 'Try a different search.' : 'Pick another view above.' ?></p>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.dl-table tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
    if (!e.target.closest('a')) window.location.href = tr.dataset.href;
}));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
