<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/header.php';

// ====================================================================
// 1. FAILSAFE SYNC (Keeps Deliveries in sync with Orders)
// ====================================================================
try {
    $stmtOrders = $pdo->query("SELECT id, user_id, customer_name, address, created_at FROM orders WHERE id NOT IN (SELECT order_id FROM deliveries)");
    $missingOrders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($missingOrders)) {
        $stmtInsert = $pdo->prepare("INSERT INTO deliveries (order_id, customer_name, address, status, created_at) VALUES (?, ?, ?, 'pending', ?)");
        $stmtUser   = $pdo->prepare("SELECT name, username FROM users WHERE id = ?");

        foreach ($missingOrders as $order) {
            $finalName = $order['customer_name'];
            if (empty($finalName) && !empty($order['user_id'])) {
                $stmtUser->execute([$order['user_id']]);
                $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if ($user) {
                    if (!empty($user['name'])) {
                        $finalName = $user['name'];
                    } elseif (!empty($user['username'])) {
                        $finalName = $user['username'];
                    }
                }
            }
            if (empty($finalName)) {
                $finalName = "Guest Customer #" . $order['id'];
            }
            $finalAddress = !empty($order['address']) ? $order['address'] : 'Unknown';
            $stmtInsert->execute([$order['id'], trim($finalName), $finalAddress, $order['created_at']]);
        }
    }
} catch (Exception $e) {
    error_log("Sync Error: " . $e->getMessage());
}

