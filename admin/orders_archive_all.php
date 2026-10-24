<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// --- CONFIGURATION ---
$limit = 20;

// --- FILTER HANDLING ---
$filter_year = $_GET['year'] ?? '';
$filter_month = $_GET['month'] ?? '';
$current_page = $_GET['page'] ?? 1;

$current_page = max(1, (int)$current_page);

// Base WHERE clause and parameters
$where_clause = " WHERE 1=1";
$params = [];

if (!empty($filter_year) && is_numeric($filter_year)) {
    $where_clause .= " AND YEAR(created_at) = :year";
    $params[':year'] = $filter_year;
}

if (!empty($filter_month) && is_numeric($filter_month) && $filter_month >= 1 && $filter_month <= 12) {
    $where_clause .= " AND MONTH(created_at) = :month";
    $params[':month'] = $filter_month;
}

// --- PAGINATION LOGIC ---
try {
    $count_query = "SELECT COUNT(*) AS total FROM orders" . $where_clause;
    $stmt_count = $pdo->prepare($count_query);
    $stmt_count->execute($params);
    $total_records = $stmt_count->fetch()['total'];
    
    $total_pages = ceil($total_records / $limit);
    $offset = ($current_page - 1) * $limit;

} catch (PDOException $e) {
    $total_records = 0;
    $total_pages = 1;
    $offset = 0;
    $error_message = "Database Error: Could not count orders. " . $e->getMessage();
}

// --- ORDER FETCHING ---
$query = "SELECT * FROM orders" . $where_clause . " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

$fetch_params = $params;
$fetch_params[':limit'] = $limit;
$fetch_params[':offset'] = $offset;

try {
    $stmt = $pdo->prepare($query);
    foreach ($fetch_params as $key => &$val) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindParam($key, $val, PDO::PARAM_INT);
        } else {
            $stmt->bindParam($key, $val, PDO::PARAM_STR);
        }
    }
    
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $orders = [];
    $error_message = (isset($error_message) ? $error_message . " | " : "") . "Could not fetch paginated data. " . $e->getMessage();
}

