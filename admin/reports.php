<?php
// admin/reports.php — sales report sa napiling petsa: KPIs, buwanang revenue, top products/customers,
// retail vs business, at CSV export ng mga order.
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/order_ops.php'; // sx_order_status_pill / sx_order_statuses

// ---------------- Petsa ----------------
$presets = ['30d' => ['Last 30 days', '-30 days'], '90d' => ['Last 90 days', '-90 days'], '6m' => ['Last 6 months', '-6 months'],
            '12m' => ['Last 12 months', '-12 months'], 'all' => ['All time', null]];
$valid = fn($d) => ($t = DateTime::createFromFormat('Y-m-d', (string)$d)) && $t->format('Y-m-d') === $d;
$range = $_GET['range'] ?? '12m';
if (isset($_GET['start_date'], $_GET['end_date']) && $valid($_GET['start_date']) && $valid($_GET['end_date']) && $_GET['start_date'] <= $_GET['end_date']) {
    $range = 'custom';
    $startDate = $_GET['start_date'];
    $endDate = $_GET['end_date'];
} else {
    if (!isset($presets[$range])) $range = '12m';
    $endDate = date('Y-m-d');
    $startDate = $presets[$range][1] ? date('Y-m-d', strtotime($presets[$range][1])) : ($pdo->query("SELECT DATE(MIN(created_at)) FROM orders")->fetchColumn() ?: $endDate);
}
$p = ['s' => "{$startDate} 00:00:00", 'e' => "{$endDate} 23:59:59"];
$inRange = "o.created_at BETWEEN :s AND :e";

// ---------------- CSV export (bago ang header) ----------------
if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->prepare("
        SELECT o.id, o.created_at, COALESCE(u.name, o.customer_name, 'Guest') AS customer, u.email, o.order_type, o.status,
               o.payment_terms, o.total
        FROM orders o LEFT JOIN users u ON u.id = o.user_id
        WHERE {$inRange} ORDER BY o.created_at
    ");
    $rows->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"sales_{$startDate}_to_{$endDate}.csv\"");
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Order ID', 'Date', 'Customer', 'Email', 'Type', 'Status', 'Terms', 'Total']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['customer'], $r['email'], $r['order_type'], $r['status'], $r['payment_terms'], number_format((float)$r['total'], 2, '.', '')]);
    }
    exit;
}

// ---------------- Data ----------------
// Revenue = delivered + completed (dati: kasama pati cancelled at pending)
$kpi = $pdo->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN total END), 0) AS revenue,
           COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total END), 0) AS booked,
           COALESCE(AVG(CASE WHEN status <> 'cancelled' THEN total END), 0) AS aov,
           SUM(status = 'cancelled') AS cancelled,
           COALESCE(SUM(CASE WHEN status = 'cancelled' THEN total END), 0) AS lost
    FROM orders o WHERE {$inRange}
");
$kpi->execute($p);
$kpi = $kpi->fetch(PDO::FETCH_ASSOC);

$nu = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at BETWEEN :s AND :e");
$nu->execute($p);
$newUsers = (int)$nu->fetchColumn();

