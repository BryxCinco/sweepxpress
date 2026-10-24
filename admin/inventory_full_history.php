<?php
/**
 * INVENTORY FULL HISTORY REPORT
 * Displays all stock transactions (In, Out, Adjustments) for all products.
 */

require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

$page_title = 'Full Stock Movement History';

// =================================================================================
// 1. DATA LOGIC & PAGINATION
// =================================================================================
$limit = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

$search_clause = "";
$bind_values = [];

if ($search) {
    // Search both products (p) and stock movements (s) tables
    $search_clause = " WHERE p.name LIKE :s OR p.sku LIKE :s OR s.remark LIKE :s ";
    $bind_values[':s'] = "%$search%";
}

// Fetch all stock movements with related details
$sql = "SELECT s.id, s.product_id, s.movement_type, s.quantity, s.remark, s.created_at, s.user_name,
              l.name AS location_name, p.name AS product_name, p.sku, p.price
        FROM stock_movements s
        LEFT JOIN locations l ON s.location_id = l.id
        LEFT JOIN products p ON s.product_id = p.id
        {$search_clause}
        ORDER BY s.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
if ($search) $stmt->bindValue(':s', $bind_values[':s']);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total count for pagination
$countSql = "SELECT COUNT(*) FROM stock_movements s LEFT JOIN products p ON s.product_id = p.id {$search_clause}";
$countStmt = $pdo->prepare($countSql);
if ($search) $countStmt->bindValue(':s', $bind_values[':s']);
$countStmt->execute();
$totalRecords = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $limit));
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

* {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    /* FIX 1: Ensure padding/border don't exceed element width */
    box-sizing: border-box;
}

body {
    background: #f8f9fb;
    /* FIX 2: Prevents the page from scrolling horizontally due to rogue elements */
    overflow-x: hidden; 
}

