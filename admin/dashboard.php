<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

$currentAdmin = $_SESSION['user'] ?? [];

// --- DB QUERIES (unchanged) ---
try {
    $totalSales    = $pdo->query("SELECT IFNULL(SUM(total),0) AS sales FROM orders")->fetch()['sales'];
    $totalOrders   = $pdo->query("SELECT COUNT(*) AS cnt FROM orders")->fetch()['cnt'];
    $totalProducts = $pdo->query("SELECT COUNT(*) AS cnt FROM products")->fetch()['cnt'];
    $totalUsers    = $pdo->query("SELECT COUNT(*) AS cnt FROM users")->fetch()['cnt'];

    $totalCancelledOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'cancelled'")->fetch()['cnt'];
    $aov              = $totalOrders > 0 ? $totalSales / $totalOrders : 0;
    $cancellationRate = $totalOrders > 0 ? ($totalCancelledOrders / $totalOrders) * 100 : 0;

    $salesData = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%b %Y') AS month, IFNULL(SUM(total), 0) AS monthly_total
        FROM orders
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
        ORDER BY created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $months = array_column($salesData, 'month');
    $totals = array_column($salesData, 'monthly_total');

    $topProducts = $pdo->query("
        SELECT p.name, SUM(o.quantity) AS qty
        FROM order_items o
        JOIN products p ON o.product_id = p.id
        GROUP BY p.id
        ORDER BY qty DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topUsers = $pdo->query("
        SELECT u.name, COUNT(o.id) AS orders
        FROM users u
        JOIN orders o ON o.user_id = u.id
        GROUP BY u.id
        ORDER BY orders DESC
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recentOrders = $pdo->query("
        SELECT o.id, u.name, o.total, o.status, o.created_at
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        ORDER BY o.created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $deliveredOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status IN ('delivered','completed')")->fetch()['cnt'];
    $pendingOrders   = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'pending'")->fetch()['cnt'];
    $preparingOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status IN ('preparing','shipped')")->fetch()['cnt'];
    $cancelledOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'cancelled'")->fetch()['cnt'];

    $adminCount    = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'admin'")->fetch()['cnt'];
    $customerCount = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'customer'")->fetch()['cnt'];
    $businessCount = $pdo->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'business'")->fetch()['cnt'];

    $oneMonthAgo   = date('Y-m-d H:i:s', strtotime('-30 days'));
    $newUsersCount = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE created_at >= ?");
    $newUsersCount->execute([$oneMonthAgo]);
    $newUsers = $newUsersCount->fetch()['cnt'];

    $lowStockProducts = $pdo->query("
        SELECT name, stock
        FROM products
        WHERE stock < 10
        ORDER BY stock ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $salesPerWeekData = $pdo->query("
        SELECT CONCAT('W', WEEK(created_at, 1)) AS week_label, SUM(total) AS weekly_total
        FROM orders
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
        GROUP BY YEAR(created_at), WEEK(created_at, 1)
        ORDER BY created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $weekLabels = array_column($salesPerWeekData, 'week_label');
    $weekTotals = array_column($salesPerWeekData, 'weekly_total');

    $lostSalesData = $pdo->query("
        SELECT DATE_FORMAT(created_at, '%b %Y') AS month, IFNULL(SUM(total), 0) AS lost_total
        FROM orders
        WHERE status = 'cancelled'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
        ORDER BY created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $lostSalesLabels = array_column($lostSalesData, 'month');
    $lostSalesTotals = array_column($lostSalesData, 'lost_total');

} catch (PDOException $e) {
    error_log("Dashboard DB Error: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>


<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

:root {
    --bg-page: #edf1f7;
    --bg-shell: #f6f8fc;
    --bg-card: #ffffff;
    --bg-soft: #f1f5f9;

    --border-soft: #d4dbe8;
    --text-main: #111827;
    --text-soft: #6b7280;
    --text-faint: #9ca3af;

    --blue-main: #2563eb;
    --blue-soft: #93c5fd;
    --teal-main: #0f766e;
    --teal-soft: #5eead4;
    --amber-main: #f59e0b;
    --red-main: #e11d48;
    --purple-main: #7c3aed;

    --radius-md: 14px;
    --radius-lg: 18px;

    --shadow-soft: 0 10px 30px rgba(15,23,42,0.08);
    --shadow-strong: 0 16px 48px rgba(15,23,42,0.12);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: radial-gradient(circle at top,#e0f2fe 0,#edf1f7 45%,#f9fafb 100%);
    color: var(--text-main);
}

/* SHELL */
.dash-shell {
    max-width: 1520px;
    margin: 0 auto;
    padding: 28px 16px 40px;
}

@media (min-width: 768px) {
    .dash-shell { padding: 28px 28px 40px; }
}

/* HEADER BAR */
/* HEADER BAR – COMPACT STRIP */
.hero-bar {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
    gap: 16px;
    padding: 12px 14px;
    border-radius: 16px;
    background: linear-gradient(135deg, #f9fafb 0%, #eef2ff 50%, #e0f2fe 100%);
    border: 1px solid rgba(148,163,184,0.35);
    box-shadow: 0 14px 32px rgba(15,23,42,0.16);
}

.hero-main {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.hero-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    background: rgba(15,23,42,0.06);
    color: #1d4ed8;
}

.hero-title {
    font-size: 1.2rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    color: #0f172a;
}

.hero-sub {
    font-size: 0.82rem;
    color: #64748b;
}

.hero-meta {
    justify-self: end;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
}

.hero-meta-top {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 4px 10px;
    border-radius: 999px;
    border: 1px solid rgba(148,163,184,0.45);
    background: rgba(255,255,255,0.9);
    font-size: 0.75rem;
    color: #475569;
}

.hero-time-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: #94a3b8;
    font-weight: 600;
}

.hero-time-value {
    font-size: 1.3rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    color: #0f172a;
}

.hero-time-date {
    font-size: 0.78rem;
    color: #64748b;
}

@media (max-width: 900px) {
    .hero-bar {
        grid-template-columns: 1fr;
        border-radius: 14px;
        padding: 12px 12px;
    }
    .hero-meta {
        align-items: flex-start;
        justify-self: start;
    }
}

/* SECTION HEAD */
/* Section header spacing */
.section-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-top: 22px;
    margin-bottom: 12px;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 2px;
}

.section-sub {
    font-size: 0.78rem;
    color: var(--text-soft);
}

/* KPI STRIP */
.kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(180px,1fr));
    gap: 12px;
    margin-bottom: 24px;
}

.kpi-card {
    position: relative;
    overflow: hidden;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg,#ffffff,#f3f6fb);
    border: 1px solid rgba(148,163,184,0.25);
    box-shadow: var(--shadow-soft);
    padding: 12px 12px 10px;
}

.kpi-inner {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
}

.kpi-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: var(--text-faint);
    font-weight: 600;
    margin-bottom: 4px;
}

.kpi-value {
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: -0.03em;
}

.kpi-foot {
    margin-top: 4px;
    font-size: 0.75rem;
    color: var(--text-soft);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 8px;
    border-radius: 999px;
    background: var(--bg-soft);
}

.kpi-icon {
    width: 30px;
    height: 30px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
}

/* COLOR VARIANTS */
.kpi-teal   .kpi-value { color: var(--teal-main); }
.kpi-teal   .kpi-icon  { background: var(--teal-main); }

.kpi-blue   .kpi-value { color: var(--blue-main); }
.kpi-blue   .kpi-icon  { background: var(--blue-main); }

.kpi-purple .kpi-value { color: var(--purple-main); }
.kpi-purple .kpi-icon  { background: var(--purple-main); }

.kpi-amber  .kpi-value { color: var(--amber-main); }
.kpi-amber  .kpi-icon  { background: var(--amber-main); }

.kpi-red    .kpi-value { color: var(--red-main); }
.kpi-red    .kpi-icon  { background: var(--red-main); }

.kpi-slate  .kpi-value { color: var(--text-main); }
.kpi-slate  .kpi-icon  { background: #6b7280; }

/* GRID LAYOUTS */
.row-flex {
    display: grid;
    grid-template-columns: minmax(0,2fr) minmax(0,1.1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.row-flex-3 {
    display: grid;
    grid-template-columns: repeat(3,minmax(0,1fr));
    gap: 16px;
    margin-bottom: 20px;
}

.bottom-grid {
    display: grid;
    grid-template-columns: minmax(0,1.3fr) minmax(0,1.7fr);
    gap: 16px;
}

/* Stack for mobile */
@media (max-width: 1100px) {
    .row-flex,
    .bottom-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 900px) {
    .row-flex-3 {
        grid-template-columns: repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 640px) {
    .row-flex,
    .row-flex-3,
    .bottom-grid {
        grid-template-columns: 1fr;
        gap: 12px;
    }
}

/* PANELS & TABLES */
.panel {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-soft);
    box-shadow: var(--shadow-soft);
    padding: 14px 14px 12px;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.panel-title {
    font-size: 0.95rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}

.panel-sub {
    font-size: 0.78rem;
    color: var(--text-soft);
}

.chip-soft {
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 0.73rem;
    background: var(--bg-soft);
    color: var(--text-soft);
}

/* Charts */
canvas {
    max-height: 260px;
}

@media (max-width: 640px) {
    canvas { max-height: 220px; }
}

/* TABLE */
.table-wrap {
    overflow-x: auto;
    margin-top: 4px;
}

.table-recent {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.86rem;
}

.table-recent thead th {
    text-align: left;
    padding: 8px 8px;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--text-faint);
    border-bottom: 1px solid var(--border-soft);
    white-space: nowrap;
}

.table-recent tbody td {
    padding: 8px 8px;
    border-bottom: 1px solid rgba(148,163,184,0.25);
}

.table-recent tbody tr:hover {
    background: #eef2ff;
}

.badge-id {
    display: inline-flex;
    padding: 3px 8px;
    border-radius: 999px;
    background: var(--bg-soft);
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--blue-main);
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
}

.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 999px;
}

.st-delivered { background:#dcfce7;color:#166534; }
.st-delivered .status-dot { background:#16a34a; }

.st-pending { background:#fef9c3;color:#92400e; }
.st-pending .status-dot { background:#eab308; }

.st-preparing { background:#e0f2fe;color:#075985; }
.st-preparing .status-dot { background:#0ea5e9; }

.st-cancelled { background:#fee2e2;color:#b91c1c; }
.st-cancelled .status-dot { background:#ef4444; }

/* LIST CARDS */
.list-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-soft);
    box-shadow: var(--shadow-soft);
    padding: 14px 14px 12px;
}

.list-head {
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.list-group {
    list-style: none;
    margin: 0;
    padding: 0;
}

.list-group li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 7px 0;
    border-bottom: 1px dashed rgba(148,163,184,0.5);
    font-size: 0.86rem;
}

.list-group li:last-child {
    border-bottom: none;
}

.list-badge {
    display: inline-flex;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--bg-soft);
    font-size: 0.78rem;
    font-weight: 600;
}

/* Hide segmentation + low stock + recent orders on very small screens only */
@media screen and (max-width: 640px) {
    .bottom-grid > .row.g-3.h-100,
    .bottom-grid > .panel {
        display: none;
    }
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .panel,
    .list-card {
        padding: 14px 12px;
    }
}
</style>

<div class="dash-shell">
    <div class="hero-bar">
    <div class="hero-main">
        <span class="hero-pill">
            <i class="fa-solid fa-shield-halved"></i>
            Admin control
        </span>
        <div class="hero-title">
            Growth &amp; Operations Center
        </div>
        <p class="hero-sub">
            Welcome back, <strong><?php echo htmlspecialchars($currentAdmin['name'] ?? 'Admin'); ?></strong>. Monitor sales, users, and risk in one focused view.
        </p>
        
    </div>
    <div class="hero-meta">
        <div class="hero-meta-top">
            <i class="fa-regular fa-id-badge"></i>
            Admin since <?php echo isset($currentAdmin['created_at']) ? htmlspecialchars(date('M Y', strtotime($currentAdmin['created_at']))) : '—'; ?>
        </div>
        <div style="text-align:right;">
            <div class="hero-time-label">Live snapshot</div>
            <div class="hero-time-value" id="current-time">--:--</div>
            <div class="hero-time-date" id="current-date">Loading…</div>
        </div>
    </div>
</div>

    <div class="section-head">
        <div>
            <div class="section-title">Financial overview</div>
            <div class="section-sub">Revenue performance and transaction volume.</div>
        </div>
    </div>
    <div class="kpi-row">
        <div class="kpi-card kpi-teal">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Total revenue</div>
                    <div class="kpi-value">₱<?php echo number_format($totalSales, 2); ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-infinity"></i> All-time sales
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-peso-sign"></i>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-blue">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Avg order value</div>
                    <div class="kpi-value">₱<?php echo number_format($aov, 2); ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-bullhorn"></i> Marketing efficiency
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-coins"></i>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-purple">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Total orders</div>
                    <div class="kpi-value"><?php echo number_format($totalOrders); ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-gauge-high"></i> Operational volume
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-cart-shopping"></i>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-amber">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Active SKUs</div>
                    <div class="kpi-value"><?php echo $totalProducts; ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-layer-group"></i> Product catalog
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-box-open"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="section-head">
        <div>
            <div class="section-title">Customers &amp; risk</div>
            <div class="section-sub">Acquisition, base size and cancellation signals.</div>
        </div>
    </div>
    <div class="kpi-row">
        <div class="kpi-card kpi-red">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Cancellation rate</div>
                    <div class="kpi-value"><?php echo number_format($cancellationRate, 2); ?>%</div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-fire"></i> Risk indicator
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-teal">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">New users (30d)</div>
                    <div class="kpi-value"><?php echo number_format($newUsers); ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-rocket"></i> Acquisition velocity
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-slate">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Total users</div>
                    <div class="kpi-value"><?php echo number_format($totalUsers); ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-earth-asia"></i> Market reach
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-red">
            <div class="kpi-inner">
                <div>
                    <div class="kpi-label">Low stock items</div>
                    <div class="kpi-value"><?php echo count($lowStockProducts); ?></div>
                    <div class="kpi-foot">
                        <i class="fa-solid fa-triangle-exclamation"></i> Supply risk
                    </div>
                </div>
                <div class="kpi-icon">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row-flex">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title" style="color:var(--blue-main);">
                        <i class="fa-solid fa-chart-area"></i> Monthly revenue
                    </div>
                    <div class="panel-sub">Trailing 6‑month view of realized revenue.</div>
                </div>
                <span class="chip-soft">
                    <i class="fa-regular fa-calendar"></i> Last 6 months
                </span>
            </div>
            <canvas id="salesChart"></canvas>
        </div>
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title" style="color:var(--red-main);">
                        <i class="fa-solid fa-circle-minus"></i> Lost sales
                    </div>
                    <div class="panel-sub">Cancelled order value by month.</div>
                </div>
            </div>
            <canvas id="lostSalesChart"></canvas>
        </div>
    </div>

    <div class="row-flex-3">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">
                        <i class="fa-solid fa-calendar-week" style="color:var(--blue-main);"></i> Weekly performance
                    </div>
                    <div class="panel-sub">8‑week sales velocity.</div>
                </div>
            </div>
            <canvas id="salesPerWeekChart"></canvas>
        </div>
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">
                        <i class="fa-solid fa-trophy" style="color:var(--teal-main);"></i> Top 5 products
                    </div>
                    <div class="panel-sub">Units sold by leading SKUs.</div>
                </div>
            </div>
            <canvas id="topProductsChart"></canvas>
        </div>
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">
                        <i class="fa-solid fa-filter" style="color:var(--amber-main);"></i> Orders funnel
                    </div>
                    <div class="panel-sub">Current order lifecycle distribution.</div>
                </div>
            </div>
            <canvas id="ordersChart"></canvas>
        </div>
    </div>

    <div class="bottom-grid">
        <div class="row g-3 h-100">
            <div class="col-12">
                <div class="list-card">
                    <div class="list-head">
                        <i class="fa-solid fa-user-group"></i> Customer segmentation
                    </div>
                    <ul class="list-group">
                        <li>
                            <span>Customer accounts</span>
                            <span class="list-badge"><?php echo $customerCount; ?></span>
                        </li>
                        <li>
                            <span>Business accounts</span>
                            <span class="list-badge"><?php echo $businessCount; ?></span>
                        </li>
                        <li>
                            <span>Top buyers tracked</span>
                            <span class="list-badge"><?php echo count($topUsers); ?></span>
                        </li>
                    </ul>
                    <p style="font-size:0.86rem;color:var(--text-soft);margin-top:12px;padding-top:10px;border-top:1px solid var(--border-soft);">
                        <strong style="color:var(--blue-main);"><?php echo $newUsers; ?></strong> new users joined in the last 30 days.
                    </p>
                </div>
            </div>
            <div class="col-12">
                <div class="list-card">
                    <div class="list-head">
                        <i class="fa-solid fa-triangle-exclamation"></i> Low stock inventory
                    </div>
                    <ul class="list-group">
                        <?php if (count($lowStockProducts) > 0): ?>
                            <?php foreach ($lowStockProducts as $product): ?>
                                <li>
                                    <span><?php echo htmlspecialchars($product['name']); ?></span>
                                    <span class="list-badge" style="background:rgba(248,113,113,0.12);color:var(--red-main);">
                                        <?php echo $product['stock']; ?> left
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li style="justify-content:center;color:var(--teal-main);">
                                All inventory is healthy!
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title">
                        <i class="fa-solid fa-clock-rotate-left"></i> Recent order activity
                    </div>
                    <div class="panel-sub">Most recent 5 transactions.</div>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table-recent">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentOrders as $ro): ?>
                        <tr>
                            <td><span class="badge-id">#<?php echo $ro['id']; ?></span></td>
                            <td><?php echo htmlspecialchars($ro['name'] ?? 'N/A'); ?></td>
                            <td style="font-weight:600;color:var(--teal-main);">
                                ₱<?php echo number_format($ro['total'], 2); ?>
                            </td>
                            <td>
                                <?php
                                $cls = 'st-pending';
                                $label = ucfirst($ro['status']);
                                if (in_array($ro['status'], ['delivered','completed'])) {
                                    $cls = 'st-delivered'; $label = 'Delivered';
                                } elseif ($ro['status'] === 'pending') {
                                    $cls = 'st-pending';   $label = 'Pending';
                                } elseif (in_array($ro['status'], ['preparing','shipped'])) {
                                    $cls = 'st-preparing'; $label = 'Preparing';
                                } elseif ($ro['status'] === 'cancelled') {
                                    $cls = 'st-cancelled'; $label = 'Cancelled';
                                }
                                ?>
                                <span class="status-pill <?php echo $cls; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo htmlspecialchars($label); ?>
                                </span>
                            </td>
                            <td><?php echo date("M d, H:i", strtotime($ro['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// live time
function updateDateTime() {
    const now = new Date();
    const t = document.getElementById('current-time');
    const d = document.getElementById('current-date');
    if (t) t.textContent = now.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',hour12:true});
    if (d) d.textContent = now.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'});
}
updateDateTime();
setInterval(updateDateTime,1000);

// Chart.js global styling
Chart.defaults.font.family = "'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI'";
Chart.defaults.color = '#6b7280';
Chart.defaults.plugins.legend.labels.boxWidth = 10;
Chart.defaults.plugins.legend.labels.boxHeight = 10;

function gradientFill(ctx, baseColor) {
    const g = ctx.createLinearGradient(0,0,0,220);
    g.addColorStop(0, baseColor);
    g.addColorStop(1, 'rgba(255,255,255,0)');
    return g;
}

// Monthly revenue
(function() {
    const ctx = document.getElementById('salesChart').getContext('2d');
    const grad = gradientFill(ctx,'rgba(37,99,235,0.35)');
    new Chart(ctx,{
        type:'line',
        data:{
            labels: <?php echo json_encode($months); ?>,
            datasets:[{
                data: <?php echo json_encode($totals); ?>,
                borderColor:'#2563eb',
                backgroundColor:grad,
                tension:0.45,
                fill:true,
                pointRadius:4,
                pointHoverRadius:6,
                pointBackgroundColor:'#1d4ed8',
                pointBorderColor:'#ffffff',
                pointBorderWidth:2,
                borderWidth:2.5
            }]
        },
        options:{
            plugins:{legend:{display:false}},
            responsive:true,
            maintainAspectRatio:false,
            scales:{
                y:{beginAtZero:true,grid:{color:'#e5e7eb'},ticks:{callback:v=>'₱'+v.toLocaleString()}},
                x:{grid:{display:false}}
            }
        }
    });
})();

// Lost sales
(function() {
    const ctx = document.getElementById('lostSalesChart').getContext('2d');
    new Chart(ctx,{
        type:'bar',
        data:{
            labels: <?php echo json_encode($lostSalesLabels); ?>,
            datasets:[{
                data: <?php echo json_encode($lostSalesTotals); ?>,
                backgroundColor:'#f97373',
                borderRadius:6,
                maxBarThickness:26
            }]
        },
        options:{
            plugins:{legend:{display:false}},
            responsive:true,
            maintainAspectRatio:false,
            scales:{
                y:{beginAtZero:true,grid:{color:'#e5e7eb'},ticks:{callback:v=>'₱'+v.toLocaleString()}},
                x:{grid:{display:false}}
            }
        }
    });
})();

// Orders funnel
(function() {
    const ctx = document.getElementById('ordersChart').getContext('2d');
    new Chart(ctx,{
        type:'doughnut',
        data:{
            labels:['Delivered','Pending','Preparing','Cancelled'],
            datasets:[{
                data:[<?php echo $deliveredOrders; ?>,<?php echo $pendingOrders; ?>,<?php echo $preparingOrders; ?>,<?php echo $cancelledOrders; ?>],
                backgroundColor:['#22c55e','#facc15','#38bdf8','#f97373'],
                borderWidth:1,
                borderColor:'#f9fafb',
                hoverOffset:8
            }]
        },
        options:{
            plugins:{legend:{position:'bottom'}},
            cutout:'68%',
            responsive:true,
            maintainAspectRatio:false
        }
    });
})();

// Top products
(function() {
    const ctx = document.getElementById('topProductsChart').getContext('2d');
    new Chart(ctx,{
        type:'bar',
        data:{
            labels: <?php echo json_encode(array_column($topProducts,'name')); ?>,
            datasets:[{
                data: <?php echo json_encode(array_column($topProducts,'qty')); ?>,
                backgroundColor:'#0f766e',
                borderRadius:8,
                barThickness:22
            }]
        },
        options:{
            indexAxis:'y',
            plugins:{legend:{display:false}},
            responsive:true,
            maintainAspectRatio:false,
            scales:{
                x:{grid:{color:'#e5e7eb'}},
                y:{grid:{display:false}}
            }
        }
    });
})();

// Weekly performance
(function() {
    const ctx = document.getElementById('salesPerWeekChart').getContext('2d');
    new Chart(ctx,{
        type:'bar',
        data:{
            labels: <?php echo json_encode($weekLabels); ?>,
            datasets:[{
                data: <?php echo json_encode($weekTotals); ?>,
                backgroundColor:'#60a5fa',
                borderRadius:8,
                maxBarThickness:26
            }]
        },
        options:{
            plugins:{legend:{display:false}},
            responsive:true,
            maintainAspectRatio:false,
            scales:{
                y:{beginAtZero:true,grid:{color:'#e5e7eb'}},
                x:{grid:{display:false}}
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>