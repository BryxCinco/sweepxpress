<?php
require_once __DIR__ . '/../includes/auth.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

require_once __DIR__ . '/../config.php';

$endDate = date("Y-m-d");
$startDate = date("Y-m-d", strtotime("-6 months"));

function get_status_badge($status) {
    $status = strtolower($status);
    return match($status) {
        'delivered', 'completed' => 'st-delivered',
        'preparing', 'shipped' => 'st-preparing',
        'pending' => 'st-pending',
        'cancelled' => 'st-cancelled',
        default => 'st-other'
    };
}

if (isset($_GET['start_date'], $_GET['end_date'])) {
    $newStartDate = $_GET['start_date'];
    $newEndDate = $_GET['end_date'];

    if (strtotime($newStartDate) && strtotime($newEndDate) && strtotime($newStartDate) <= strtotime($newEndDate)) {
        $startDate = $newStartDate;
        $endDate = $newEndDate;
    }
}

try {
    $stmt = $pdo->prepare("SELECT IFNULL(SUM(total),0) AS total_sales, COUNT(id) AS total_orders, IFNULL(AVG(total),0) AS avg_order, IFNULL(MAX(total),0) AS max_order FROM orders WHERE created_at BETWEEN :start AND :end");
    $stmt->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $salesOverview = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT COUNT(id) AS new_users FROM users WHERE created_at BETWEEN :start AND :end");
    $stmt->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $newUsers = $stmt->fetch(PDO::FETCH_ASSOC)['new_users'];
    
    $totalUsers = $pdo->query("SELECT COUNT(id) FROM users")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.name, SUM(oi.quantity) AS qty_sold, SUM(oi.quantity * oi.price) AS revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN orders o ON oi.order_id = o.id
        WHERE o.created_at BETWEEN :start AND :end
        GROUP BY p.id
        ORDER BY qty_sold DESC
        LIMIT 5
    ");
    $stmt->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT u.name, u.email, SUM(o.total) AS spent
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.created_at BETWEEN :start AND :end
        GROUP BY u.id
        ORDER BY spent DESC
        LIMIT 5
    ");
    $stmt->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS count
        FROM orders
        WHERE created_at BETWEEN :start AND :end
        GROUP BY status
    ");
    $stmt->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $orderStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, SUM(total) AS monthly_sales
        FROM orders
        WHERE created_at BETWEEN :start AND :end
        GROUP BY month
        ORDER BY month ASC
    ");
    $stmt->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $monthlySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $detailedSales = $pdo->prepare("
        SELECT o.id, u.name, o.total, o.status, o.created_at
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.created_at BETWEEN :start AND :end
        ORDER BY o.created_at DESC
    ");
    $detailedSales->execute(['start' => $startDate . ' 00:00:00', 'end' => $endDate . ' 23:59:59']);
    $salesRows = $detailedSales->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $salesOverview = ['total_sales' => 0, 'total_orders' => 0, 'avg_order' => 0, 'max_order' => 0];
    $newUsers = 0;
    $totalUsers = 0;
    $topProducts = $topCustomers = $orderStatus = $monthlySales = $salesRows = [];
    echo "<div class='alert alert-danger'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

require_once __DIR__ . '/../includes/header.php';
?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

body {
    background: radial-gradient(circle at top,#e0f2fe 0,#f4f5fb 50%,#f9fafb 100%);
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.dash-shell {
    max-width: 1600px;
    margin: 0 auto;
    padding: 32px 24px 48px;
}

/* HEADER */
.dash-header {
    margin-bottom: 24px;
}

.dash-header h1 {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin-bottom: 4px;
}

.dash-header p {
    font-size: 0.9rem;
    color: #64748b;
}

/* DATE FILTER */
.date-filter-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    padding: 20px;
    margin-bottom: 24px;
}

.date-filter-title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-filter-form {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 12px;
    align-items: end;
}

.date-input {
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #d4dbe8;
    font-size: 0.9rem;
    background: #fafbfc;
    transition: all 0.2s;
}

.date-input:focus {
    outline: none;
    border-color: #2563eb;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.date-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 4px;
}

.filter-btn {
    padding: 10px 24px;
    background: linear-gradient(135deg,#2563eb,#1d4ed8);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(37,99,235,0.3);
}

.filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(37,99,235,0.4);
}

/* KPI CARDS */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.kpi-card {
    background: linear-gradient(135deg, #ffffff, #f8fafc);
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    padding: 20px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.04);
    transition: all 0.2s;
}

.kpi-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.1);
}

.kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.kpi-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-weight: 700;
    color: #64748b;
}

.kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
}

.kpi-value {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.02em;
}

.kpi-green { color: #16a34a; }
.kpi-green .kpi-icon { background: rgba(16,185,129,0.1); color: #10b981; }

.kpi-blue { color: #2563eb; }
.kpi-blue .kpi-icon { background: rgba(37,99,235,0.1); color: #3b82f6; }

.kpi-amber { color: #f59e0b; }
.kpi-amber .kpi-icon { background: rgba(245,158,11,0.1); color: #f59e0b; }

.kpi-slate { color: #64748b; }
.kpi-slate .kpi-icon { background: rgba(100,116,139,0.1); color: #64748b; }

/* CHART SECTION */
.chart-grid {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    margin-bottom: 28px;
}

.chart-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    padding: 24px;
}

.chart-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.chart-subtitle {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 20px;
}

canvas {
    max-height: 320px;
}

/* TABLE SECTION */
.table-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 28px;
}

.table-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    padding: 24px;
}

.table-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.table-card-subtitle {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 16px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.data-table thead th {
    padding: 10px 12px;
    background: #f9fafb;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9ca3af;
    text-align: left;
}

.data-table tbody td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

/* STATUS BADGES */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
}

.st-delivered {
    background: rgba(16,185,129,0.1);
    color: #047857;
}
.st-delivered .status-dot { background: #10b981; }

.st-preparing {
    background: rgba(59,130,246,0.1);
    color: #1d4ed8;
}
.st-preparing .status-dot { background: #3b82f6; }

.st-pending {
    background: rgba(245,158,11,0.1);
    color: #b45309;
}
.st-pending .status-dot { background: #f59e0b; }

.st-cancelled {
    background: rgba(239,68,68,0.1);
    color: #b91c1c;
}
.st-cancelled .status-dot { background: #ef4444; }

.st-other {
    background: rgba(148,163,184,0.1);
    color: #475569;
}

/* DETAILED TABLE */
.detailed-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 8px 24px rgba(0,0,0,0.06);
    padding: 24px;
}

@media (max-width: 1100px) {
    .chart-grid,
    .table-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dash-shell {
        padding: 24px 16px;
    }
    
    .date-filter-form {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dash-shell">
    <!-- HEADER -->
    <div class="dash-header">
        <h1>Marketing & Sales Dashboard</h1>
        <p>Track performance, revenue trends, and customer insights.</p>
    </div>

    <!-- DATE FILTER -->
    <div class="date-filter-card">
        <div class="date-filter-title">
            <i class="fas fa-calendar-alt"></i>
            Report Date Range
        </div>
        <form method="GET" class="date-filter-form">
            <div>
                <div class="date-label">Start Date</div>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="date-input" required>
            </div>
            <div>
                <div class="date-label">End Date</div>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="date-input" required>
            </div>
            <button type="submit" class="filter-btn">
                <i class="fas fa-filter"></i>
                Apply Filter
            </button>
        </form>
    </div>

    <!-- KPI CARDS -->
    <div class="kpi-grid">
        <div class="kpi-card kpi-green">
            <div class="kpi-header">
                <div class="kpi-label">Total Sales</div>
                <div class="kpi-icon">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
            <div class="kpi-value">₱<?php echo number_format($salesOverview['total_sales'], 2); ?></div>
        </div>

        <div class="kpi-card kpi-blue">
            <div class="kpi-header">
                <div class="kpi-label">Total Orders</div>
                <div class="kpi-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
            <div class="kpi-value"><?php echo number_format($salesOverview['total_orders']); ?></div>
        </div>

        <div class="kpi-card kpi-amber">
            <div class="kpi-header">
                <div class="kpi-label">New Customers</div>
                <div class="kpi-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
            </div>
            <div class="kpi-value"><?php echo number_format($newUsers); ?></div>
        </div>

        <div class="kpi-card kpi-slate">
            <div class="kpi-header">
                <div class="kpi-label">Total Users</div>
                <div class="kpi-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="kpi-value"><?php echo number_format($totalUsers); ?></div>
        </div>
    </div>

    <!-- CHART SECTION -->
    <div class="chart-grid">
        <div class="chart-card">
            <div class="chart-title">Revenue Trend</div>
            <div class="chart-subtitle">Monthly sales performance over the selected period.</div>
            <canvas id="monthlySalesChart"></canvas>
        </div>

        <div class="chart-card">
            <div class="chart-title">Order Funnel</div>
            <div class="chart-subtitle">Breakdown by current order status.</div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th style="text-align:right;">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderStatus as $row): ?>
                            <tr>
                                <td>
                                    <span class="status-pill <?php echo get_status_badge($row['status']); ?>">
                                        <span class="status-dot"></span>
                                        <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                                    </span>
                                </td>
                                <td style="text-align:right;font-weight:700;"><?php echo $row['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orderStatus): ?>
                            <tr><td colspan="2" style="text-align:center;color:#9ca3af;">No orders found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TABLES SECTION -->
    <div class="table-grid">
        <div class="table-card">
            <div class="table-card-title">Top 5 Products</div>
            <div class="table-card-subtitle">Best-selling products by quantity sold.</div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:center;">Qty Sold</th>
                            <th style="text-align:right;">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $row): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td style="text-align:center;font-weight:700;"><?php echo $row['qty_sold']; ?></td>
                                <td style="text-align:right;font-weight:700;color:#16a34a;">₱<?php echo number_format($row['revenue'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topProducts): ?>
                            <tr><td colspan="3" style="text-align:center;color:#9ca3af;">No product data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card-title">High-Value Customers</div>
            <div class="table-card-subtitle">Top 5 customers by total spending.</div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th style="text-align:right;">Spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCustomers as $row): ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td style="text-align:right;font-weight:700;color:#f59e0b;">₱<?php echo number_format($row['spent'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$topCustomers): ?>
                            <tr><td colspan="3" style="text-align:center;color:#9ca3af;">No customer data available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- DETAILED SALES TABLE -->
    <div class="detailed-card">
        <div class="chart-title">Detailed Sales Deep Dive</div>
        <div class="chart-subtitle">Interactive table of all orders within the selected date range.</div>
        <div class="table-responsive">
            <table id="salesTable" class="data-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($salesRows as $row): ?>
                        <tr>
                            <td style="font-weight:600;">#<?php echo htmlspecialchars($row['id']); ?></td>
                            <td><?php echo htmlspecialchars($row['name'] ?? 'N/A'); ?></td>
                            <td style="font-weight:700;color:#16a34a;">₱<?php echo number_format($row['total'], 2); ?></td>
                            <td>
                                <span class="status-pill <?php echo get_status_badge($row['status']); ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo date("M d, Y", strtotime($row['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const monthlySalesData = {
    labels: <?php echo json_encode(array_map(fn($m) => date("M Y", strtotime($m)), array_column($monthlySales, 'month'))); ?>,
    datasets: [{
        label: 'Monthly Sales (₱)',
        data: <?php echo json_encode(array_column($monthlySales, 'monthly_sales')); ?>,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37,99,235,0.08)',
        pointBackgroundColor: '#2563eb',
        pointBorderColor: '#fff',
        pointHoverBackgroundColor: '#fff',
        pointHoverBorderColor: '#2563eb',
        fill: true,
        tension: 0.4,
        borderWidth: 3
    }]
};

const config = {
    type: 'line',
    data: monthlySalesData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '₱' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,0.04)' },
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    }
                }
            },
            x: { grid: { display: false } }
        }
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('monthlySalesChart');
    if (ctx) new Chart(ctx, config);
   
    $('#salesTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [[4, "desc"]]
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