// Buwanang revenue + booked, kasama ang buwang walang benta
$m = $pdo->prepare("
    SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS ym,
           SUM(CASE WHEN status IN ('delivered','completed') THEN total ELSE 0 END) AS revenue,
           SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS booked
    FROM orders o WHERE {$inRange} GROUP BY ym
");
$m->execute($p);
$monthRows = $m->fetchAll(PDO::FETCH_UNIQUE);
$monthLabels = $monthRevenue = $monthBooked = [];
for ($d = new DateTime(substr($startDate, 0, 7) . '-01'), $end = new DateTime(substr($endDate, 0, 7) . '-01'); $d <= $end; $d->modify('+1 month')) {
    $ym = $d->format('Y-m');
    $monthLabels[]  = $d->format('M y');
    $monthRevenue[] = round((float)($monthRows[$ym]['revenue'] ?? 0), 2);
    $monthBooked[]  = round((float)($monthRows[$ym]['booked'] ?? 0), 2);
}

$tp = $pdo->prepare("
    SELECT p.id, COALESCE(p.name, 'Deleted product') AS name, SUM(oi.quantity) AS qty, SUM(oi.quantity * oi.price) AS revenue
    FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products p ON p.id = oi.product_id
    WHERE {$inRange} AND o.status <> 'cancelled'
    GROUP BY oi.product_id, p.id, p.name ORDER BY revenue DESC LIMIT 8
");
$tp->execute($p);
$topProducts = $tp->fetchAll(PDO::FETCH_ASSOC);

$tcStmt = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.role, COUNT(*) AS orders, SUM(o.total) AS spent
    FROM orders o JOIN users u ON u.id = o.user_id
    WHERE {$inRange} AND o.status <> 'cancelled'
    GROUP BY u.id, u.name, u.email, u.role ORDER BY spent DESC LIMIT 8
");
$tcStmt->execute($p);
$topCustomers = $tcStmt->fetchAll(PDO::FETCH_ASSOC);

// Retail vs business (business = B2B, PO, o business account)
$sp = $pdo->prepare("
    SELECT CASE WHEN o.order_type = 'B2B' OR o.status = 'Pending PO' OR COALESCE(u.role, '') = 'business' THEN 'Business' ELSE 'Retail' END AS seg,
           COUNT(*) AS orders, SUM(o.total) AS booked
    FROM orders o LEFT JOIN users u ON u.id = o.user_id
    WHERE {$inRange} AND o.status <> 'cancelled' GROUP BY seg
");
$sp->execute($p);
$segments = $sp->fetchAll(PDO::FETCH_UNIQUE);

$st = $pdo->prepare("SELECT status, COUNT(*) FROM orders o WHERE {$inRange} GROUP BY status ORDER BY 2 DESC");
$st->execute($p);
$statusCounts = $st->fetchAll(PDO::FETCH_KEY_PAIR);

$cancelRate = $kpi['orders'] ? $kpi['cancelled'] / $kpi['orders'] * 100 : 0;
$peso = fn($n, $d = 0) => '₱' . number_format((float)$n, $d);
$qs = $range === 'custom' ? "start_date={$startDate}&end_date={$endDate}" : "range={$range}";

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   REPORTS (Phase 9g) — tokens + sx-card / sx-table / sx-chip mula sa assets/style.css
   ============================================================ */
.rp { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.rp-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.rp-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.rp-range { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); padding: var(--sx-space-3) var(--sx-space-4); }
.rp-range nav { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.rp-range form { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); }
.rp-range .sx-input { width: auto; }
.rp-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.rp-kpi { padding: var(--sx-space-4); }
.rp-kpi span { display: block; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.rp-kpi strong { display: block; margin-top: 4px; font-size: clamp(1.2rem, 1rem + 0.8vw, 1.6rem); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.rp-kpi small { display: block; margin-top: 2px; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.rp-grid { display: grid; gap: var(--sx-space-4); margin-bottom: var(--sx-space-4); }
.rp-panel h2 { margin: 0 0 4px; font-size: var(--sx-text-base); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.rp-panel > p { margin: 0 0 var(--sx-space-4); font-size: var(--sx-text-xs); color: var(--sx-muted); }
.rp-chart { position: relative; height: 280px; }
.rp-table .num { text-align: right; white-space: nowrap; }
.rp-table a { color: var(--sx-dark); font-weight: var(--sx-weight-semibold); text-decoration: none; }
.rp-table a:hover { color: var(--sx-primary); }
.rp-bar { height: 6px; margin-top: 4px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); overflow: hidden; }
.rp-bar i { display: block; height: 100%; background: var(--sx-primary); }
.rp-split { display: grid; gap: var(--sx-space-3); }
.rp-split div { display: flex; justify-content: space-between; font-size: var(--sx-text-sm); }
.rp-status { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.rp-status span { display: inline-flex; align-items: center; gap: 6px; font-size: var(--sx-text-sm); }
@media (min-width: 768px) { .rp-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 1200px) {
    .rp-kpis { grid-template-columns: repeat(6, 1fr); }
    .rp-grid.main { grid-template-columns: 2fr 1fr; }
    .rp-grid.two { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="rp sx-container">
    <div class="rp-head">
        <div>
            <span class="sx-eyebrow">Insights</span>
            <h1 class="sx-heading-lg">Sales report</h1>
            <p><?= date('M j, Y', strtotime($startDate)) ?> – <?= date('M j, Y', strtotime($endDate)) ?></p>
        </div>
        <a href="reports.php?<?= h($qs) ?>&export=csv" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-file-csv"></i> Export orders (CSV)</a>
    </div>

    <div class="sx-card rp-range">
        <nav aria-label="Date range">
            <?php foreach ($presets as $k => [$label]): ?>
                <a href="reports.php?range=<?= $k ?>" class="sx-chip<?= $range === $k ? ' active' : '' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="get">
            <input type="date" name="start_date" class="sx-input" value="<?= h($startDate) ?>" aria-label="Start date">
            <span class="sx-hint">to</span>
            <input type="date" name="end_date" class="sx-input" value="<?= h($endDate) ?>" max="<?= date('Y-m-d') ?>" aria-label="End date">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm">Apply</button>
        </form>
    </div>

    <div class="rp-kpis">
        <div class="sx-card rp-kpi"><span>Revenue</span><strong><?= $peso($kpi['revenue']) ?></strong><small>Delivered &amp; completed</small></div>
        <div class="sx-card rp-kpi"><span>Booked</span><strong><?= $peso($kpi['booked']) ?></strong><small>All except cancelled</small></div>
        <div class="sx-card rp-kpi"><span>Orders</span><strong><?= number_format((int)$kpi['orders']) ?></strong><small><?= (int)$kpi['cancelled'] ?> cancelled</small></div>
        <div class="sx-card rp-kpi"><span>Avg order</span><strong><?= $peso($kpi['aov'], 2) ?></strong><small>Excludes cancelled</small></div>
        <div class="sx-card rp-kpi"><span>Cancellation rate</span><strong><?= number_format($cancelRate, 1) ?>%</strong><small><?= $peso($kpi['lost']) ?> lost</small></div>
        <div class="sx-card rp-kpi"><span>New accounts</span><strong><?= $newUsers ?></strong><small>Signed up in range</small></div>
    </div>

    <div class="rp-grid main">
        <section class="sx-card rp-panel">
            <h2>Revenue by month</h2>
            <p>Revenue counts delivered and completed orders; booked includes everything not cancelled.</p>
            <div class="rp-chart"><canvas id="monthChart" role="img" aria-label="Revenue and booked sales by month"></canvas></div>
        </section>
        <section class="sx-card rp-panel">
            <h2>Retail vs business</h2>
            <p>Booked value, excluding cancelled orders.</p>
            <?php $segTotal = array_sum(array_column($segments, 'booked')) ?: 1; ?>
            <div class="rp-split">
                <?php foreach (['Retail', 'Business'] as $seg): $v = (float)($segments[$seg]['booked'] ?? 0); ?>
                    <div>
                        <span><strong><?= $seg ?></strong> · <?= (int)($segments[$seg]['orders'] ?? 0) ?> orders</span>
                        <span><?= $peso($v) ?> · <?= number_format($v / $segTotal * 100) ?>%</span>
                    </div>
                    <div class="rp-bar" style="margin-top: -6px"><i style="width: <?= round($v / $segTotal * 100, 1) ?>%"></i></div>
                <?php endforeach; ?>
            </div>
            <h2 style="margin-top: var(--sx-space-5)">Orders by status</h2>
            <p>All orders placed in range.</p>
            <div class="rp-status">
                <?php foreach ($statusCounts as $s => $n): ?>
                    <span><?= sx_order_status_pill($s) ?> <?= (int)$n ?></span>
                <?php endforeach; ?>
                <?php if (!$statusCounts): ?><span class="sx-hint">No orders in this range.</span><?php endif; ?>
            </div>
        </section>
    </div>

    <div class="rp-grid two">
        <section class="sx-card rp-panel">
            <h2>Top products</h2>
            <p>By booked revenue.</p>
            <?php $maxRev = max(array_column($topProducts, 'revenue') ?: [1]); ?>
            <div class="sx-table-wrap" style="border: 0">
                <table class="sx-table rp-table">
                    <thead><tr><th>Product</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($topProducts as $tpRow): ?>
                            <tr>
                                <td>
                                    <?php if ($tpRow['id']): ?><a href="product_history.php?product_id=<?= (int)$tpRow['id'] ?>"><?= h($tpRow['name']) ?></a><?php else: ?><?= h($tpRow['name']) ?><?php endif; ?>
                                    <div class="rp-bar"><i style="width: <?= round($tpRow['revenue'] / $maxRev * 100, 1) ?>%"></i></div>
                                </td>
                                <td class="num"><?= number_format((int)$tpRow['qty']) ?></td>
                                <td class="num"><?= $peso($tpRow['revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topProducts): ?><tr><td colspan="3" class="sx-hint">No sales in this range.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="sx-card rp-panel">
            <h2>Top customers</h2>
            <p>By booked value.</p>
            <div class="sx-table-wrap" style="border: 0">
                <table class="sx-table rp-table">
                    <thead><tr><th>Customer</th><th class="num">Orders</th><th class="num">Booked</th></tr></thead>
                    <tbody>
                        <?php foreach ($topCustomers as $c): ?>
                            <tr>
                                <td><a href="user_actions.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a><?= $c['role'] === 'business' ? ' <span class="sx-hint">· business</span>' : '' ?></td>
                                <td class="num"><?= (int)$c['orders'] ?></td>
                                <td class="num"><?= $peso($c['spent']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topCustomers): ?><tr><td colspan="3" class="sx-hint">No customers in this range.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;
    const css = getComputedStyle(document.documentElement);
    const tok = (n) => css.getPropertyValue(n).trim();
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = tok('--sx-muted');
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    const peso = (v) => '₱' + Number(v).toLocaleString('en-PH');
    new Chart(document.getElementById('monthChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthLabels) ?>,
            datasets: [
                { label: 'Revenue', data: <?= json_encode($monthRevenue) ?>, backgroundColor: tok('--sx-primary'), borderRadius: 6, maxBarThickness: 28 },
                { label: 'Booked', data: <?= json_encode($monthBooked) ?>, backgroundColor: tok('--sx-accent'), borderRadius: 6, maxBarThickness: 28 },
            ],
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top', align: 'end' }, tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + peso(c.parsed.y) } } },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: tok('--sx-border-light') }, ticks: { callback: (v) => peso(v) } } },
        },
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