.history-container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-history {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-history i {
    color: #06b6d4;
}

.btn-back-history {
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

.btn-back-history:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* SEARCH CARD */
.search-card-history {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    padding: 20px 24px;
    margin-bottom: 24px;
}

.search-form-history {
    display: flex;
    gap: 12px;
}

.search-input-history {
    flex: 1;
    padding: 12px 16px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    transition: all 0.2s;
}

.search-input-history:focus {
    outline: none;
    border-color: #06b6d4;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
}

.btn-search-history {
    padding: 12px 32px;
    background: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
    color: #ffffff;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-search-history:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(6, 182, 212, 0.3);
}

/* STATS CARD */
.stats-card-history {
    background: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
    border-radius: 16px;
    padding: 20px 28px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 10px 24px rgba(6, 182, 212, 0.3);
    margin-bottom: 24px;
}

.stats-icon-history {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.5rem;
}

.stats-content-history h2 {
    font-size: 0.85rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px 0;
}

.stats-content-history .stats-value-history {
    font-size: 2rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1;
}

/* TABLE CARD */
.table-card-history {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow: hidden;
}

.table-history {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-history thead {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
}

.table-history thead th {
    padding: 18px 16px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #164e63;
    border-bottom: 2px solid #06b6d4;
    white-space: nowrap;
}

.table-history thead th.text-center {
    text-align: center;
}

.table-history tbody td {
    padding: 16px;
    font-size: 0.9rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.table-history tbody tr {
    transition: background 0.2s;
    cursor: pointer;
}

.table-history tbody tr:hover {
    background: #f0fdfa;
}

.table-history tbody tr:last-child td {
    border-bottom: none;
}

/* BADGES */
.badge-history {
    display: inline-flex;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.badge-history.in {
    background: #d1fae5;
    color: #065f46;
}

.badge-history.out {
    background: #fef3c7;
    color: #92400e;
}

.badge-history.adjust {
    background: #fee2e2;
    color: #991b1b;
}

/* PRODUCT NAME */
.product-name-history {
    font-weight: 700;
    color: #06b6d4;
}

/* PRICE */
.price-history {
    font-weight: 600;
    color: #10b981;
}

/* EMPTY STATE */
.empty-state-history {
    text-align: center;
    padding: 80px 32px;
}

.empty-state-history i {
    font-size: 4rem;
    color: #94a3b8;
    margin-bottom: 20px;
}

.empty-state-history p {
    font-size: 1rem;
    color: #64748b;
    margin: 0;
}

/* PAGINATION */
.pagination-history {
    display: flex;
    justify-content: center;
    gap: 8px;
    /* FIX 3: Added horizontal padding and flex-wrap */
    padding: 24px 16px; 
    list-style: none;
    margin: 0;
    flex-wrap: wrap; /* NEW: Forces list items to wrap to next line on small screens */
}

.pagination-history li a {
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

.pagination-history li a:hover {
    background: #06b6d4;
    color: #ffffff;
    border-color: #06b6d4;
}

.pagination-history li.active a {
    background: #06b6d4;
    color: #ffffff;
    border-color: #06b6d4;
}

/* ====================================================================
 * RESPONSIVE: TABLE → CARD VIEW FIX (OVERFLOW)
 * ==================================================================== */
@media (max-width: 768px) {
    .history-container {
        /* FIX 4: Use smaller padding on mobile */
        padding: 24px 16px 40px;
    }
    
    .history-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .btn-back-history {
        width: 100%;
        justify-content: center;
    }
    
    .search-form-history {
        flex-direction: column;
    }
    
    .btn-search-history {
        width: 100%;
        justify-content: center;
    }
    
    /* Card Transformation Logic */
    .table-card-history { 
        border: none; 
        background: transparent; 
        /* Tiyakin na ang table container ay hindi mag-overflow */
        overflow: hidden; 
    }
    .table-history { border-collapse: separate; border-spacing: 0 10px; }
    .table-history thead { display: none; }
    .table-history tbody,
    .table-history tr,
    .table-history td { display: block; width: 100%; }

    .table-history tr {
        background: #ffffff;
        border-radius: 12px;
        padding: 15px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
        margin-bottom: 12px; 
        cursor: pointer;
        min-width: 0;
        overflow: hidden; 
    }
    .table-history tbody tr:hover { background: #ffffff; }
    .table-history td { 
        padding: 6px 0; 
        border: none; 
        text-align: right; 
        font-size: 0.9rem;
        word-wrap: break-word;
        max-width: 100%;
        min-width: 0;
    }
    .table-history td::before {
        content: attr(data-label);
        float: left;
        font-weight: 600;
        color: #64748b;
        font-size: 0.8rem;
        text-transform: uppercase;
        margin-right: 10px;
    }

    /* Column Specific Styling (to make it look like a title) */
    .table-history td:nth-child(2) { /* Product Name */
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 8px;
        text-align: left;
    }
    .table-history td:nth-child(2)::before {
        content: ""; 
        float: none;
    }
    .table-history td:nth-child(5) { /* Type Badge (Center alignment) */
        text-align: center;
    }
    .table-history td:nth-child(5)::before {
        float: left;
        text-align: left;
    }
    .table-history td:nth-child(6) { /* Quantity */
        font-size: 1.2rem;
        font-weight: 800;
    }
}
</style>

<div class="history-container">
    <div class="history-header">
        <h1 class="page-title-history">
            <i class="fas fa-history"></i>
            Stock Movement History
        </h1>
        <a href="inventory.php" class="btn-back-history">
            <i class="fas fa-arrow-left"></i>
            Back to Inventory
        </a>
    </div>

    <div class="search-card-history">
        <form method="GET" class="search-form-history">
            <input type="text" name="search" class="search-input-history" placeholder="Search product name, SKU or remark..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn-search-history" type="submit">
                <i class="fas fa-search"></i>
                Search
            </button>
        </form>
    </div>

    <div class="stats-card-history">
        <div class="stats-icon-history">
            <i class="fas fa-boxes"></i>
        </div>
        <div class="stats-content-history">
            <h2>Total Movement Records</h2>
            <span class="stats-value-history"><?= number_format($totalRecords) ?></span>
        </div>
    </div>

    <div class="table-card-history">
        <?php if (!$rows): ?>
            <div class="empty-state-history">
                <i class="fas fa-inbox"></i>
                <p>No stock movement history found</p>
            </div>
        <?php else: ?>
            <table class="table-history">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Product Name</th>
                        <th class="text-center">SKU</th>
                        <th>Price</th>
                        <th class="text-center">Type</th>
                        <th class="text-center">Quantity</th>
                        <th>Location</th>
                        <th>User</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr onclick="window.location.href='product_history.php?product_id=<?= $r['product_id'] ?>'">
                        <td data-label="Date/Time" style="color: #64748b; font-size: 0.85rem;"><?= date('M d, Y • h:i A', strtotime($r['created_at'])) ?></td>
                        <td data-label="Product" class="product-name-history"><?= htmlspecialchars($r['product_name']) ?></td>
                        <td data-label="SKU" class="text-center" style="color: #64748b;"><?= htmlspecialchars($r['sku']) ?></td>
                        <td data-label="Price" class="price-history">₱<?= number_format($r['price'], 2) ?></td>
                        <td data-label="Type" class="text-center">
                            <span class="badge-history <?= strtolower($r['movement_type']) == 'in' ? 'in' : (strtolower($r['movement_type']) == 'out' ? 'out' : 'adjust') ?>">
                                <?= $r['movement_type'] ?>
                            </span>
                        </td>
                        <td data-label="Quantity" class="text-center" style="font-weight: 600;"><?= $r['quantity'] ?></td>
                        <td data-label="Location" style="color: #64748b;"><?= htmlspecialchars($r['location_name'] ?: 'N/A') ?></td>
                        <td data-label="User" style="color: #64748b; font-size: 0.85rem;">
                            <?php 
                            $display_name = $r['user_name'];
                            
                            if (isset($_SESSION['user_name']) && strcasecmp($display_name, 'admin') === 0) {
                                $display_name = $_SESSION['user_name'];
                            }
                            
                            echo htmlspecialchars($display_name ?: 'System/Unknown'); 
                            ?>
                        </td>
                        <td data-label="Remark" style="color: #475569; font-size: 0.85rem;"><?= htmlspecialchars($r['remark']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
            <ul class="pagination-history">
                <?php for ($i=1; $i<=$totalPages; $i++): ?>
                    <li class="<?= $i==$page?'active':'' ?>">
                        <a href="?page=<?= $i ?><?= $search?'&search='.urlencode($search):'' ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>