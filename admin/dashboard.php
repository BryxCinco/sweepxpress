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
        SELECT p.id, p.name, p.category, p.image_path, p.reorder_point, COALESCE(SUM(i.quantity), 0) AS qty
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        GROUP BY p.id, p.name, p.category, p.image_path, p.reorder_point
        HAVING qty <= p.reorder_point
        ORDER BY qty ASC, p.name
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ---- Pinakabagong orders
    $recentOrders = $pdo->query("
        SELECT o.id, COALESCE(u.name, o.customer_name, 'Guest') AS name, o.total, o.status, o.order_type, o.created_at,
               (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS units,
               (SELECT p.image_path FROM order_items oi JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = o.id ORDER BY oi.id LIMIT 1) AS thumb
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

// Ngayong buwan vs nakaraang buwan (galing sa $monthRevenue; walang dagdag na query)
$thisMonth = (float)($monthRevenue[11] ?? 0);
$lastMonth = (float)($monthRevenue[10] ?? 0);
$mom = $lastMonth > 0 ? ($thisMonth - $lastMonth) / $lastMonth * 100 : null;

// "5m ago", "3h ago", "Oct 2"
$ago = function (string $ts): string {
    $s = time() - strtotime($ts);
    if ($s < 3600) return max(1, (int)floor($s / 60)) . 'm ago';
    if ($s < 86400) return (int)floor($s / 3600) . 'h ago';
    if ($s < 7 * 86400) return (int)floor($s / 86400) . 'd ago';
    return date('M j', strtotime($ts));
};
$initials = function (string $name): string {
    $w = preg_split('/\s+/', trim($name)) ?: ['?'];
    return mb_strtoupper(mb_substr($w[0], 0, 1) . (isset($w[1]) ? mb_substr($w[1], 0, 1) : ''));
};
$placeholder = '/sweepxpress/assets/placeholder.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ADMIN DASHBOARD (Phase 9i) — bento grid, puro cards; tokens mula sa assets/style.css
   ============================================================ */
.db { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.db-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-4); margin-bottom: var(--sx-space-5); }
.db-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.db-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

/* ---- Bento grid ---- */
.db-bento { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--sx-space-4); }
.db-bento > * { min-width: 0; }
.db-card { display: flex; flex-direction: column; gap: var(--sx-space-4); padding: var(--sx-space-5); }
.db-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--sx-space-3); }
.db-card-head h2 { margin: 0; font-size: var(--sx-text-base); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.db-card-head p { margin: 2px 0 0; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.db-card-head a { flex-shrink: 0; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); color: var(--sx-primary); text-decoration: none; }
.db-icon { display: grid; place-items: center; flex-shrink: 0; width: 36px; height: 36px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 0.9rem; }
.db-icon.accent { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.db-icon.warn { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.db-icon.danger { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }

/* Hero: revenue */
.db-hero { position: relative; overflow: hidden; justify-content: space-between; min-height: 280px; border: 0; background: var(--sx-gradient-brand); color: #fff; }
.db-hero::after { content: ''; position: absolute; right: -90px; top: -110px; width: 320px; height: 320px; border-radius: 50%; background: radial-gradient(circle, rgba(110, 231, 183, 0.35) 0%, transparent 70%); pointer-events: none; }
.db-hero > * { position: relative; z-index: 1; }
.db-hero-label { display: flex; align-items: center; gap: var(--sx-space-2); font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); color: rgba(255, 255, 255, 0.85); }
.db-hero-label .db-icon { background: rgba(255, 255, 255, 0.15); color: #fff; }
.db-hero-value { margin: var(--sx-space-2) 0 0; font-size: clamp(2.2rem, 1.6rem + 2.4vw, 3.4rem); font-weight: var(--sx-weight-extrabold); letter-spacing: -0.03em; line-height: 1; }
.db-hero-sub { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); margin-top: var(--sx-space-3); font-size: var(--sx-text-sm); color: rgba(255, 255, 255, 0.85); }
.db-delta { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: var(--sx-radius-pill); background: rgba(255, 255, 255, 0.16); color: #fff; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); }
.db-delta.up { background: rgba(110, 231, 183, 0.25); }
.db-delta.down { background: rgba(252, 165, 165, 0.25); }
.db-hero-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sx-space-2); }
.db-hero-stats a, .db-hero-stats div { padding: var(--sx-space-3); border-radius: var(--sx-radius-md); background: rgba(255, 255, 255, 0.12); color: #fff; text-decoration: none; backdrop-filter: blur(6px); }
.db-hero-stats a:hover { background: rgba(255, 255, 255, 0.2); }
.db-hero-stats span { display: block; font-size: var(--sx-text-xs); color: rgba(255, 255, 255, 0.75); }
.db-hero-stats strong { display: block; margin-top: 2px; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); }

/* Needs attention: 4 action tiles sa loob ng isang card */
.db-attn { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); }
.db-attn a { display: flex; flex-direction: column; gap: var(--sx-space-3); padding: var(--sx-space-4); border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-lg); color: inherit; text-decoration: none; transition: transform var(--sx-transition), border-color var(--sx-transition), background var(--sx-transition); }
.db-attn a:hover { transform: translateY(-2px); border-color: var(--sx-primary); }
.db-attn a.hot { border-color: color-mix(in srgb, var(--sx-status-pending) 40%, transparent); background: color-mix(in srgb, var(--sx-status-pending-bg) 60%, transparent); }
.db-attn strong { font-size: 1.6rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.db-attn span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.db-attn .row { display: flex; align-items: center; justify-content: space-between; }

/* Stat cards (inventory, supply chain) */
.db-big { font-size: clamp(1.5rem, 1.1rem + 1.2vw, 2rem); font-weight: var(--sx-weight-extrabold); letter-spacing: -0.02em; line-height: 1.1; color: var(--sx-dark); }
.db-big small { display: block; margin-top: 4px; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-medium); letter-spacing: 0; color: var(--sx-muted); }
.db-chips { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-2); margin-top: auto; }
.db-chips a, .db-chips div { display: flex; flex-direction: column; gap: 2px; padding: var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); color: inherit; text-decoration: none; transition: background var(--sx-transition); }
.db-chips a:hover { background: var(--sx-primary-soft); }
.db-chips span { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.db-chips strong { font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.db-chips .warn strong { color: var(--sx-status-pending); }
.db-chips .bad strong { color: var(--sx-status-cancelled); }
.db-meter { height: 8px; overflow: hidden; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.db-meter i { display: block; height: 100%; border-radius: inherit; background: var(--sx-accent); }
.db-meter-label { display: flex; justify-content: space-between; margin-top: 6px; font-size: var(--sx-text-xs); color: var(--sx-muted); }

/* Charts */
.db-chart { position: relative; height: 260px; }
.db-chart.sm { height: 220px; }

/* Restock soon: product mini-cards */
.db-products { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: var(--sx-space-3); }
.db-product { display: flex; flex-direction: column; overflow: hidden; border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-lg); color: inherit; text-decoration: none; transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
.db-product:hover { transform: translateY(-3px); box-shadow: var(--sx-shadow-md); }
.db-product img { width: 100%; aspect-ratio: 16 / 10; background: var(--sx-surface-alt); object-fit: cover; }
.db-product div { display: grid; gap: 6px; padding: var(--sx-space-3); }
.db-product strong { overflow: hidden; font-size: var(--sx-text-sm); color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.db-product small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.db-product .db-meter { height: 6px; }
.db-product .db-meter i { background: var(--sx-status-pending); }
.db-product .db-meter i.out { background: var(--sx-status-cancelled); }
.db-qty { justify-self: start; padding: 2px 10px; border-radius: var(--sx-radius-pill); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); }
.db-qty.out { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.db-qty.low { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }

/* Recent orders: order cards */
.db-orders { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: var(--sx-space-3); }
.db-order { display: flex; flex-direction: column; gap: var(--sx-space-3); padding: var(--sx-space-4); border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-lg); color: inherit; text-decoration: none; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.db-order:hover { transform: translateY(-3px); box-shadow: var(--sx-shadow-md); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.db-order-top { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.db-order-top img, .db-order-top .av { width: 44px; height: 44px; flex-shrink: 0; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.db-order-top .av { display: grid; place-items: center; background: var(--sx-primary-soft); color: var(--sx-primary); font-weight: var(--sx-weight-extrabold); }
.db-order-top div { min-width: 0; }
.db-order-top strong { display: block; overflow: hidden; color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.db-order-top small { color: var(--sx-muted); font-size: var(--sx-text-xs); }
.db-order-foot { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-2); }
.db-order-foot b { font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.db-tag { margin-left: 4px; padding: 1px 6px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: 10px; font-weight: var(--sx-weight-bold); vertical-align: middle; }

/* Customers */
.db-people { display: grid; gap: var(--sx-space-3); }
.db-people div { display: flex; align-items: center; gap: var(--sx-space-3); }
.db-people strong { margin-left: auto; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.db-people span { font-size: var(--sx-text-sm); color: var(--sx-text); }
.db-ok { display: grid; place-items: center; gap: var(--sx-space-2); padding: var(--sx-space-6) 0; color: var(--sx-accent-dark); text-align: center; }
.db-ok i { font-size: 1.6rem; }

/* ---- Bento placement ---- */
@media (min-width: 768px) {
    .db-bento {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-template-areas:
            "hero    hero"
            "attn    attn"
            "inv     supply"
            "rev     rev"
            "status  people"
            "flow    top"
            "orders  orders"
            "restock restock";
    }
}
@media (min-width: 1200px) {
    .db-bento {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        grid-template-areas:
            "hero    hero    attn    attn"
            "hero    hero    inv     supply"
            "rev     rev     rev     status"
            "flow    flow    top     top"
            "orders  orders  orders  people"
            "restock restock restock restock";
    }
}
@media (min-width: 768px) {
    .db-hero { grid-area: hero; } .db-a-attn { grid-area: attn; } .db-a-inv { grid-area: inv; } .db-a-supply { grid-area: supply; }
    .db-a-rev { grid-area: rev; } .db-a-status { grid-area: status; } .db-a-flow { grid-area: flow; } .db-a-top { grid-area: top; }
    .db-a-orders { grid-area: orders; } .db-a-people { grid-area: people; } .db-a-restock { grid-area: restock; }
    .db-attn { grid-template-columns: repeat(4, 1fr); }
}
@media (min-width: 1200px) { .db-attn { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1400px) { .db-attn { grid-template-columns: repeat(4, 1fr); } }
</style>

<div class="db sx-container">
    <div class="db-head">
        <div>
            <span class="sx-eyebrow"><?= date('l, F j') ?></span>
            <h1 class="sx-heading-lg"><?= $greeting ?>, <?= h($firstName) ?></h1>
            <p>Here's how sales, stock and suppliers are moving.</p>
        </div>
        <div class="db-actions">
            <a href="/sweepxpress/admin/reorder.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-cart-flatbed"></i> Reorder</a>
            <a href="/sweepxpress/admin/add_product.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-plus"></i> Add product</a>
            <a href="/sweepxpress/admin/allorders.php" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-bag-shopping"></i> View orders</a>
        </div>
    </div>

    <?php if (!empty($dbError)): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div>Some figures couldn't be loaded. Check the error log.</div>
        </div>
    <?php endif; ?>

    <div class="db-bento">
        <!-- Revenue (hero) -->
        <section class="sx-card db-card db-hero" aria-label="Revenue">
            <div>
                <div class="db-hero-label"><span class="db-icon"><i class="fa-solid fa-peso-sign"></i></span> Revenue · delivered &amp; completed</div>
                <p class="db-hero-value"><?= $peso($kpi['revenue']) ?></p>
                <div class="db-hero-sub">
                    <span>This month <?= $peso($thisMonth) ?></span>
                    <?php if ($mom !== null): ?>
                        <span class="db-delta <?= $mom >= 0 ? 'up' : 'down' ?>"><i class="fa-solid fa-arrow-<?= $mom >= 0 ? 'up' : 'down' ?>"></i> <?= number_format(abs($mom), 1) ?>% vs last month</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="db-hero-stats">
                <a href="/sweepxpress/admin/allorders.php"><span>Orders</span><strong><?= number_format($kpi['orders']) ?></strong></a>
                <div><span>Avg order</span><strong><?= $peso($kpi['aov']) ?></strong></div>
                <div><span>Cancelled</span><strong><?= number_format($kpi['cancelRate'], 1) ?>%</strong></div>
            </div>
        </section>

        <!-- Needs attention -->
        <section class="sx-card db-card db-a-attn">
            <div class="db-card-head"><div><h2>Needs attention</h2><p><?= number_format($kpi['open']) ?> orders still open</p></div></div>
            <div class="db-attn">
                <a href="/sweepxpress/admin/allorders.php?tab=pending" class="<?= $attention['pending'] ? 'hot' : '' ?>">
                    <div class="row"><span class="db-icon"><i class="fa-solid fa-hourglass-half"></i></span><strong><?= (int)$attention['pending'] ?></strong></div><span>Pending orders</span>
                </a>
                <a href="/sweepxpress/admin/allorders.php?type=b2b" class="<?= $attention['po'] ? 'hot' : '' ?>">
                    <div class="row"><span class="db-icon"><i class="fa-solid fa-file-signature"></i></span><strong><?= (int)$attention['po'] ?></strong></div><span>POs to review</span>
                </a>
                <a href="/sweepxpress/admin/orders_cancellation.php" class="<?= $attention['cancel'] ? 'hot' : '' ?>">
                    <div class="row"><span class="db-icon danger"><i class="fa-solid fa-ban"></i></span><strong><?= (int)$attention['cancel'] ?></strong></div><span>Cancel requests</span>
                </a>
                <a href="/sweepxpress/admin/reorder.php" class="<?= $supply['reorder'] ? 'hot' : '' ?>">
                    <div class="row"><span class="db-icon warn"><i class="fa-solid fa-cart-flatbed"></i></span><strong><?= (int)$supply['reorder'] ?></strong></div><span>Needs reorder</span>
                </a>
            </div>
        </section>

        <!-- Inventory -->
        <section class="sx-card db-card db-a-inv">
            <div class="db-card-head"><div><h2>Inventory</h2><p>At selling price</p></div><span class="db-icon accent"><i class="fa-solid fa-warehouse"></i></span></div>
            <div class="db-big"><?= $peso($kpi['invValue']) ?><small><?= number_format($kpi['units']) ?> units on hand</small></div>
            <div class="db-chips">
                <a href="/sweepxpress/admin/inventory.php?filter=low" class="<?= $kpi['low'] ? 'warn' : '' ?>"><span>Low stock</span><strong><?= number_format($kpi['low']) ?></strong></a>
                <a href="/sweepxpress/admin/inventory.php?filter=out" class="<?= $kpi['out'] ? 'bad' : '' ?>"><span>Out of stock</span><strong><?= number_format($kpi['out']) ?></strong></a>
            </div>
        </section>

        <!-- Supply chain -->
        <section class="sx-card db-card db-a-supply">
            <div class="db-card-head"><div><h2>Supply chain</h2><p>Stock value at cost</p></div><span class="db-icon"><i class="fa-solid fa-truck-field"></i></span></div>
            <div>
                <div class="db-big"><?= $peso($supply['costValue']) ?></div>
                <?php $costPct = $supply['products'] ? (int)round($supply['costed'] / $supply['products'] * 100) : 0; ?>
                <div class="db-meter" style="margin-top: var(--sx-space-2)" role="img" aria-label="<?= $costPct ?>% of products have a cost price"><i style="width: <?= $costPct ?>%"></i></div>
                <div class="db-meter-label"><span><?= $supply['costed'] ?> of <?= $supply['products'] ?> products costed</span><span><?= $costPct ?>%</span></div>
            </div>
            <div class="db-chips">
                <a href="/sweepxpress/admin/purchase_orders.php?tab=sent"><span>Arriving this week</span><strong><?= number_format($supply['arriving']) ?></strong></a>
                <a href="/sweepxpress/admin/purchase_orders.php" class="<?= $supply['late'] ? 'bad' : '' ?>"><span>Late deliveries</span><strong><?= number_format($supply['late']) ?></strong></a>
            </div>
        </section>

        <!-- Charts -->
        <section class="sx-card db-card db-a-rev">
            <div class="db-card-head"><div><h2>Revenue</h2><p>Delivered &amp; completed orders, last 12 months</p></div><a href="/sweepxpress/admin/reports.php">Reports →</a></div>
            <div class="db-chart"><canvas id="revenueChart" aria-label="Monthly revenue chart" role="img"></canvas></div>
        </section>
        <section class="sx-card db-card db-a-status">
            <div class="db-card-head"><div><h2>Orders by status</h2><p>All time</p></div></div>
            <div class="db-chart"><canvas id="statusChart" aria-label="Orders by status chart" role="img"></canvas></div>
        </section>
        <section class="sx-card db-card db-a-flow">
            <div class="db-card-head"><div><h2>Stock in vs out</h2><p>Units moved per week, last 12 weeks</p></div><a href="/sweepxpress/admin/inventory.php">Inventory →</a></div>
            <div class="db-chart sm"><canvas id="flowChart" aria-label="Stock in versus out chart" role="img"></canvas></div>
        </section>
        <section class="sx-card db-card db-a-top">
            <div class="db-card-head"><div><h2>Top products</h2><p>Units sold, excluding cancelled orders</p></div><a href="/sweepxpress/admin/products.php">Products →</a></div>
            <div class="db-chart sm"><canvas id="topChart" aria-label="Top products chart" role="img"></canvas></div>
        </section>

        <!-- Recent orders -->
        <section class="sx-card db-card db-a-orders">
            <div class="db-card-head"><div><h2>Recent orders</h2><p>Latest 6</p></div><a href="/sweepxpress/admin/allorders.php">All orders →</a></div>
            <?php if ($recentOrders): ?>
                <div class="db-orders">
                    <?php foreach ($recentOrders as $ro):
                        [$label, $pill] = $statusMeta[$ro['status']] ?? [ucfirst($ro['status']), 'pending']; ?>
                        <a href="/sweepxpress/admin/order_details.php?id=<?= (int)$ro['id'] ?>" class="db-order">
                            <div class="db-order-top">
                                <?php if ($ro['thumb']): ?>
                                    <img src="<?= h($ro['thumb']) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                                <?php else: ?>
                                    <span class="av" aria-hidden="true"><?= h($initials($ro['name'])) ?></span>
                                <?php endif; ?>
                                <div>
                                    <strong><?= h($ro['name']) ?></strong>
                                    <small>#<?= (int)$ro['id'] ?><?php if ($ro['order_type'] === 'B2B' || $ro['status'] === 'Pending PO'): ?><span class="db-tag">PO</span><?php endif; ?> · <?= (int)$ro['units'] ?> item<?= (int)$ro['units'] === 1 ? '' : 's' ?> · <?= $ago($ro['created_at']) ?></small>
                                </div>
                            </div>
                            <div class="db-order-foot">
                                <b><?= $peso($ro['total'], 2) ?></b>
                                <span class="sx-pill sx-pill-<?= $pill ?>"><?= h($label) ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="db-ok" style="color: var(--sx-muted)"><i class="fa-solid fa-receipt"></i> No orders yet.</div>
            <?php endif; ?>
        </section>

        <!-- Customers -->
        <section class="sx-card db-card db-a-people">
            <div class="db-card-head"><div><h2>Customers</h2><p>Registered accounts</p></div><a href="/sweepxpress/admin/customer_management.php">All →</a></div>
            <div class="db-people">
                <div><span class="db-icon"><i class="fa-solid fa-user"></i></span><span>Customers</span><strong><?= (int)$segments['customer'] ?></strong></div>
                <div><span class="db-icon accent"><i class="fa-solid fa-building"></i></span><span>Business accounts</span><strong><?= (int)$segments['business'] ?></strong></div>
                <div><span class="db-icon warn"><i class="fa-solid fa-user-plus"></i></span><span>New in 30 days</span><strong><?= (int)$segments['new30'] ?></strong></div>
            </div>
        </section>

        <!-- Restock soon -->
        <section class="sx-card db-card db-a-restock">
            <div class="db-card-head"><div><h2>Restock soon</h2><p>Lowest stock compared with each reorder point</p></div><a href="/sweepxpress/admin/reorder.php">Reorder →</a></div>
            <?php if ($lowStockList): ?>
                <div class="db-products">
                    <?php foreach ($lowStockList as $p):
                        $qty = (int)$p['qty']; $rp = max(1, (int)$p['reorder_point']); ?>
                        <a href="/sweepxpress/admin/product_history.php?product_id=<?= (int)$p['id'] ?>" class="db-product">
                            <img src="<?= h($p['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                            <div>
                                <strong><?= h($p['name']) ?></strong>
                                <div class="db-meter" role="img" aria-label="<?= $qty ?> of reorder point <?= $rp ?>"><i class="<?= $qty === 0 ? 'out' : '' ?>" style="width: <?= min(100, (int)round($qty / $rp * 100)) ?>%"></i></div>
                                <small>Reorder at <?= (int)$p['reorder_point'] ?> · <?= h($p['category'] ?: 'Uncategorized') ?></small>
                                <span class="db-qty <?= $qty === 0 ? 'out' : 'low' ?>"><?= $qty === 0 ? 'Out of stock' : $qty . ' left' ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="db-ok"><i class="fa-solid fa-circle-check"></i> All products are well stocked.</div>
            <?php endif; ?>
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
