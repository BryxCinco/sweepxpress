<?php
/**
 * INVENTORY MANAGEMENT SYSTEM (Full Integrated Version)
 * Updated with AI-Lite Check Button + Mobile Card View
 */

require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';

// =================================================================================
// 1. CSV EXPORT LOGIC
// =================================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_report_' . date('Y-m-d_H-i') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, "sep=,\n");
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Product Name', 'SKU', 'Unit Price', 'Current Stock', 'Capital Tied', 'Status']);
    try {
        $stmt = $pdo->query("SELECT p.id, p.name, p.sku, p.price, IFNULL(SUM(i.quantity),0) as real_stock FROM products p LEFT JOIN inventory i ON i.product_id = p.id GROUP BY p.id ORDER BY p.name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stock = (int)$row['real_stock'];
            $price = (float)$row['price'];
            $capital = $stock * $price;
            $status = ($stock == 0) ? 'Out of Stock' : (($stock <= 10) ? 'Low Stock' : 'In Stock');
            fputcsv($output, [$row['id'], $row['name'], $row['sku'], number_format($price, 2, '.', ''), $stock, number_format($capital, 2, '.', ''), $status]);
        }
    } catch (Exception $e) {}
    fclose($output);
    exit;
}

require_once __DIR__ . '/../includes/header.php';

// =================================================================================
// 2. DASHBOARD STATS (FIXED FOR ACCURATE COUNTING)
// =================================================================================
// FIX: Using a JOIN on a subquery to correctly aggregate stock and calculate stats.
$valStmt = $pdo->query("
    SELECT 
        SUM(product_stock.real_stock * p.price) as total_value,
        COUNT(CASE WHEN product_stock.real_stock > 0 AND product_stock.real_stock <= 10 THEN 1 END) as low_stock_count,
        COUNT(CASE WHEN product_stock.real_stock = 0 THEN 1 END) as out_of_stock_count
    FROM products p
    JOIN (
        SELECT p.id, p.price, IFNULL(SUM(i.quantity), 0) AS real_stock
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        GROUP BY p.id
    ) product_stock ON p.id = product_stock.id
");

$valData = $valStmt->fetch(PDO::FETCH_ASSOC);
$totalValue = $valData['total_value'] ?? 0;
$lowStockCount = $valData['low_stock_count'] ?? 0;
$outOfStockCount = $valData['out_of_stock_count'] ?? 0;

// =================================================================================
// 3. MAIN DATA LOGIC
// =================================================================================
$limit = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$params = [];
$whereSql = "";
if ($search) {
    $whereSql = "WHERE (p.name LIKE :s OR p.sku LIKE :s)";
    $params[':s'] = "%$search%";
}

$havingClause = "";
if ($filter === 'low') $havingClause = "HAVING real_stock > 0 AND real_stock <= 10";
elseif ($filter === 'out') $havingClause = "HAVING real_stock = 0";

$sql = "SELECT p.id, p.name, COALESCE(p.sku,'') AS sku, COALESCE(p.price,0) AS price, IFNULL(SUM(i.quantity),0) AS real_stock FROM products p LEFT JOIN inventory i ON i.product_id = p.id $whereSql GROUP BY p.id $havingClause ORDER BY p.name ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// The COUNT query needs to use the same logic including the HAVING clause
$countSql = "SELECT COUNT(*) FROM (SELECT p.id, IFNULL(SUM(i.quantity),0) as real_stock FROM products p LEFT JOIN inventory i ON i.product_id = p.id $whereSql GROUP BY p.id $havingClause) as subquery";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
$countStmt->execute();
$totalProducts = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalProducts / $limit));
?>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
* { 
    font-family: 'Inter', sans-serif; 
    box-sizing: border-box; 
}
body { 
    background: #f8f9fb; 
    color: #0f172a; 
    overflow-x: hidden; 
}
.inventory-container { max-width: 1600px; margin: 0 auto; padding: 40px 32px 64px; }

