<?php
// admin/deliveries.php — iskedyul ng delivery ng mga aktibong order (Phase 9i: kanban board ng cards).
// Binabasa mula sa orders + deliveries; hindi na nagsusulat sa DB habang binubuksan
// (dati: "failsafe sync" na gumagawa ng deliveries row tuwing load). Ang pagpalit ng
// status/petsa ay sa order_details.php (includes/order_ops.php).
// Walang ?view= → board (5 column, tig-SX_DL_COLUMN na card). May ?view= → lahat ng card ng column na iyon.
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/order_ops.php';

$today = date('Y-m-d');
$week  = date('Y-m-d', strtotime('+7 days'));

// Aktibo = may kailangan pang i-deliver
$activeSql = "o.status IN ('pending', 'Pending PO', 'preparing', 'shipped')";
$views = [
    'overdue'     => ['Overdue',     "{$activeSql} AND d.delivery_date < ?",                 [$today], 'danger', 'fa-triangle-exclamation'],
    'today'       => ['Today',       "{$activeSql} AND d.delivery_date = ?",                 [$today], 'warn', 'fa-sun'],
    'upcoming'    => ['Next 7 days', "{$activeSql} AND d.delivery_date > ? AND d.delivery_date <= ?", [$today, $week], '', 'fa-calendar-week'],
    'unscheduled' => ['No date yet', "{$activeSql} AND d.delivery_date IS NULL",             [], 'muted', 'fa-calendar-xmark'],
    'later'       => ['Later',       "{$activeSql} AND d.delivery_date > ?",                 [$week], '', 'fa-calendar'],
    'delivered'   => ['Delivered',   "o.status IN ('delivered', 'completed')",               [], 'ok', 'fa-circle-check'],
];
const SX_DL_COLUMN = 8; // card bawat column sa board
$view   = isset($views[$_GET['view'] ?? '']) ? $_GET['view'] : null; // null = board
$search = trim($_GET['q'] ?? '');
$limit  = 24;
$page   = max(1, (int)($_GET['page'] ?? 1));

// Isang delivery row bawat order (pinakahuli), para walang doble
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

// Bilang bawat column
$counts = [];
foreach ($views as $k => [, $sql, $p]) {
    $c = $pdo->prepare("SELECT COUNT(*) {$from} WHERE {$sql}{$searchSql}");
    $c->execute(array_merge($p, $searchParams));
    $counts[$k] = (int)$c->fetchColumn();
}

$select = "
    SELECT o.id, o.status, o.total, o.address, o.customer_name, o.phone_number, o.created_at, o.payment_terms, o.order_type,
           u.name AS account_name, u.role, d.delivery_date,
           (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) AS units
    {$from}
";
$orderBy = fn($k) => $k === 'delivered' ? 'd.delivery_date DESC, o.id DESC' : ($k === 'unscheduled' ? 'o.created_at ASC' : 'd.delivery_date ASC, o.id ASC');