// Get available years
$years = $pdo->query("SELECT DISTINCT YEAR(created_at) AS year FROM orders ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);

// Array of months
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Base query string
$base_query = "";
if (!empty($filter_year)) $base_query .= "&year=" . $filter_year;
if (!empty($filter_month)) $base_query .= "&month=" . $filter_month;

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

* {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

body {
    background: #f8f9fb;
}

.archive-container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.archive-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-archive {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-archive i {
    color: #06b6d4;
}

.header-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-pdf-archive {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    transition: all 0.2s;
}

.btn-pdf-archive:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
    color: #ffffff;
}

.btn-back-archive {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.2s;
}

.btn-back-archive:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* FILTER CARD */
.filter-card-archive {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    padding: 28px;
    margin-bottom: 24px;
}

.filter-title-archive {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-title-archive i {
    color: #06b6d4;
}

.filter-form-archive {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    align-items: end;
}

.form-group-archive {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-label-archive {
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
}

.form-select-archive {
    padding: 10px 14px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    transition: all 0.2s;
}

.form-select-archive:focus {
    outline: none;
    border-color: #06b6d4;
    box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
}

.filter-actions {
    display: flex;
    gap: 10px;
}

.btn-filter-apply {
    padding: 10px 24px;
    background: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-filter-apply:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
}

.btn-filter-reset {
    padding: 10px 24px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
}

.btn-filter-reset:hover {
    background: #f3f4f6;
    color: #0f172a;
}

/* STATS CARD */
.stats-card-archive {
    background: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
    border-radius: 16px;
    padding: 20px 28px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 24px rgba(6, 182, 212, 0.3);
    margin-bottom: 24px;
    color: #ffffff;
}

.stats-left-archive {
    display: flex;
    align-items: center;
    gap: 16px;
}

.stats-icon-archive {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.stats-content-archive h2 {
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px 0;
    opacity: 0.95;
}

.stats-content-archive .stats-value-archive {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1;
}

.stats-badge-archive {
    padding: 10px 20px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 999px;
    font-size: 0.875rem;
    font-weight: 600;
}

/* TABLE CARD */
.table-card-archive {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow: hidden;
}

.table-archive {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-archive thead {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
}

.table-archive thead th {
    padding: 18px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #164e63;
    border-bottom: 2px solid #06b6d4;
    white-space: nowrap;
}

.table-archive tbody td {
    padding: 18px 20px;
    font-size: 0.95rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.table-archive tbody tr:hover {
    background: #ecfeff;
}

.table-archive tbody tr:last-child td {
    border-bottom: none;
}

/* ORDER ID */
.order-id-archive {
    font-weight: 700;
    color: #0f172a;
}

/* STATUS BADGES */
.status-badge-archive {
    display: inline-flex;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
}

.status-badge-archive.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge-archive.preparing {
    background: #cffafe;
    color: #164e63;
}

.status-badge-archive.delivered {
    background: #d1fae5;
    color: #065f46;
}

.status-badge-archive.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

/* PRICE */
.price-archive {
    font-weight: 700;
    color: #10b981;
}

/* VIEW BUTTON */
.btn-view-archive {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #ffffff;
    color: #06b6d4;
    border: 1.5px solid #06b6d4;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-view-archive:hover {
    background: #06b6d4;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
}

/* PAGINATION */
.pagination-archive {
    display: flex;
    justify-content: center;
    gap: 8px;
    padding: 24px;
    list-style: none;
    margin: 0;
}

.pagination-archive a {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #0f172a;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    text-decoration: none;
    transition: all 0.2s;
}

.pagination-archive a:hover {
    background: #06b6d4;
    color: #ffffff;
    border-color: #06b6d4;
}

.pagination-archive a.active {
    background: #06b6d4;
    color: #ffffff;
    border-color: #06b6d4;
}

.pagination-archive .disabled {
    opacity: 0.5;
    pointer-events: none;
}

/* EMPTY STATE */
.empty-state-archive {
    text-align: center;
    padding: 80px 32px;
}

.empty-state-archive i {
    font-size: 4rem;
    color: #94a3b8;
    margin-bottom: 20px;
}

.empty-state-archive h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
}

.empty-state-archive p {
    font-size: 0.95rem;
    color: #94a3b8;
}

/* ALERT */
.alert-error-archive {
    background: #fee2e2;
    border: 1px solid #fecaca;
    color: #991b1b;
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .archive-container {
        padding: 24px 16px 40px;
    }
    
    .archive-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .btn-pdf-archive,
    .btn-back-archive {
        width: 100%;
        justify-content: center;
    }
    
    .filter-form-archive {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="archive-container">
    <!-- HEADER -->
    <div class="archive-header">
        <h1 class="page-title-archive">
            <i class="fas fa-archive"></i>
            All Orders Archive
        </h1>
        <div class="header-actions">
            <a href="generate_pdf.php?status=all<?= h($base_query) ?>" target="_blank" class="btn-pdf-archive">
                <i class="fas fa-file-pdf"></i>
                Download PDF Report
            </a>
            <a href="allorders.php" class="btn-back-archive">
                <i class="fas fa-arrow-left"></i>
                Back to Orders
            </a>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="alert-error-archive">
            <i class="fas fa-exclamation-circle"></i> <?= h($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- FILTER CARD -->
    <div class="filter-card-archive">
        <div class="filter-title-archive">
            <i class="fas fa-filter"></i>
            Filter Orders
        </div>
        <form method="GET" class="filter-form-archive">
            <input type="hidden" name="page" value="1">
            
            <div class="form-group-archive">
                <label class="form-label-archive">Year</label>
                <select name="year" class="form-select-archive">
                    <option value="">All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?= h($year) ?>" <?= ($filter_year == $year) ? 'selected' : '' ?>>
                            <?= h($year) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group-archive">
                <label class="form-label-archive">Month</label>
                <select name="month" class="form-select-archive">
                    <option value="">All Months</option>
                    <?php foreach ($months as $num => $name): ?>
                        <option value="<?= h($num) ?>" <?= ($filter_month == $num) ? 'selected' : '' ?>>
                            <?= h($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-filter-apply">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <a href="orders_archive_all.php" class="btn-filter-reset">Reset</a>
            </div>
        </form>
    </div>

    <!-- STATS -->
    <div class="stats-card-archive">
        <div class="stats-left-archive">
            <div class="stats-icon-archive">
                <i class="fas fa-box"></i>
            </div>
            <div class="stats-content-archive">
                <h2>Total Orders</h2>
                <span class="stats-value-archive"><?= number_format($total_records) ?></span>
            </div>
        </div>
        <div class="stats-badge-archive">
            Page <?= $current_page ?> of <?= $total_pages ?>
        </div>
    </div>

    <!-- TABLE -->
    <div class="table-card-archive">
        <?php if (empty($orders)): ?>
            <div class="empty-state-archive">
                <i class="fas fa-inbox"></i>
                <h3>No Orders Found</h3>
                <p>No orders match your filter criteria</p>
            </div>
        <?php else: ?>
            <table class="table-archive">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date/Time</th>
                        <th>Customer ID</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): 
                        $displayStatus = $order['status'];
                        $statusClass = match($displayStatus) {
                            'delivered' => 'delivered',
                            'shipped' => 'preparing',
                            'pending' => 'pending',
                            'cancelled', 'cancellation_requested' => 'cancelled',
                            default => 'pending'
                        };
                        
                        if ($displayStatus == 'shipped') {
                            $displayStatus = 'preparing';
                        }
                    ?>
                        <tr>
                            <td class="order-id-archive">#<?= h($order['id']) ?></td>
                            <td style="color: #64748b;"><?= date('M d, Y • h:i A', strtotime($order['created_at'])) ?></td>
                            <td style="color: #64748b;"><?= h($order['user_id']) ?></td>
                            <td>
                                <span class="status-badge-archive <?= $statusClass ?>">
                                    <?= ucfirst(str_replace('_', ' ', $displayStatus)) ?>
                                </span>
                            </td>
                            <td class="price-archive">₱<?= number_format($order['total'] ?? 0, 2) ?></td>
                            <td>
                                <a href="order_details.php?id=<?= h($order['id']) ?>" class="btn-view-archive">
                                    <i class="fas fa-eye"></i>
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- PAGINATION -->
    <?php if ($total_pages > 1): ?>
        <ul class="pagination-archive">
            <a href="orders_archive_all.php?page=<?= $current_page - 1 . $base_query ?>" 
               class="<?= ($current_page <= 1) ? 'disabled' : '' ?>">
                &laquo;
            </a>
            
            <?php 
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);

            if ($start_page > 1) {
                echo '<a href="orders_archive_all.php?page=1' . $base_query . '">1</a>';
                if ($start_page > 2) {
                    echo '<span style="padding: 0 8px;">...</span>';
                }
            }

            for ($p = $start_page; $p <= $end_page; $p++): 
            ?>
                <a href="orders_archive_all.php?page=<?= $p . $base_query ?>" 
                   class="<?= ($p == $current_page) ? 'active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
            
            <?php
            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                    echo '<span style="padding: 0 8px;">...</span>';
                }
                echo '<a href="orders_archive_all.php?page=' . $total_pages . $base_query . '">' . $total_pages . '</a>';
            }
            ?>

            <a href="orders_archive_all.php?page=<?= $current_page + 1 . $base_query ?>" 
               class="<?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                &raquo;
            </a>
        </ul>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