/* HEADER */
.inventory-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 20px; }
.page-title-inventory { font-size: 2rem; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 12px; }
.page-title-inventory i { color: #8b5cf6; }
.btn-back-inventory { padding: 12px 24px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; color: #0f172a; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
.btn-back-inventory:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }

/* STATS */
.stats-card-inventory { background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%); border-radius: 16px; padding: 24px 32px; display: flex; justify-content: space-between; align-items: center; color: #fff; margin-bottom: 32px; box-shadow: 0 10px 24px rgba(139,92,246,0.3); }
.stats-left-inventory { display: flex; gap: 40px; flex-wrap: wrap; }
.stat-item h2 { font-size: 0.9rem; font-weight: 600; text-transform: uppercase; opacity: 0.8; margin: 0 0 5px 0; }
.stats-count-inventory { font-size: 1.5rem; font-weight: 800; margin: 0; }
.stats-divider { width: 1px; background: rgba(255,255,255,0.3); height: 50px; }

/* TOOLBAR */
.toolbar-card-inventory { background: #fff; border-radius: 20px; border: 1px solid #e5e7eb; padding: 24px; margin-bottom: 24px; box-shadow: 0 10px 32px rgba(0,0,0,0.05); }
.toolbar-top-row { display: flex; justify-content: space-between; gap: 20px; margin-bottom: 24px; flex-wrap: wrap; }
.search-form-inventory { flex: 1; display: flex; gap: 10px; max-width: 600px; position: relative; }
.search-input-inventory { flex: 1; padding: 12px 16px 12px 48px; border: 1px solid #e5e7eb; border-radius: 12px; font-size: 0.95rem; background: #f9fafb; }
.search-input-inventory:focus { outline:none; border-color:#8b5cf6; background:#fff; box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
.search-icon-inventory { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #64748b; pointer-events: none; }
.btn-search-inventory { background: #0f172a; color: #fff; border: none; padding: 0 24px; border-radius: 12px; font-weight: 600; cursor: pointer; }
.btn-scan-camera { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 0 16px; border-radius: 12px; font-size: 1.2rem; cursor: pointer; color: #334155; display: flex; align-items: center; }
.btn-scan-camera:hover { color: #2563eb; border-color: #2563eb; }

/* EXPORT & IMPORT BUTTONS */
.btn-action-group { display: flex; gap: 10px; }
.btn-export, .btn-import { background: #fff; border: 1px solid #cbd5e1; padding: 12px 24px; border-radius: 12px; color: #475569; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 8px; transition:0.2s; }
.btn-export:hover, .btn-import:hover { border-color: #94a3b8; color: #0f172a; background: #f1f5f9; }

/* FILTERS */
.filter-tabs { display: flex; gap: 20px; border-bottom: 2px solid #f1f5f9; padding-bottom: 10px; margin-bottom: 24px; overflow-x: auto; }
.filter-tab { text-decoration: none; color: #64748b; font-weight: 600; padding: 8px 12px; display: flex; align-items: center; gap: 8px; border-radius: 8px; transition: 0.2s; }
.filter-tab:hover, .filter-tab.active { background: #f5f3ff; color: #7c3aed; }
.count-badge { background: #e2e8f0; color: #475569; font-size: 0.75rem; padding: 2px 8px; border-radius: 10px; }
.filter-tab.active .count-badge { background: #ddd6fe; color: #5b21b6; }

/* COLORFUL BUTTONS */
.actions-grid-inventory { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
.action-btn-inventory { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; text-decoration: none; transition: 0.2s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
.action-btn-inventory:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.08); }
.action-icon-inventory { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 12px; color: #fff; }

.green .action-icon-inventory { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 16px rgba(16,185,129,0.3); } .green:hover { border-color: #10b981; }
.orange .action-icon-inventory { background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 8px 16px rgba(245,158,11,0.3); } .orange:hover { border-color: #f59e0b; }
.purple .action-icon-inventory { background: linear-gradient(135deg, #8b5cf6, #7c3aed); box-shadow: 0 8px 16px rgba(139,92,246,0.3); } .purple:hover { border-color: #8b5cf6; }
.cyan .action-icon-inventory { background: linear-gradient(135deg, #06b6d4, #0891b2); box-shadow: 0 8px 16px rgba(6,182,212,0.3); } .cyan:hover { border-color: #06b6d4; }

/* TABLE */
.table-card-inventory { background: #fff; border-radius: 20px; border: 1px solid #e5e7eb; overflow: hidden; margin-bottom: 30px; }
.table-inventory { width: 100%; border-collapse: collapse; }
.table-inventory th { background: #f8fafc; padding: 18px 24px; text-align: left; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
.table-inventory td { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
.table-inventory tr:hover { background: #f8fafc; cursor: pointer; }
.status-pill { padding: 6px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
.pill-green { background: #dcfce7; color: #166534; } .pill-orange { background: #ffedd5; color: #9a3412; } .pill-red { background: #fee2e2; color: #991b1b; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.btn-qr { width: 36px; height: 36px; border: 1px solid #e2e8f0; background: #fff; border-radius: 8px; cursor: pointer; color: #64748b; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.btn-qr:hover { background: #f1f5f9; color: #0f172a; border-color: #94a3b8; }

#scanner-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); justify-content: center; align-items: center; }
#scanner-content { background: #fff; padding: 24px; border-radius: 16px; width: 90%; max-width: 450px; text-align: center; position: relative; }
#scanner-preview { width: 100%; height: 300px; background: #000; margin: 16px 0; border-radius: 8px; overflow: hidden; }
.btn-close-scanner { position: absolute; top: 16px; right: 16px; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }

.pagination { display: flex; justify-content: center; gap: 8px; padding: 24px; flex-wrap: wrap; } 
.pagination a { padding: 10px 16px; border: 1px solid #e5e7eb; border-radius: 8px; text-decoration: none; color: #0f172a; font-weight: 600; }
.pagination a.active { background: #0f172a; color: #fff; border-color: #0f172a; }
.empty-state-inventory { padding: 80px 20px; text-align: center; color: #94a3b8; }
.empty-state-inventory i { font-size: 4rem; margin-bottom: 24px; color: #e2e8f0; }

/* MOBILE: TABLE → CARD VIEW - UPDATED */
@media (max-width: 768px) {
    .inventory-container { padding: 24px 16px; }
    .inventory-header { flex-direction: column; align-items: flex-start; }
    .btn-back-inventory { width: 100%; justify-content: center; }
    .stats-card-inventory { flex-direction: column; align-items: flex-start; gap: 24px; }
    .stats-divider { display: none; }
    .toolbar-top-row { flex-direction: column; align-items: stretch; }
    .actions-grid-inventory { grid-template-columns: repeat(2, 1fr); }
    .btn-action-group { flex-direction: column; width: 100%; }
    .btn-export, .btn-import { width: 100%; justify-content: center; }

    /* Wrapper clean for cards */
    .table-card-inventory { border: none; background: transparent; overflow: visible; }
    .table-inventory { border-collapse: separate; border-spacing: 0 16px; }
    .table-inventory thead { display: none; }
    .table-inventory tbody,
    .table-inventory tr,
    .table-inventory td { display: block; width: 100%; }

    /* Card style per row */
    .table-inventory tr {
        background: #ffffff;
        border-radius: 16px;
        padding: 16px; 
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        border: 1px solid #e5e7eb;
        cursor: pointer;
        margin-bottom: 16px; 
    }

    .table-inventory tr:hover { background: #ffffff; }

    .table-inventory td {
        padding: 6px 0;
        border: none;
        text-align: right !important; 
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }

    /* General Data Label: Use the data-label attribute for the title */
    .table-inventory td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
        font-size: 0.8rem;
        text-transform: uppercase;
        text-align: left;
        flex-shrink: 0;
    }

    /* Product Name (2nd column): Make it the main title and remove label */
    .table-inventory td:nth-child(2) {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0f172a;
        margin-bottom: 8px;
        display: block; 
        text-align: left !important;
    }
    .table-inventory td:nth-child(2)::before {
        content: none; 
    }

    /* SKU (3rd column) & ID (1st column) smaller/subtle */
    .table-inventory td:nth-child(1),
    .table-inventory td:nth-child(3) {
        font-size: 0.85rem;
        color: #94a3b8;
    }
    
    /* Stock (5th column): Emphasize stock count */
    .table-inventory td:nth-child(5) { 
        font-size: 1.2rem;
        font-weight: 800;
        color: #8b5cf6;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9; 
        margin-top: 8px;
    }

    /* Status (7th column): Align pill to the right */
    .table-inventory td:nth-child(7) {
        justify-content: flex-end;
        padding: 4px 0;
    }
    .table-inventory td:nth-child(7) .status-pill {
        display: inline-flex;
    }
    .table-inventory td:nth-child(7)::before {
        content: none; 
    }

    /* QR Button (8th column) */
    .table-inventory td:nth-child(8) {
        display: block;
        text-align: right !important;
        padding: 8px 0 0;
    }
    .table-inventory td:nth-child(8)::before {
        content: none;
    }
    .btn-qr {
        width: 32px;
        height: 32px;
    }
}
</style>

<div class="inventory-container">
    <div class="inventory-header">
        <div>
            <h1 class="page-title-inventory"><i class="fas fa-boxes-stacked"></i> Inventory Management</h1>
            <p class="page-subtitle-inventory">Track stock levels, valuation, and print labels.</p>
        </div>
        <a href="dashboard.php" class="btn-back-inventory"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="stats-card-inventory">
        <div class="stats-left-inventory">
            <div class="stat-item">
                <h2>Total Products</h2>
                <div class="stats-count-inventory"><?= number_format($totalProducts) ?></div>
            </div>
            <div class="stats-divider"></div>
            <div class="stat-item">
                <h2>Total Valuation</h2>
                <div class="stats-count-inventory">₱<?= number_format($totalValue, 2) ?></div>
            </div>
            <div class="stats-divider"></div>
            <div class="stat-item">
                <h2>Needs Reorder</h2>
                <div class="stats-count-inventory" style="color:#fcd34d">
                    <?= number_format($lowStockCount + $outOfStockCount) ?>
                    <?php if($outOfStockCount > 0): ?>
                        <span style="font-size:0.75rem; color:#fee2e2">(<?= $outOfStockCount ?> Out)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div style="background:rgba(255,255,255,0.2); padding:8px 16px; border-radius:8px; font-weight:600">
            Page <?= $page ?> of <?= $totalPages ?>
        </div>
    </div>

    <div class="toolbar-card-inventory">
        <div class="toolbar-top-row">
            <form method="GET" class="search-form-inventory" id="searchForm">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                <i class="fas fa-search search-icon-inventory"></i>
                <input type="text" name="search" id="searchInput" class="search-input-inventory" placeholder="Search product name or SKU..." value="<?= htmlspecialchars($search) ?>">
                <button type="button" class="btn-scan-camera" onclick="openScanner()" title="Scan QR Code"><i class="fas fa-qrcode"></i></button>
                <button type="submit" class="btn-search-inventory">Search</button>
            </form>

            <div class="btn-action-group">
                <a href="cron_stock_alert.php" target="_blank" class="btn-import" style="border-color: #eab308; color: #b45309; background: #fefce8;" title="Check for low stock and send email">
                    <i class="fas fa-robot"></i> AI Check
                </a>

                <a href="import_csv.php" class="btn-import"><i class="fas fa-file-import"></i> Import CSV</a>
                <a href="inventory.php?action=export" class="btn-export"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        </div>

        <div class="filter-tabs">
            <a href="inventory.php?filter=all" class="filter-tab <?= $filter==='all'?'active':'' ?>">All Items</a>
            <a href="inventory.php?filter=low" class="filter-tab <?= $filter==='low'?'active':'' ?>">Low Stock <span class="count-badge"><?= $lowStockCount ?></span></a>
            <a href="inventory.php?filter=out" class="filter-tab <?= $filter==='out'?'active':'' ?>">Out of Stock <span class="count-badge"><?= $outOfStockCount ?></span></a>
        </div>

        <div class="actions-grid-inventory">
            <a href="stock_in.php" class="action-btn-inventory green">
                <div class="action-icon-inventory green"><i class="fas fa-arrow-down"></i></div>
                <span class="action-label-inventory">Stock In</span>
            </a>
            <a href="stock_out.php" class="action-btn-inventory orange">
                <div class="action-icon-inventory orange"><i class="fas fa-arrow-up"></i></div>
                <span class="action-label-inventory">Stock Out</span>
            </a>
            <a href="stock_adjust.php" class="action-btn-inventory purple">
                <div class="action-icon-inventory purple"><i class="fas fa-sliders-h"></i></div>
                <span class="action-label-inventory">Adjust Stock</span>
            </a>
            <a href="inventory_full_history.php" class="action-btn-inventory cyan">
                <div class="action-icon-inventory cyan"><i class="fas fa-history"></i></div>
                <span class="action-label-inventory">Full History</span>
            </a>
        </div>
    </div>

    <div class="table-card-inventory">
        <table class="table-inventory">
            <thead>
                <tr>
                    <th width="5%">ID</th>
                    <th width="25%">Product Name</th>
                    <th width="15%">SKU</th>
                    <th width="12%" style="text-align:right">Price</th>
                    <th width="10%" style="text-align:center">Stock</th>
                    <th width="15%" style="text-align:right">Capital Tied</th>
                    <th width="13%" style="text-align:center">Status</th>
                    <th width="5%" style="text-align:center">QR</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="8"><div class="empty-state-inventory"><i class="fas fa-search"></i><h3>No Products Found</h3></div></td></tr>
                <?php else: foreach ($products as $p):
                    $stock = (int)$p['real_stock'];
                    $capital = $stock * $p['price'];
                    $stClass = ($stock==0) ? 'pill-red' : (($stock<=10) ? 'pill-orange' : 'pill-green');
                    $stText = ($stock==0) ? 'Out of Stock' : (($stock<=10) ? 'Low Stock' : 'In Stock');
                ?>
                <tr onclick="window.location.href='product_history.php?product_id=<?= $p['id'] ?>'">
                    <td data-label="ID" style="color:#64748b;font-weight:600"><?= $p['id'] ?></td>
                    <td data-label="Product Name" style="font-weight:700;color:#0f172a"><?= htmlspecialchars($p['name']) ?></td>
                    <td data-label="SKU" style="font-family:monospace;color:#64748b"><?= htmlspecialchars($p['sku']) ?></td>
                    <td data-label="Price" style="text-align:right;font-weight:600">₱<?= number_format($p['price'], 2) ?></td>
                    <td data-label="Stock" style="text-align:center;font-weight:800;font-size:1.1rem"><?= $stock ?></td>
                    <td data-label="Capital Tied" style="text-align:right;color:#475569">₱<?= number_format($capital, 2) ?></td>
                    <td data-label="Status" style="text-align:center"><span class="status-pill <?= $stClass ?>"><span class="dot"></span><?= $stText ?></span></td>
                    <td data-label="QR" style="text-align:center" onclick="event.stopPropagation()">
                        <button type="button" class="btn-qr" onclick="printQR('<?= addslashes($p['name']) ?>', '<?= addslashes($p['name']) ?>')"><i class="fas fa-qrcode"></i></button>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i=1; $i<=$totalPages; $i++): $qs='?page='.$i.'&filter='.$filter.($search?'&search='.urlencode($search):''); ?>
                <a href="inventory.php<?= $qs ?>" class="<?= ($i==$page)?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<div id="scanner-modal">
    <div id="scanner-content">
        <button class="btn-close-scanner" onclick="closeScanner()">&times;</button>
        <h3><i class="fas fa-camera"></i> Scan Product QR</h3>
        <p style="color:#64748b;margin-bottom:10px">Point camera at a product label to search.</p>
        <div id="scanner-preview"></div>
    </div>
</div>

<script>
function printQR(data, name) {
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(data)}`;
    const win = window.open('', 'Print', 'width=400,height=500');
    win.document.write(`<html><body style="text-align:center;font-family:sans-serif;padding:40px;"><div style="border:3px solid black;border-radius:10px;padding:20px;display:inline-block;"><img src="${url}" width="150" height="150"><br><h2 style="margin:10px 0;">${name}</h2></div><br><br><button onclick="window.print()" style="padding:10px 20px;">Print Label</button></body></html>`);
    win.document.close();
}
let scanner = null;
function openScanner() {
    document.getElementById('scanner-modal').style.display = 'flex';
    if (!scanner) {
        scanner = new Html5QrcodeScanner("scanner-preview", { fps: 10, qrbox: 250 }, false); 
        scanner.render(onScanSuccess, onScanError);
    }
}
function closeScanner() {
    document.getElementById('scanner-modal').style.display = 'none';
    if(scanner) { 
        scanner.clear().catch(error => {
            console.error("Failed to clear scanner:", error);
        });
        scanner = null; 
    }
}
function onScanSuccess(decodedText) {
    document.getElementById('searchInput').value = decodedText;
    closeScanner();
    document.getElementById('searchForm').submit();
}
function onScanError(errorMessage) {
    // console.log(`Scanner Error: ${errorMessage}`);
}
window.onclick = function(e) { if(e.target == document.getElementById('scanner-modal')) closeScanner(); }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>