$columns = [];
if ($view === null) {
    foreach (['overdue', 'today', 'upcoming', 'unscheduled', 'later'] as $k) {
        $st = $pdo->prepare("{$select} WHERE {$views[$k][1]}{$searchSql} ORDER BY {$orderBy($k)} LIMIT " . SX_DL_COLUMN);
        $st->execute(array_merge($views[$k][2], $searchParams));
        $columns[$k] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $total = $counts[$view];
    $totalPages = max(1, (int)ceil($total / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;
    $st = $pdo->prepare("{$select} WHERE {$views[$view][1]}{$searchSql} ORDER BY {$orderBy($view)} LIMIT {$limit} OFFSET {$offset}");
    $st->execute(array_merge($views[$view][2], $searchParams));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

$url = function (array $changes = []) use ($view, $search, $page) {
    $q = array_filter(array_merge(['view' => $view, 'q' => $search, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'deliveries.php' . ($q ? '?' . http_build_query($q) : '');
};

// Isang delivery card (board at grid)
$card = function (array $r, bool $done) use ($today): string {
    $link = 'order_details.php?id=' . (int)$r['id'] . '&from=deliveries';
    $name = $r['customer_name'] ?: ($r['account_name'] ?: 'Guest');
    $isPO = $r['order_type'] === 'B2B' || $r['status'] === 'Pending PO' || $r['role'] === 'business';
    $days = $r['delivery_date'] ? (int)round((strtotime($r['delivery_date']) - strtotime($today)) / 86400) : null;
    if ($r['delivery_date'] === null) $when = 'Ordered ' . date('M j', strtotime($r['created_at']));
    elseif ($done) $when = date('M j, Y', strtotime($r['delivery_date']));
    elseif ($days === 0) $when = 'Today';
    elseif ($days < 0) $when = abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late';
    else $when = date('D, M j', strtotime($r['delivery_date'])) . ' · in ' . $days . 'd';
    $late = !$done && $days !== null && $days < 0;
    ob_start(); ?>
    <a href="<?= h($link) ?>" class="dl-card<?= $late ? ' late' : '' ?>">
        <div class="dl-card-top">
            <span class="dl-id">#<?= (int)$r['id'] ?><?php if ($isPO): ?><span class="dl-tag">PO</span><?php endif; ?></span>
            <?= sx_order_status_pill($r['status']) ?>
        </div>
        <strong class="dl-name"><?= h($name) ?></strong>
        <p class="dl-addr"><i class="fa-solid fa-location-dot"></i><?= h($r['address'] ?: 'No address') ?></p>
        <?php if ($r['phone_number']): ?><p class="dl-addr"><i class="fa-solid fa-phone"></i><?= h($r['phone_number']) ?></p><?php endif; ?>
        <div class="dl-card-foot">
            <span class="dl-when<?= $late ? ' late' : '' ?>"><i class="fa-regular fa-calendar"></i><?= h($when) ?></span>
            <span><?= (int)$r['units'] ?> unit<?= (int)$r['units'] === 1 ? '' : 's' ?> · <b>₱<?= number_format((float)$r['total'], 0) ?></b></span>
        </div>
    </a>
    <?php return ob_get_clean();
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   DELIVERIES (Phase 9i) — kanban board ng cards; tokens mula sa assets/style.css
   ============================================================ */
.dl { max-width: 1600px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.dl-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.dl-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.dl-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.dl-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.dl-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.dl-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }
.dl-search { display: flex; gap: var(--sx-space-2); flex: 1 1 280px; max-width: 420px; }
.dl-search .sx-input { flex: 1; min-width: 0; }

/* Board */
.dl-board { display: grid; grid-auto-columns: minmax(260px, 1fr); grid-auto-flow: column; gap: var(--sx-space-4); overflow-x: auto; padding-bottom: var(--sx-space-3); scroll-snap-type: x mandatory; }
.dl-col { display: flex; flex-direction: column; gap: var(--sx-space-3); min-width: 0; padding: var(--sx-space-3); border-radius: var(--sx-radius-xl); background: var(--sx-surface-alt); scroll-snap-align: start; }
.dl-col-head { display: flex; align-items: center; gap: var(--sx-space-2); padding: var(--sx-space-1) var(--sx-space-2); }
.dl-col-head i { display: grid; place-items: center; width: 30px; height: 30px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 0.8rem; }
.dl-col.danger .dl-col-head i { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.dl-col.warn .dl-col-head i { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.dl-col.muted .dl-col-head i { background: var(--sx-surface); color: var(--sx-muted); }
.dl-col-head h2 { margin: 0; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.dl-col-head span { margin-left: auto; padding: 2px 10px; border-radius: var(--sx-radius-pill); background: var(--sx-surface); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); color: var(--sx-muted); }
.dl-col-empty { padding: var(--sx-space-5) var(--sx-space-2); color: var(--sx-muted); font-size: var(--sx-text-sm); text-align: center; }
.dl-more { display: block; padding: var(--sx-space-2); border-radius: var(--sx-radius-md); color: var(--sx-primary); font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); text-align: center; text-decoration: none; }
.dl-more:hover { background: var(--sx-surface); }

/* Card */
.dl-card { display: flex; flex-direction: column; gap: var(--sx-space-2); padding: var(--sx-space-4); border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-lg); background: var(--sx-surface); box-shadow: var(--sx-shadow-sm); color: inherit; text-decoration: none; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.dl-card:hover { transform: translateY(-3px); box-shadow: var(--sx-shadow-md); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.dl-card.late { border-left: 4px solid var(--sx-status-cancelled); }
.dl-card-top { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-2); }
.dl-id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.dl-tag { margin-left: 6px; padding: 1px 6px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-family: var(--sx-font); font-size: 10px; font-weight: var(--sx-weight-bold); }
.dl-name { overflow: hidden; color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.dl-addr { display: flex; gap: 6px; margin: 0; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.dl-addr i { margin-top: 2px; color: var(--sx-primary); }
.dl-addr:first-of-type { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.dl-card-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-2); margin-top: var(--sx-space-1); padding-top: var(--sx-space-2); border-top: 1px solid var(--sx-border-light); font-size: var(--sx-text-xs); color: var(--sx-muted); }
.dl-card-foot b { color: var(--sx-dark); }
.dl-when { font-weight: var(--sx-weight-semibold); color: var(--sx-dark); }
.dl-when i { margin-right: 4px; color: var(--sx-primary); }
.dl-when.late, .dl-when.late i { color: var(--sx-status-cancelled); }

/* Isang column (focus) */
.dl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--sx-space-4); }
@media (min-width: 1200px) { .dl-board { grid-auto-flow: row; grid-template-columns: repeat(5, minmax(0, 1fr)); overflow: visible; } }
</style>

<div class="dl sx-container">
    <div class="dl-head">
        <div>
            <span class="sx-eyebrow">Logistics</span>
            <h1 class="sx-heading-lg">Deliveries</h1>
            <p>Open orders by delivery date. Set dates and statuses on each order.</p>
        </div>
        <div class="dl-head-actions">
            <?php if ($view !== null): ?><a href="<?= h($url(['view' => null, 'page' => null])) ?>" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-table-columns"></i> Board</a><?php endif; ?>
            <a href="allorders.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-bag-shopping"></i> All orders</a>
        </div>
    </div>

    <div class="dl-bar">
        <nav class="dl-chips" aria-label="Delivery views">
            <a href="<?= h($url(['view' => null, 'page' => null])) ?>" class="sx-chip<?= $view === null ? ' active' : '' ?>"><i class="fa-solid fa-table-columns"></i> Board</a>
            <?php foreach ($views as $k => [$label]): ?>
                <a href="<?= h($url(['view' => $k, 'page' => null])) ?>" class="sx-chip<?= $view === $k ? ' active' : '' ?>"><?= $label ?> <span><?= $counts[$k] ?></span></a>
            <?php endforeach; ?>
        </nav>
        <form method="get" class="dl-search" role="search">
            <?php if ($view !== null): ?><input type="hidden" name="view" value="<?= h($view) ?>"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="Order #, customer or address…" value="<?= h($search) ?>" aria-label="Search deliveries">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
    </div>

    <?php if ($view === null): ?>
        <div class="dl-board">
            <?php foreach ($columns as $k => $list): [$label, , , $tone, $icon] = $views[$k]; ?>
                <section class="dl-col <?= $tone ?>" aria-label="<?= h($label) ?>">
                    <div class="dl-col-head"><i class="fa-solid <?= $icon ?>"></i><h2><?= $label ?></h2><span><?= $counts[$k] ?></span></div>
                    <?php foreach ($list as $r) echo $card($r, false); ?>
                    <?php if (!$list): ?><div class="dl-col-empty">Nothing here</div><?php endif; ?>
                    <?php if ($counts[$k] > count($list)): ?>
                        <a href="<?= h($url(['view' => $k, 'page' => null])) ?>" class="dl-more">Show all <?= $counts[$k] ?> →</a>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php elseif ($rows): ?>
        <div class="dl-grid">
            <?php foreach ($rows as $r) echo $card($r, $view === 'delivered'); ?>
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
            <h3>Nothing in “<?= h($views[$view][0]) ?>”</h3>
            <p><?= $search ? 'Try a different search.' : 'Pick another view above.' ?></p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
