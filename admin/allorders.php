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
$limit  = 24;

// Cards (default) o List; tinatandaan sa cookie ng browser na ito
$view = in_array($_GET['view'] ?? '', ['cards', 'list'], true) ? $_GET['view'] : ($_COOKIE['sx_orders_view'] ?? 'cards');
if ($view !== 'list') $view = 'cards';
if (isset($_GET['view'])) setcookie('sx_orders_view', $view, ['expires' => time() + 31536000, 'path' => '/sweepxpress/admin/', 'samesite' => 'Lax']);
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
           (SELECT MAX(delivery_date) FROM deliveries WHERE order_id = o.id) AS delivery_date,
           (SELECT GROUP_CONCAT(COALESCE(p.image_path, '') ORDER BY oi.id SEPARATOR '|')
              FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id) AS thumbs
    {$from}
    WHERE {$listWhere}
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($listParams);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$years = $pdo->query("SELECT DISTINCT YEAR(created_at) FROM orders ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
$requestCount = (int)($byStatus['cancellation_requested'] ?? 0);

// Bento (lahat ng order, hindi apektado ng filter)
$sum = $pdo->query("
    SELECT SUM(status IN ('pending','Pending PO')) AS to_process,
           SUM(status IN ('preparing','shipped')) AS preparing,
           SUM(DATE(created_at) = CURDATE()) AS today,
           COALESCE(SUM(CASE WHEN status IN ('delivered','completed') AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN total END), 0) AS month_revenue
    FROM orders
")->fetch(PDO::FETCH_ASSOC);
$placeholder = '/sweepxpress/assets/placeholder.jpg';
$ago = function (string $ts): string {
    $sec = time() - strtotime($ts);
    if ($sec < 3600) return max(1, (int)floor($sec / 60)) . 'm ago';
    if ($sec < 86400) return (int)floor($sec / 3600) . 'h ago';
    if ($sec < 7 * 86400) return (int)floor($sec / 86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
};

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
   ADMIN ORDERS (Phase 9i) — bento + isang card bawat order (may List toggle); tokens mula sa assets/style.css
   ============================================================ */
.ol { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ol-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ol-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.ol-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

/* Bento */
.ol-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ol-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 100px; padding: var(--sx-space-4); color: inherit; text-decoration: none; }
.ol-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.ol-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.ol-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ol-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.ol-tile.hero span, .ol-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.ol-tile.hero strong { color: #fff; font-size: 2.2rem; }
.ol-tile.warn strong { color: var(--sx-status-pending); }
.ol-tile.bad strong { color: var(--sx-status-cancelled); }
a.ol-tile { transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
a.ol-tile:hover { transform: translateY(-2px); box-shadow: var(--sx-shadow-md); }

/* Tabs + filters */
.ol-tabs { display: flex; gap: var(--sx-space-2); margin-bottom: var(--sx-space-3); overflow-x: auto; scrollbar-width: none; }
.ol-tabs::-webkit-scrollbar { display: none; }
.ol-tabs .sx-chip { flex-shrink: 0; }
.ol-tabs .sx-chip span { margin-left: 4px; opacity: 0.7; }
.ol-tabs .sx-chip.hot:not(.active) { border-color: var(--sx-status-pending); color: var(--sx-status-pending); }
.ol-filters { display: grid; gap: var(--sx-space-2); margin-bottom: var(--sx-space-4); padding: var(--sx-space-3) var(--sx-space-4); }
.ol-filters form { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); align-items: center; }
.ol-filters .sx-input { flex: 1 1 220px; min-width: 0; }
.ol-filters .sx-select { width: auto; flex: 0 0 auto; }
.ol-view { display: inline-flex; margin-left: auto; padding: 3px; border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-pill); background: var(--sx-surface); }
.ol-view a { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: var(--sx-radius-pill); color: var(--sx-muted); font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); text-decoration: none; }
.ol-view a.on { background: var(--sx-dark); color: var(--sx-on-dark); }

/* Cards */
.ol-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--sx-space-4); }
.ol-card { display: flex; flex-direction: column; gap: var(--sx-space-4); padding: var(--sx-space-4); cursor: pointer; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.ol-card:hover { transform: translateY(-4px); box-shadow: var(--sx-shadow-lg); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.ol-card.hot { border-color: color-mix(in srgb, var(--sx-status-pending) 40%, transparent); }
.ol-card-top { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-2); }
.ol-id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: var(--sx-weight-bold); color: var(--sx-dark); text-decoration: none; }
.ol-id:hover { color: var(--sx-primary); }
.ol-tag { margin-left: 6px; padding: 1px 6px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 10px; font-weight: var(--sx-weight-bold); }
.ol-who { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.ol-av { display: grid; place-items: center; flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; background: var(--sx-primary-soft); color: var(--sx-primary); font-size: var(--sx-text-sm); font-weight: var(--sx-weight-extrabold); }
.ol-av.b2b { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.ol-who div { min-width: 0; }
.ol-who strong { display: block; overflow: hidden; color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.ol-who small { display: block; overflow: hidden; font-size: var(--sx-text-xs); color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
.ol-thumbs { display: flex; align-items: center; }
.ol-thumbs img, .ol-thumbs span { width: 40px; height: 40px; margin-right: -10px; border: 2px solid var(--sx-surface); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.ol-thumbs span { display: grid; place-items: center; color: var(--sx-muted); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); }
.ol-thumbs em { margin-left: var(--sx-space-4); font-size: var(--sx-text-xs); font-style: normal; color: var(--sx-muted); }
.ol-card-foot { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-2); margin-top: auto; padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); }
.ol-card-foot b { font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ol-card-foot small { display: block; font-size: var(--sx-text-xs); color: var(--sx-muted); text-align: right; }
.ol-card-foot small i { margin-right: 4px; color: var(--sx-primary); }

/* List */
.ol-table tbody tr { cursor: pointer; }
.ol-table .num { text-align: right; white-space: nowrap; }
.ol-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); white-space: nowrap; }
.ol-table .who strong { display: block; color: var(--sx-dark); }
.ol-table .who small { color: var(--sx-muted); }

@media (min-width: 768px) { .ol-bento { grid-template-columns: 1.6fr repeat(3, 1fr); } .ol-tile.hero { grid-column: span 1; } }
@media (max-width: 767.98px) { .ol-table .hide-sm { display: none; } .ol-view { margin-left: 0; } }
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

    <div class="ol-bento">
        <div class="sx-card ol-tile hero">
            <span>Revenue this month</span>
            <strong>₱<?= number_format((float)$sum['month_revenue'], 0) ?></strong>
            <small>Delivered &amp; completed since <?= date('M 1') ?></small>
        </div>
        <a href="<?= h($url(['tab' => 'pending', 'page' => null])) ?>" class="sx-card ol-tile<?= $sum['to_process'] ? ' warn' : '' ?>">
            <span>To process</span><strong><?= (int)$sum['to_process'] ?></strong><small>Pending and PO review</small>
        </a>
        <a href="<?= h($url(['tab' => 'preparing', 'page' => null])) ?>" class="sx-card ol-tile">
            <span>Preparing</span><strong><?= (int)$sum['preparing'] ?></strong><small>Packed or on the way</small>
        </a>
        <a href="orders_cancellation.php" class="sx-card ol-tile<?= $requestCount ? ' bad' : '' ?>">
            <span>Cancel requests</span><strong><?= $requestCount ?></strong><small><?= (int)$sum['today'] ?> new order<?= (int)$sum['today'] === 1 ? '' : 's' ?> today</small>
        </a>
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
            <nav class="ol-view" aria-label="View">
                <a href="<?= h($url(['view' => 'cards'])) ?>" class="<?= $view === 'cards' ? 'on' : '' ?>"><i class="fa-solid fa-grip"></i> Cards</a>
                <a href="<?= h($url(['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'on' : '' ?>"><i class="fa-solid fa-list"></i> List</a>
            </nav>
        </form>
    </div>

    <?php if ($orders && $view === 'cards'): ?>
        <div class="ol-grid">
            <?php foreach ($orders as $o):
                $isB2B = $o['order_type'] === 'B2B' || $o['status'] === 'Pending PO' || $o['role'] === 'business';
                $link  = 'order_details.php?id=' . (int)$o['id'];
                $name  = $o['account_name'] ?: ($o['customer_name'] ?: 'Guest');
                $words = preg_split('/\s+/', trim($name)) ?: ['?'];
                $init  = mb_strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
                $imgs  = $o['thumbs'] !== null && $o['thumbs'] !== '' ? explode('|', $o['thumbs']) : [];
                $hot   = in_array($o['status'], ['pending', 'Pending PO', 'cancellation_requested'], true);
            ?>
                <article class="sx-card ol-card<?= $hot ? ' hot' : '' ?>" data-href="<?= $link ?>">
                    <div class="ol-card-top">
                        <span>
                            <a href="<?= $link ?>" class="ol-id">#<?= (int)$o['id'] ?></a>
                            <?php if ($isB2B): ?><span class="ol-tag"><?= $o['payment_terms'] && str_starts_with($o['payment_terms'], 'Net') ? h($o['payment_terms']) : 'B2B' ?></span><?php endif; ?>
                        </span>
                        <?= sx_order_status_pill($o['status']) ?>
                    </div>
                    <div class="ol-who">
                        <span class="ol-av<?= $isB2B ? ' b2b' : '' ?>" aria-hidden="true"><?= h($init) ?></span>
                        <div>
                            <strong><?= h($name) ?></strong>
                            <small><?= h($o['email'] ?: 'Guest order') ?></small>
                        </div>
                    </div>
                    <div class="ol-thumbs" aria-label="<?= count($imgs) ?> product line<?= count($imgs) === 1 ? '' : 's' ?>">
                        <?php foreach (array_slice($imgs, 0, 4) as $img): ?>
                            <img src="<?= h($img ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                        <?php endforeach; ?>
                        <?php if (count($imgs) > 4): ?><span>+<?= count($imgs) - 4 ?></span><?php endif; ?>
                        <em><?= (int)$o['units'] ?> unit<?= (int)$o['units'] === 1 ? '' : 's' ?></em>
                    </div>
                    <div class="ol-card-foot">
                        <b>₱<?= number_format((float)$o['total'], 2) ?></b>
                        <div>
                            <small><i class="fa-regular fa-clock"></i><?= $ago($o['created_at']) ?></small>
                            <?php if ($o['delivery_date']): ?><small><i class="fa-solid fa-truck-fast"></i><?= date('M j', strtotime($o['delivery_date'])) ?></small><?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php elseif ($orders): ?>
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

    <?php endif; ?>
    <?php if ($orders): ?>
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
document.querySelectorAll('.ol-table tr[data-href], .ol-card[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
    if (!e.target.closest('a')) window.location.href = tr.dataset.href;
}));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