// ====================================================================
// 2. SEARCH & PAGINATION & FILTERS
// ====================================================================
$limit  = 15;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$filter_year   = $_GET['year'] ?? '';
$filter_month  = $_GET['month'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search_query  = $_GET['search'] ?? '';

$where_clauses = ["1=1"];
$params = [];

if (!empty($search_query)) {
    $where_clauses[] = "(d.customer_name LIKE ? OR d.order_id LIKE ? OR d.id LIKE ? OR o.po_number LIKE ?)";
    $term = "%$search_query%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}
if (!empty($filter_year)) {
    $where_clauses[] = "YEAR(d.created_at) = ?";
    $params[] = $filter_year;
}
if (!empty($filter_month)) {
    $where_clauses[] = "MONTH(d.created_at) = ?";
    $params[] = $filter_month;
}
if (!empty($filter_status)) {
    $db_search_status = ($filter_status === 'preparing') ? 'preparing' : $filter_status;
    $where_clauses[]  = "d.status = ?";
    $params[]         = $db_search_status;
}

$where_sql = implode(' AND ', $where_clauses);

// Metrics
$stmtMetrics = $pdo->query("
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN status IN ('pending', 'shipped') THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as risk_count,
        (SELECT SUM(total) FROM orders WHERE status != 'cancelled') as total_revenue
    FROM deliveries
");
$metrics = $stmtMetrics->fetch(PDO::FETCH_ASSOC);

// Pagination Count
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM deliveries d LEFT JOIN orders o ON d.order_id = o.id WHERE $where_sql");
$stmtCount->execute($params);
$total_rows  = $stmtCount->fetchColumn();
$total_pages = ceil($total_rows / $limit);

// MAIN QUERY
$sql = "
    SELECT 
        d.*, 
        d.customer_name as delivery_name,
        o.customer_name as order_name,
        o.po_number,
        o.payment_terms,
        o.total, 
        o.created_at as order_created, 
        o.status AS order_status
    FROM deliveries d
    LEFT JOIN orders o ON d.order_id = o.id
    WHERE $where_sql
    ORDER BY d.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');

:root {
    --primary: #2563eb; --primary-soft: #eff6ff;
    --success: #10b981; --warning: #f59e0b;
    --danger: #ef4444; --dark: #0f172a;
    --text-sub: #64748b; --bg: #f1f5f9;
    --card-bg: #ffffff; --border: #e2e8f0;
    --radius: 12px;
}
body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--dark); }

.dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
.hud-card { background: var(--card-bg); padding: 24px; border-radius: var(--radius); border: 1px solid var(--border); display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
.hud-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.hud-icon.blue { background: var(--primary-soft); color: var(--primary); }
.hud-icon.red { background: #fef2f2; color: var(--danger); }
.hud-icon.green { background: #ecfdf5; color: var(--success); }
.hud-info h4 { margin: 0; font-size: 0.9rem; color: var(--text-sub); font-weight: 500; }
.hud-info span { font-size: 1.5rem; font-weight: 800; display: block; margin-top: 4px; }

.action-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; background: var(--card-bg); padding: 15px 20px; border-radius: var(--radius); border: 1px solid var(--border); }
.filter-group { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; flex: 1; }
.form-select { padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; outline: none; background: #fff; cursor: pointer; }
.search-box { position: relative; flex: 1; min-width: 200px; }
.search-box input { width: 100%; padding: 10px 15px 10px 40px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.9rem; outline: none; }
.search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-sub); }

.table-container { background: var(--card-bg); border-radius: var(--radius); border: 1px solid var(--border); overflow: hidden; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05); }
.smart-table { width: 100%; border-collapse: collapse; }
.smart-table thead { background: #f8fafc; border-bottom: 1px solid var(--border); }
.smart-table th { padding: 16px; text-align: left; font-size: 0.75rem; text-transform: uppercase; color: var(--text-sub); font-weight: 700; letter-spacing: 0.05em; }
.smart-table td { padding: 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }

/* Desktop Actions column: right-aligned single row */
.smart-table td.actions-cell {
    white-space: nowrap;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 8px;
}
.smart-table td.actions-cell a {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

/* Status badges */
.status-badge {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.badge-pending   { background: #fefce8; color: #a16207; border: 1px solid #fde047; }
.badge-preparing { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.badge-delivered { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
.badge-cancelled { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

.badge-b2b { background: #7e22ce; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; display: inline-block; margin-right: 5px; }
.badge-terms { background: #f3e8ff; color: #7e22ce; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; display: inline-block; }
.customer-meta { display: flex; flex-direction: column; }
.customer-name { font-weight: 600; color: var(--dark); }

/* Mobile: 1 card per order, aligned labels & values */
@media (max-width: 768px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }

    .smart-table,
    .smart-table thead,
    .smart-table tbody,
    .smart-table th,
    .smart-table td,
    .smart-table tr {
        display: block;
        width: 100%;
    }

    .smart-table thead {
        display: none;
    }

    .smart-table tr {
        margin-bottom: 15px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: #ffffff;
        overflow: hidden;
    }

    .smart-table td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        text-align: left;
        padding: 10px 16px;
        border-bottom: 1px solid #f1f5f9;
        position: relative;
        padding-left: 16px;
    }

    .smart-table td:last-child {
        border-bottom: none;
    }

    .smart-table td::before {
        content: attr(data-label);
        flex: 0 0 40%;
        max-width: 40%;
        font-weight: 600;
        color: var(--text-sub);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .smart-table td > * {
        margin-left: auto;
    }

    .smart-table td > div,
    .smart-table td > span {
        flex: 1;
        text-align: right;
        font-size: 0.9rem;
    }

    .customer-meta {
        align-items: flex-end;
    }

    .customer-name {
        width: 100%;
        text-align: right;
    }

    .status-badge {
        justify-content: flex-end;
        width: 100%;
    }

    .smart-table td.actions-cell {
        justify-content: flex-end;
    }
}

.pagination {
    margin-top: 15px;
    display: flex;
    gap: 6px;
    justify-content: center;
    align-items: center;
}
.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 6px;
    border: 1px solid var(--border);
    color: var(--text-sub);
    text-decoration: none;
    font-size: 0.85rem;
}
.page-link.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.page-link.disabled {
    pointer-events: none;
    opacity: 0.5;
}
</style>

<div style="max-width: 1400px; margin: 0 auto; padding: 30px 20px;">
    
    <div style="margin-bottom: 30px;">
        <h1 style="font-weight: 800; margin-bottom: 5px;">Logistics Command Center</h1>
        <p style="color: var(--text-sub);">Real-time tracking and intelligent ETA.</p>
    </div>

    <div class="dashboard-grid">
        <div class="hud-card">
            <div class="hud-icon blue"><i class="fa-solid fa-box-open"></i></div>
            <div class="hud-info">
                <h4>Total Active</h4>
                <span><?php echo number_format($metrics['active_count'] ?? 0); ?></span>
            </div>
        </div>
        <div class="hud-card">
            <div class="hud-icon green"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="hud-info">
                <h4>Total Revenue</h4>
                <span>₱<?php echo number_format($metrics['total_revenue'] ?? 0, 0); ?></span>
            </div>
        </div>
        <div class="hud-card">
            <div class="hud-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="hud-info">
                <h4>Risk (Delayed >3 Days)</h4>
                <span><?php echo number_format($metrics['risk_count'] ?? 0); ?></span>
            </div>
        </div>
    </div>

    <div class="action-toolbar">
        <form method="get" class="filter-group" id="filterForm">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search customer, PO#, or ID...">
            </div>
            
            <?php if(!empty($search_query) || !empty($filter_status) || !empty($filter_year)): ?>
                <a href="deliveries.php" class="btn btn-sm btn-light" style="border:1px solid #ddd; color:#666;">❌ Clear</a>
            <?php endif; ?>
            
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="pending"   <?php if($filter_status=='pending')   echo 'selected'; ?>>Pending</option>
                <option value="preparing" <?php if($filter_status=='preparing') echo 'selected'; ?>>Preparing</option>
                <option value="delivered" <?php if($filter_status=='delivered') echo 'selected'; ?>>Delivered</option>
                <option value="completed" <?php if($filter_status=='completed') echo 'selected'; ?>>Completed</option>
                <option value="cancelled" <?php if($filter_status=='cancelled') echo 'selected'; ?>>Cancelled</option>
            </select>

            <select name="year" class="form-select" onchange="this.form.submit()">
                <option value="">All Years</option>
                <?php 
                $currentYear = date('Y');
                for($y = $currentYear; $y >= $currentYear - 2; $y--) {
                    $sel = ($filter_year == $y) ? 'selected' : '';
                    echo "<option value='$y' $sel>$y</option>";
                }
                ?>
            </select>
            <select name="month" class="form-select" onchange="this.form.submit()">
                <option value="">All Months</option>
                <?php 
                for($m=1; $m<=12; $m++) {
                    $mName = date('F', mktime(0,0,0,$m,1));
                    $sel   = ($filter_month == $m) ? 'selected' : '';
                    echo "<option value='$m' $sel>$mName</option>";
                }
                ?>
            </select>
            <button type="submit" style="display:none;"></button>
        </form>
        
        <button class="btn btn-dark" style="background:var(--dark); color:#fff; border-radius:8px; padding: 10px 20px;">
            <i class="fa-solid fa-file-export me-2"></i> Export
        </button>
    </div>

    <div class="table-container">
        <table class="smart-table" id="deliveryTable">
            <thead>
                <tr>
                    <th>Tracking</th>
                    <th>Customer & Terms</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($deliveries as $d): ?>
                <?php 
                    $created_date = new DateTime($d['order_created']);

                    $db_status = strtolower($d['status'] ?? '');
                    if ($db_status === '' || $db_status === '0') $db_status = 'pending';
                    $ui_status = ($db_status === 'shipped') ? 'preparing' : $db_status;

                    $badgeClass = match($ui_status) {
                        'pending'   => 'badge-pending',
                        'preparing' => 'badge-preparing',
                        'delivered' => 'badge-delivered',
                        'completed' => 'badge-delivered',
                        'cancelled' => 'badge-cancelled',
                        default     => 'badge-pending'
                    };

                    $statusIcon = match($ui_status) {
                        'pending'               => 'fa-clock',
                        'preparing'             => 'fa-box-open',
                        'delivered', 'completed'=> 'fa-check-circle',
                        'cancelled'             => 'fa-times-circle',
                        default                 => 'fa-circle-question'
                    };

                    $daysToAdd = 5;
                    $terms = strtolower($d['payment_terms'] ?? '');
                    if (strpos($terms, '30') !== false) { $daysToAdd = 30; }
                    elseif (strpos($terms, '45') !== false) { $daysToAdd = 45; }
                    elseif (strpos($terms, '60') !== false) { $daysToAdd = 60; }
                    elseif ($ui_status == 'preparing') { $daysToAdd = 3; }
                    if ($ui_status == 'delivered' || $ui_status == 'completed') $daysToAdd = 0;

                    $etaDate = clone $created_date;
                    $etaDate->modify("+$daysToAdd days");
                    $today = new DateTime();

                    $etaText  = $etaDate->format('M j');
                    $etaColor = "var(--text-sub)";

                    if ($ui_status != 'delivered' && $ui_status != 'completed' && $ui_status != 'cancelled') {
                        if ($today > $etaDate) {
                            $etaColor = "var(--danger)";
                            $etaText .= " (Delayed)";
                        } else {
                            $etaColor = "var(--success)";
                            $etaText .= " (On Time)";
                        }
                    } else {
                        $etaText = "Completed";
                    }

                    $poNumber     = $d['po_number'] ?? '';
                    $paymentTerms = $d['payment_terms'] ?? '';
                    $isB2B        = !empty($poNumber);
                    $displayName  = !empty($d['order_name']) ? $d['order_name'] : $d['delivery_name'];
                ?>
                <tr>
                    <td data-label="Tracking">
                        <div style="font-weight:700;">#<?php echo htmlspecialchars($d['id']); ?></div>
                        <div style="font-size:0.8rem; color:var(--text-sub);">
                            <?php echo $d['created_at'] ? date('M j, H:i', strtotime($d['created_at'])) : '-'; ?>
                        </div>
                    </td>
                    <td data-label="Customer & Terms">
                        <div class="customer-meta">
                            <span class="customer-name">
                                <?php if($isB2B): ?>
                                    <span class="badge-b2b">PO: <?php echo htmlspecialchars($poNumber); ?></span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($displayName); ?>
                            </span>
                            <span style="font-size:0.75rem; color:<?php echo $etaColor; ?>; font-weight:700; display:flex; align-items:center; gap:4px; margin-top:2px;">
                                <i class="fa-regular fa-calendar"></i> ETA: <?php echo $etaText; ?>
                            </span>
                            <?php if(!empty($paymentTerms)): ?>
                                <span style="margin-top:2px;">
                                    <span class="badge-terms"><?php echo htmlspecialchars($paymentTerms); ?></span>
                                </span>
                            <?php endif; ?>
                            <span style="font-size:0.8rem; color:var(--text-sub); margin-top:2px;">
                                Order #<?php echo htmlspecialchars($d['order_id']); ?>
                            </span>
                        </div>
                    </td>
                    <td data-label="Status">
                        <span class="status-badge <?php echo $badgeClass; ?>">
                            <i class="fa-solid <?php echo $statusIcon; ?>"></i>
                            <?php echo ucfirst($ui_status); ?>
                        </span>
                    </td>
                    <td data-label="Actions" class="actions-cell">
                        <a href="AdminReceipt.php?order_id=<?php echo $d['order_id']; ?>" 
                           style="color:#64748b; text-decoration:none; font-weight:600; font-size:0.9rem; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"
                           title="Print Admin Sales Receipt" target="_blank">
                            <i class="fa-solid fa-receipt"></i> Receipt
                        </a>
                        <a href="order_details_deliveries.php?id=<?php echo $d['order_id']; ?>" 
                           style="color:var(--primary); text-decoration:none; font-weight:600; font-size:0.9rem; padding: 6px 8px; border: 1px solid var(--primary); background: var(--primary-soft); border-radius: 6px;"
                           title="View Order Details">
                            <i class="fa-solid fa-eye"></i> View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if(empty($deliveries)): ?>
            <div style="padding:40px; text-align:center; color:var(--text-sub);">
                <i class="fa-solid fa-inbox" style="font-size:2rem; margin-bottom:10px; opacity:0.3;"></i>
                <p>No deliveries found.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <a href="?page=<?= max(1, $page-1) ?>&year=<?= $filter_year ?>&month=<?= $filter_month ?>&status=<?= $filter_status ?>&search=<?= urlencode($search_query) ?>" 
           class="page-link <?= ($page <= 1) ? 'disabled' : '' ?>">
           <i class="fa-solid fa-chevron-left"></i>
        </a>
        <?php for($i = 1; $i <= $total_pages; $i++): ?>
            <a href="?page=<?= $i ?>&year=<?= $filter_year ?>&month=<?= $filter_month ?>&status=<?= $filter_status ?>&search=<?= urlencode($search_query) ?>" 
               class="page-link <?= ($page == $i) ? 'active' : '' ?>">
               <?= $i ?>
            </a>
        <?php endfor; ?>
        <a href="?page=<?= min($total_pages, $page+1) ?>&year=<?= $filter_year ?>&month=<?= $filter_month ?>&status=<?= $filter_status ?>&search=<?= urlencode($search_query) ?>" 
           class="page-link <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
           <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>

<script>
function filterTable() {
    let input = document.getElementById("deliverySearch");
    if (!input) return;
    let filter = input.value.toUpperCase();
    let table = document.getElementById("deliveryTable");
    let tr = table.getElementsByTagName("tr");
    for (let i = 1; i < tr.length; i++) {
        let txtValue = tr[i].textContent || tr[i].innerText;
        tr[i].style.display = (txtValue.toUpperCase().indexOf(filter) > -1) ? "" : "none";
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
