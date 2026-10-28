<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

require_once __DIR__ . '/../includes/purchasing_ops.php'; // sx_reorder_items (supply chain tiles)

$currentAdmin = $_SESSION['user'] ?? [];

// Mga default para hindi mag-warning ang page kapag pumalya ang DB
$kpi = array_fill_keys(['revenue', 'orders', 'open', 'aov', 'cancelRate', 'invValue', 'units', 'low', 'out'], 0);
$attention = ['pending' => 0, 'po' => 0, 'cancel' => 0, 'delivered' => 0];
$monthLabels = $monthRevenue = $flowLabels = $flowIn = $flowOut = [];
$statusCounts = $topProducts = $lowStockList = $recentOrders = [];
$segments = ['customer' => 0, 'business' => 0, 'new30' => 0];
$supply = ['reorder' => 0, 'reorderCost' => 0, 'arriving' => 0, 'arrivingUnits' => 0, 'late' => 0, 'costValue' => 0, 'costed' => 0, 'products' => 0];

try {
    // ---- Sales: kita = delivered + completed lang (dati: kasama pati cancelled at pending)
    $s = $pdo->query("
        SELECT
            COUNT(*) AS orders,
            SUM(status NOT IN ('delivered','completed','cancelled')) AS open_orders,
            SUM(status = 'cancelled') AS cancelled,
            COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN total END), 0) AS revenue,
            COALESCE(AVG(CASE WHEN status <> 'cancelled' THEN total END), 0) AS aov
        FROM orders
    ")->fetch(PDO::FETCH_ASSOC);
    $kpi['orders']     = (int)$s['orders'];
    $kpi['open']       = (int)$s['open_orders'];
    $kpi['revenue']    = (float)$s['revenue'];
    $kpi['aov']        = (float)$s['aov'];
    $kpi['cancelRate'] = $s['orders'] ? $s['cancelled'] / $s['orders'] * 100 : 0;

    // ---- Inventory: galing sa inventory table (ito ang totoong stock; luma na ang products.stock)
    $i = $pdo->query("
        SELECT
            COALESCE(SUM(q.qty * q.price), 0) AS value,
            COALESCE(SUM(q.qty), 0) AS units,
            SUM(q.qty > 0 AND q.qty <= q.reorder_point) AS low,
            SUM(q.qty = 0) AS out_of_stock
        FROM (
            SELECT p.price, p.reorder_point, COALESCE(SUM(i.quantity), 0) AS qty
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            GROUP BY p.id, p.price, p.reorder_point
        ) q
    ")->fetch(PDO::FETCH_ASSOC);
    $kpi['invValue'] = (float)$i['value'];
    $kpi['units']    = (int)$i['units'];
    $kpi['low']      = (int)$i['low'];
    $kpi['out']      = (int)$i['out_of_stock'];

    // ---- Kailangang aksyunan
    $attention = $pdo->query("
        SELECT
            SUM(status = 'pending') AS pending,
            SUM(status = 'Pending PO') AS po,
            SUM(status = 'cancellation_requested') AS cancel,
            SUM(status = 'delivered') AS delivered
        FROM orders
    ")->fetch(PDO::FETCH_ASSOC);

    // ---- Revenue bawat buwan, huling 12 buwan (kasama ang buwang walang benta)
    $rows = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS revenue
        FROM orders
        WHERE status IN ('delivered','completed')
          AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
        GROUP BY ym
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($m = 11; $m >= 0; $m--) {
        $ym = date('Y-m', strtotime("first day of -$m month"));
        $monthLabels[]  = date('M y', strtotime($ym . '-01'));
        $monthRevenue[] = round((float)($rows[$ym] ?? 0), 2);
    }

    // ---- Stock in vs out bawat linggo, huling 12 linggo (stock_movements)
    $rows = $pdo->query("
        SELECT YEARWEEK(created_at, 1) AS yw,
               SUM(CASE WHEN movement_type = 'IN'  THEN quantity ELSE 0 END) AS qty_in,
               SUM(CASE WHEN movement_type = 'OUT' THEN ABS(quantity) ELSE 0 END) AS qty_out
        FROM stock_movements
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
        GROUP BY yw
    ")->fetchAll(PDO::FETCH_UNIQUE);
    for ($w = 11; $w >= 0; $w--) {
        $ts = strtotime("monday this week -$w week");
        $yw = date('oW', $ts);
        $flowLabels[] = date('M j', $ts);
        $flowIn[]     = (int)($rows[$yw]['qty_in'] ?? 0);
        $flowOut[]    = (int)($rows[$yw]['qty_out'] ?? 0);
    }

    // ---- Orders bawat status
    $statusCounts = $pdo->query("SELECT status, COUNT(*) FROM orders GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

    // ---- Top products (units, hindi kasama ang cancelled)
    $topProducts = $pdo->query("
        SELECT p.name, SUM(oi.quantity) AS qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
        JOIN products p ON p.id = oi.product_id
        GROUP BY p.id, p.name
        ORDER BY qty DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ---- Pinakamababang stock
    $lowStockList = $pdo->query("
        SELECT p.id, p.name, p.category, p.reorder_point, COALESCE(SUM(i.quantity), 0) AS qty
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        GROUP BY p.id, p.name, p.category, p.reorder_point
        HAVING qty <= p.reorder_point
        ORDER BY qty ASC, p.name
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ---- Pinakabagong orders
    $recentOrders = $pdo->query("
        SELECT o.id, COALESCE(u.name, o.customer_name, 'Guest') AS name, o.total, o.status, o.order_type, o.created_at
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ---- Customers
    $segments = $pdo->query("
        SELECT SUM(role = 'customer') AS customer,
               SUM(role = 'business') AS business,
               SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS new30
        FROM users
    ")->fetch(PDO::FETCH_ASSOC);

    // ---- Supply chain (Phase 7e)
    foreach (sx_reorder_items($pdo) as $r) {
        if ($r['suggest'] > 0) {
            $supply['reorder']++;
            $supply['reorderCost'] += $r['suggest'] * (float)$r['cost_price'];
        }
    }
    $po = $pdo->query("
        SELECT SUM(po.expected_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS arriving,
               SUM(po.expected_date < CURDATE()) AS late,
               COALESCE(SUM(CASE WHEN po.expected_date <= CURDATE() + INTERVAL 7 DAY THEN i.left_units END), 0) AS arriving_units
        FROM purchase_orders po
        LEFT JOIN (SELECT po_id, SUM(qty_ordered - qty_received) AS left_units FROM purchase_order_items GROUP BY po_id) i ON i.po_id = po.id
        WHERE po.status IN ('sent', 'partial')
    ")->fetch(PDO::FETCH_ASSOC);
    $supply['arriving']      = (int)$po['arriving'];
    $supply['late']          = (int)$po['late'];
    $supply['arrivingUnits'] = (int)$po['arriving_units'];
    $c = $pdo->query("
        SELECT COALESCE(SUM(q.qty * q.cost_price), 0) AS value, SUM(q.cost_price IS NOT NULL) AS costed, COUNT(*) AS products
        FROM (SELECT p.cost_price, COALESCE(SUM(i.quantity), 0) AS qty FROM products p LEFT JOIN inventory i ON i.product_id = p.id GROUP BY p.id, p.cost_price) q
    ")->fetch(PDO::FETCH_ASSOC);
    $supply['costValue'] = (float)$c['value'];
    $supply['costed']    = (int)$c['costed'];
    $supply['products']  = (int)$c['products'];

} catch (PDOException $e) {
    error_log("Dashboard DB Error: " . $e->getMessage());
    $dbError = true;
}

// Status → [label, pill] (kapareho ng customer pages)
$statusMeta = [
    'pending'                => ['Pending', 'pending'],
    'Pending PO'             => ['PO review', 'pending'],
    'preparing'              => ['Preparing', 'preparing'],
    'shipped'                => ['Shipped', 'shipped'],
    'delivered'              => ['Delivered', 'delivered'],
    'completed'              => ['Completed', 'completed'],
    'cancellation_requested' => ['Cancel req.', 'cancelled'],
    'cancelled'              => ['Cancelled', 'cancelled'],
];
// Pagkakasunod sa doughnut chart + kulay mula sa status tokens
$funnel = [
    'Pending'   => ['pending', 'Pending PO'],
    'Preparing' => ['preparing', 'shipped'],
    'Delivered' => ['delivered'],
    'Completed' => ['completed'],
    'Cancelled' => ['cancellation_requested', 'cancelled'],
];
$funnelData = [];
foreach ($funnel as $label => $keys) {
    $funnelData[$label] = array_sum(array_map(fn($k) => (int)($statusCounts[$k] ?? 0), $keys));
}

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim($currentAdmin['name'] ?? 'Admin'))[0];
$peso = fn($n, $d = 0) => '₱' . number_format((float)$n, $d);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ADMIN DASHBOARD (Phase 9e) — tokens + sx-card / sx-pill / sx-table mula sa assets/style.css
   ============================================================ */
.db { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.db-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-4); margin-bottom: var(--sx-space-5); }
.db-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.db-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

.db-section-label { margin: var(--sx-space-5) 0 var(--sx-space-3); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); letter-spacing: 0.08em; text-transform: uppercase; color: var(--sx-muted); }
.db-section-label:first-of-type { margin-top: 0; }

/* KPI tiles */
.db-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); }
.db-kpi { display: flex; flex-direction: column; gap: 6px; padding: var(--sx-space-4); }
.db-kpi-top { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-2); }
.db-kpi-label { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.db-kpi-icon { display: grid; place-items: center; width: 34px; height: 34px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 0.9rem; }
.db-kpi-icon.accent { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.db-kpi-icon.warn { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.db-kpi-icon.danger { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.db-kpi-value { font-size: clamp(1.25rem, 1rem + 1vw, 1.7rem); font-weight: var(--sx-weight-extrabold); letter-spacing: -0.02em; color: var(--sx-dark); line-height: 1.1; }
.db-kpi-meta { font-size: var(--sx-text-xs); color: var(--sx-muted); }
a.db-kpi { color: inherit; text-decoration: none; transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
a.db-kpi:hover { transform: translateY(-2px); box-shadow: var(--sx-shadow-md); }

/* Needs attention */
.db-attn { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-2); }
.db-attn a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sx-space-2);
    padding: var(--sx-space-3) var(--sx-space-4);
    border: 1px solid var(--sx-border-light);
    border-radius: var(--sx-radius-md);
    background: var(--sx-surface);
    color: var(--sx-dark);
    font-size: var(--sx-text-sm);
    font-weight: var(--sx-weight-semibold);
    text-decoration: none;
}
.db-attn a:hover { border-color: var(--sx-primary); }
.db-attn strong { font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); }
.db-attn a.hot strong { color: var(--sx-status-pending); }

/* Panels */
.db-grid { display: grid; gap: var(--sx-space-4); margin-top: var(--sx-space-4); }
.db-panel { display: flex; flex-direction: column; min-width: 0; }
.db-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.db-panel-head h2 { margin: 0; font-size: var(--sx-text-base); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.db-panel-head p { margin: 2px 0 0; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.db-panel-head a { flex-shrink: 0; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); color: var(--sx-primary); text-decoration: none; }
.db-chart { position: relative; height: 260px; }
.db-chart.sm { height: 220px; }

/* Lists */
.db-list { display: grid; margin: 0; padding: 0; list-style: none; }
.db-list li { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-3); padding: 10px 0; border-bottom: 1px solid var(--sx-border-light); font-size: var(--sx-text-sm); }
.db-list li:last-child { border-bottom: none; }
.db-list .name { min-width: 0; overflow: hidden; color: var(--sx-dark); font-weight: var(--sx-weight-semibold); text-overflow: ellipsis; white-space: nowrap; }
.db-list small { display: block; font-weight: var(--sx-weight-medium); color: var(--sx-muted); }
.db-qty { flex-shrink: 0; padding: 2px 10px; border-radius: var(--sx-radius-pill); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); }
.db-qty.out { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.db-qty.low { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.db-ok { display: grid; place-items: center; gap: var(--sx-space-2); padding: var(--sx-space-6) 0; color: var(--sx-accent-dark); text-align: center; }
.db-ok i { font-size: 1.6rem; }

.db-table td a { color: var(--sx-dark); font-weight: var(--sx-weight-bold); text-decoration: none; }
.db-table td a:hover { color: var(--sx-primary); }
.db-table .num { text-align: right; white-space: nowrap; }
.db-tag { margin-left: 6px; padding: 1px 6px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 10px; font-weight: var(--sx-weight-bold); }

@media (min-width: 768px) {
    .db-kpis { grid-template-columns: repeat(4, 1fr); }
    .db-attn { grid-template-columns: repeat(4, 1fr); }
    .db-grid.two { grid-template-columns: 1fr 1fr; }
}
@media (min-width: 1200px) {
    .db-grid.main { grid-template-columns: 2fr 1fr; }
    .db-grid.three { grid-template-columns: 1fr 1fr 1fr; }
    .db-grid.bottom { grid-template-columns: 1fr 2fr; }
}
</style>

<div class="db sx-container">
    <div class="db-head">
        <div>
            <span class="sx-eyebrow"><?= date('l, F j') ?></span>
            <h1 class="sx-heading-lg"><?= $greeting ?>, <?= h($firstName) ?></h1>
            <p>Here's how sales and stock are moving.</p>
        </div>
        <div class="db-actions">
            <a href="/sweepxpress/admin/stock_in.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-truck-ramp-box"></i> Stock in</a>
            <a href="/sweepxpress/admin/add_product.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-plus"></i> Add product</a>
            <a href="/sweepxpress/admin/allorders.php" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-bag-shopping"></i> View orders</a>
        </div>
    </div>

    <?php if (!empty($dbError)): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div>Some figures couldn't be loaded. Check the error log.</div>
        </div>
    <?php endif; ?>

    <!-- Needs attention -->
    <div class="db-section-label">Needs attention</div>
    <div class="db-attn">
        <a href="/sweepxpress/admin/allorders.php" class="<?= $attention['pending'] ? 'hot' : '' ?>"><span>Pending orders</span><strong><?= (int)$attention['pending'] ?></strong></a>
        <a href="/sweepxpress/admin/allorders.php" class="<?= $attention['po'] ? 'hot' : '' ?>"><span>POs to review</span><strong><?= (int)$attention['po'] ?></strong></a>
        <a href="/sweepxpress/admin/allorders.php" class="<?= $attention['cancel'] ? 'hot' : '' ?>"><span>Cancel requests</span><strong><?= (int)$attention['cancel'] ?></strong></a>
        <a href="/sweepxpress/admin/reorder.php" class="<?= $supply['reorder'] ? 'hot' : '' ?>"><span>Needs reorder</span><strong><?= $supply['reorder'] ?></strong></a>
    </div>

    <!-- Sales KPIs -->
    <div class="db-section-label">Sales</div>
    <div class="db-kpis">
        <div class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Revenue</span><span class="db-kpi-icon accent"><i class="fa-solid fa-peso-sign"></i></span></div>
            <div class="db-kpi-value"><?= $peso($kpi['revenue']) ?></div>
            <div class="db-kpi-meta">Delivered &amp; completed orders</div>
        </div>
        <a href="/sweepxpress/admin/allorders.php" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Orders</span><span class="db-kpi-icon"><i class="fa-solid fa-bag-shopping"></i></span></div>
            <div class="db-kpi-value"><?= number_format($kpi['orders']) ?></div>
            <div class="db-kpi-meta"><?= number_format($kpi['open']) ?> still open</div>
        </a>
        <div class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Avg order value</span><span class="db-kpi-icon"><i class="fa-solid fa-receipt"></i></span></div>
            <div class="db-kpi-value"><?= $peso($kpi['aov'], 2) ?></div>
            <div class="db-kpi-meta">Excludes cancelled</div>
        </div>
        <div class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Cancellation rate</span><span class="db-kpi-icon danger"><i class="fa-solid fa-ban"></i></span></div>
            <div class="db-kpi-value"><?= number_format($kpi['cancelRate'], 1) ?>%</div>
            <div class="db-kpi-meta">Of all orders</div>
        </div>
    </div>

    <!-- Inventory KPIs -->
    <div class="db-section-label">Inventory</div>
    <div class="db-kpis">
        <div class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Stock value</span><span class="db-kpi-icon accent"><i class="fa-solid fa-warehouse"></i></span></div>
            <div class="db-kpi-value"><?= $peso($kpi['invValue']) ?></div>
            <div class="db-kpi-meta">At selling price</div>
        </div>
        <a href="/sweepxpress/admin/inventory.php" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Units on hand</span><span class="db-kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></span></div>
            <div class="db-kpi-value"><?= number_format($kpi['units']) ?></div>
            <div class="db-kpi-meta">Across all locations</div>
        </a>
        <a href="/sweepxpress/admin/inventory.php?filter=low" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Low stock</span><span class="db-kpi-icon warn"><i class="fa-solid fa-triangle-exclamation"></i></span></div>
            <div class="db-kpi-value"><?= number_format($kpi['low']) ?></div>
            <div class="db-kpi-meta">At or below reorder point</div>
        </a>
        <a href="/sweepxpress/admin/inventory.php?filter=out" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Out of stock</span><span class="db-kpi-icon danger"><i class="fa-solid fa-circle-xmark"></i></span></div>
            <div class="db-kpi-value"><?= number_format($kpi['out']) ?></div>
            <div class="db-kpi-meta">Can't be ordered</div>
        </a>
    </div>

    <!-- Supply chain KPIs (Phase 7e) -->
    <div class="db-section-label">Supply chain</div>
    <div class="db-kpis">
        <a href="/sweepxpress/admin/reorder.php" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Needs reorder</span><span class="db-kpi-icon warn"><i class="fa-solid fa-cart-flatbed"></i></span></div>
            <div class="db-kpi-value"><?= number_format($supply['reorder']) ?></div>
            <div class="db-kpi-meta">About <?= $peso($supply['reorderCost']) ?> at cost</div>
        </a>
        <a href="/sweepxpress/admin/purchase_orders.php?tab=sent" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Arriving this week</span><span class="db-kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></span></div>
            <div class="db-kpi-value"><?= number_format($supply['arriving']) ?></div>
            <div class="db-kpi-meta"><?= number_format($supply['arrivingUnits']) ?> units due by <?= date('M j', strtotime('+7 days')) ?></div>
        </a>
        <a href="/sweepxpress/admin/purchase_orders.php" class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Late deliveries</span><span class="db-kpi-icon danger"><i class="fa-solid fa-clock"></i></span></div>
            <div class="db-kpi-value"><?= number_format($supply['late']) ?></div>
            <div class="db-kpi-meta">Past expected date</div>
        </a>
        <div class="sx-card db-kpi">
            <div class="db-kpi-top"><span class="db-kpi-label">Stock value at cost</span><span class="db-kpi-icon accent"><i class="fa-solid fa-coins"></i></span></div>
            <div class="db-kpi-value"><?= $peso($supply['costValue']) ?></div>
            <div class="db-kpi-meta"><?= $supply['costed'] ?> of <?= $supply['products'] ?> products have a cost price</div>
        </div>
    </div>

    <!-- Charts -->
    <div class="db-grid main">
        <section class="sx-card db-panel">
            <div class="db-panel-head">
                <div><h2>Revenue</h2><p>Delivered &amp; completed orders, last 12 months</p></div>
            </div>
            <div class="db-chart"><canvas id="revenueChart" aria-label="Monthly revenue chart" role="img"></canvas></div>
        </section>
        <section class="sx-card db-panel">
            <div class="db-panel-head">
                <div><h2>Orders by status</h2><p>All time</p></div>
            </div>
            <div class="db-chart"><canvas id="statusChart" aria-label="Orders by status chart" role="img"></canvas></div>
        </section>
    </div>

    <div class="db-grid two">
        <section class="sx-card db-panel">
            <div class="db-panel-head">
                <div><h2>Stock in vs out</h2><p>Units moved per week, last 12 weeks</p></div>
                <a href="/sweepxpress/admin/inventory.php">Inventory →</a>
            </div>
            <div class="db-chart sm"><canvas id="flowChart" aria-label="Stock in versus out chart" role="img"></canvas></div>
        </section>
        <section class="sx-card db-panel">
            <div class="db-panel-head">
                <div><h2>Top products</h2><p>Units sold, excluding cancelled orders</p></div>
                <a href="/sweepxpress/admin/products.php">Products →</a>
            </div>
            <div class="db-chart sm"><canvas id="topChart" aria-label="Top products chart" role="img"></canvas></div>
        </section>
    </div>

    <div class="db-grid bottom">
        <div class="db-panel" style="gap: var(--sx-space-4)">
            <section class="sx-card db-panel">
                <div class="db-panel-head">
                    <div><h2>Restock soon</h2><p>Lowest stock first</p></div>
                    <a href="/sweepxpress/admin/stock_in.php">Stock in →</a>
                </div>
                <?php if ($lowStockList): ?>
                    <ul class="db-list">
                        <?php foreach ($lowStockList as $p): ?>
                            <li>
                                <span class="name"><?= h($p['name']) ?><small>Reorder at <?= (int)$p['reorder_point'] ?> · <?= h($p['category'] ?: 'Uncategorized') ?></small></span>
                                <span class="db-qty <?= (int)$p['qty'] === 0 ? 'out' : 'low' ?>"><?= (int)$p['qty'] === 0 ? 'Out' : (int)$p['qty'] . ' left' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="db-ok"><i class="fa-solid fa-circle-check"></i> All products are well stocked.</div>
                <?php endif; ?>
            </section>
            <section class="sx-card db-panel">
                <div class="db-panel-head"><div><h2>Customers</h2><p>Registered accounts</p></div></div>
                <ul class="db-list">
                    <li><span class="name">Customer accounts</span><strong><?= (int)$segments['customer'] ?></strong></li>
                    <li><span class="name">Business accounts</span><strong><?= (int)$segments['business'] ?></strong></li>
                    <li><span class="name">New in the last 30 days</span><strong><?= (int)$segments['new30'] ?></strong></li>
                </ul>
            </section>
        </div>

        <section class="sx-card db-panel">
            <div class="db-panel-head">
                <div><h2>Recent orders</h2><p>Latest 6</p></div>
                <a href="/sweepxpress/admin/allorders.php">All orders →</a>
            </div>
            <div class="sx-table-wrap">
                <table class="sx-table db-table">
                    <thead>
                        <tr><th>Order</th><th>Customer</th><th class="num">Total</th><th>Status</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $ro):
                            [$label, $pill] = $statusMeta[$ro['status']] ?? [ucfirst($ro['status']), 'pending'];
                        ?>
                            <tr>
                                <td>
                                    <a href="/sweepxpress/admin/order_details.php?id=<?= (int)$ro['id'] ?>">#<?= (int)$ro['id'] ?></a>
                                    <?php if ($ro['order_type'] === 'B2B' || $ro['status'] === 'Pending PO'): ?><span class="db-tag">PO</span><?php endif; ?>
                                </td>
                                <td><?= h($ro['name']) ?></td>
                                <td class="num"><?= $peso($ro['total'], 2) ?></td>
                                <td><span class="sx-pill sx-pill-<?= $pill ?>"><?= h($label) ?></span></td>
                                <td><?= date('M j, g:i A', strtotime($ro['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$recentOrders): ?>
                            <tr><td colspan="5" class="sx-empty">No orders yet.</td></tr>
                        <?php endif; ?>
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

    // Kulay mula sa design tokens para sumunod sa theme
    const css = getComputedStyle(document.documentElement);
    const tok = (name) => css.getPropertyValue(name).trim();
    const primary = tok('--sx-primary'), accent = tok('--sx-accent'), muted = tok('--sx-muted'), grid = tok('--sx-border-light');

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = muted;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.maintainAspectRatio = false;

    const peso = (v) => '₱' + Number(v).toLocaleString('en-PH');
    const axes = (money) => ({
        x: { grid: { display: false } },
        y: { beginAtZero: true, grid: { color: grid }, ticks: { callback: money ? (v) => peso(v) : undefined, precision: 0 } },
    });

    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($monthLabels) ?>,
            datasets: [{ label: 'Revenue', data: <?= json_encode($monthRevenue) ?>, backgroundColor: primary, borderRadius: 6, maxBarThickness: 36 }],
        },
        options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => peso(c.parsed.y) } } }, scales: axes(true) },
    });

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($funnelData)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($funnelData)) ?>,
                backgroundColor: ['--sx-status-pending', '--sx-status-preparing', '--sx-status-delivered', '--sx-status-completed', '--sx-status-cancelled'].map(tok),
                borderWidth: 0,
            }],
        },
        options: { cutout: '65%', plugins: { legend: { position: 'bottom' } } },
    });

    new Chart(document.getElementById('flowChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($flowLabels) ?>,
            datasets: [
                { label: 'Stock in', data: <?= json_encode($flowIn) ?>, backgroundColor: accent, borderRadius: 4, maxBarThickness: 18 },
                { label: 'Stock out', data: <?= json_encode($flowOut) ?>, backgroundColor: primary, borderRadius: 4, maxBarThickness: 18 },
            ],
        },
        options: { plugins: { legend: { position: 'top', align: 'end' } }, scales: axes(false) },
    });

    new Chart(document.getElementById('topChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($topProducts, 'name')) ?>,
            datasets: [{ label: 'Units sold', data: <?= json_encode(array_map('intval', array_column($topProducts, 'qty'))) ?>, backgroundColor: accent, borderRadius: 6, maxBarThickness: 22 }],
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } }, y: { grid: { display: false } } },
        },
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
