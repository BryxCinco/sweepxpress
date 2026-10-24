<?php
require_once __DIR__ . '/../includes/auth.php';
// Check if the user is an admin before proceeding
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

$product_id = (int)($_GET['product_id'] ?? 0);

// Helper function for quick redirects
function redirect_to_inventory() {
    header("Location: inventory.php");
    exit;
}

if (!$product_id) redirect_to_inventory();

// --- 1. Fetch Product Details, Current Stock, AND IMAGE PATH ---
$productStmt = $pdo->prepare("
    SELECT 
        p.id, 
        p.name, 
        COALESCE(p.sku, '') AS sku, 
        COALESCE(p.image_path, '') AS image_path, -- ADDED: Fetch the image path
        IFNULL(SUM(i.quantity), 0) AS current_stock
    FROM 
        products p
    LEFT JOIN 
        inventory i ON i.product_id = p.id
    WHERE 
        p.id = :id
    GROUP BY 
        p.id
");
$productStmt->execute([':id' => $product_id]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) redirect_to_inventory();

$page_title = 'Inventory History for ' . htmlspecialchars($product['name']);
$current_stock = (int)$product['current_stock'];


// --- 2. Fetch Product Inventory History (All movements) ---
// Fetch them from OLDEST to NEWEST to calculate the running balance sequentially.
$historySql = "SELECT sm.movement_type, sm.quantity, sm.remark, sm.created_at, sm.user_name, l.name as location_name
               FROM stock_movements sm
               JOIN locations l ON l.id = sm.location_id
               WHERE sm.product_id = :id
               ORDER BY sm.created_at ASC, sm.id ASC"; 
$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute([':id' => $product_id]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);


// --- 3. Calculate Running Balance ---
$running_balance = $current_stock;

// Iterate backwards to calculate the stock *before* the transaction occurred.
for ($i = count($history) - 1; $i >= 0; $i--) {
    $move = $history[$i];
    $quantity = (int)$move['quantity'];
    $delta = 0; 

    // Determine the delta based on the movement type (using logic from stock_adjust.php)
    if ($move['movement_type'] === 'IN') {
        $delta = $quantity;
    } elseif ($move['movement_type'] === 'OUT') {
        $delta = -$quantity;
    } elseif ($move['movement_type'] === 'ADJUST') {
        if (strpos($move['remark'], '[ADD]') !== false) {
             $delta = $quantity;
        } elseif (strpos($move['remark'], '[DEDUCT]') !== false) {
             $delta = -$quantity;
        } else {
             $delta = 0;
        }
    }

    // Current $running_balance is the stock *after* this movement.
    $history[$i]['stock_after'] = $running_balance;
    
    // Calculate the stock *before* this movement
    $running_balance -= $delta;
    $history[$i]['stock_before'] = $running_balance;
}

// Reverse the array for display (Newest first)
$history = array_reverse($history);

$current_stock_display = $current_stock; 
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

/* Main Container */
.inventory-container {
    max-width: 1600px; 
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER - Combining the product info and back button */
.inventory-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.product-info-history {
    display: flex;
    align-items: center;
    gap: 20px;
    flex: 1;
    min-width: 0;
}

.product-image-container img {
    max-width: 100px; 
    height: 100px;
    object-fit: cover;
    border: 3px solid #ede9fe; 
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.product-details-history h1 {
    font-size: 1.8rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    margin: 0 0 4px 0;
    line-height: 1.2;
}

.product-details-history p {
    font-size: 0.95rem;
    color: #64748b;
    margin: 0;
}

.product-details-history span.stock-value {
    font-weight: 700;
    color: #8b5cf6; 
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

/* TABLE CARD (Matched inventory.php style) */
.table-card-history {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow: hidden;
}

.table-header-history {
    padding: 20px 32px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header-history h3 {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-header-history .badge-records {
    padding: 6px 14px;
    background: #8b5cf6; 
    color: #ffffff;
    border-radius: 999px;
    font-size: 0.9rem;
    font-weight: 600;
}

.table-history {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-history thead {
    background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); 
}

.table-history thead th {
    padding: 18px 20px; 
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #5b21b6; 
    border-bottom: 2px solid #8b5cf6; 
    white-space: nowrap;
}

.table-history thead th.text-center {
    text-align: center;
}

.table-history tbody td {
    padding: 18px 20px; 
    font-size: 0.95rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.table-history tbody tr {
    transition: background 0.2s;
}

.table-history tbody tr:hover {
    background: #faf5ff; 
}

.table-history tbody tr:last-child td {
    border-bottom: none;
}

/* BADGES (Movement Type) */
.badge-history {
    display: inline-flex;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
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
    background: #ede9fe; 
    color: #5b21b6; 
}

/* QUANTITY CHANGE */
.qty-change-in {
    color: #059669; 
    font-weight: 700;
}

.qty-change-out {
    color: #ef4444; 
    font-weight: 700;
}

/* RUNNING STOCK */
.stock-after {
    font-weight: 700;
    color: #8b5cf6; 
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

/* ======================================= */
/* MOBILE CARD VIEW START */
/* ======================================= */
@media (max-width: 992px) { /* Shifted breakpoint to 992px for better tablet support */
    .inventory-container {
        padding: 24px 16px 40px;
    }
    
    .inventory-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .product-info-history {
        flex-direction: row; /* Keep image and text side-by-side on small screens */
        align-items: center;
        gap: 15px;
        width: 100%;
    }

    .product-details-history h1 {
        font-size: 1.5rem;
    }

    .btn-back-history {
        width: 100%;
        justify-content: center;
        margin-top: 15px;
    }

    /* Card View Transformation */
    .table-card-history {
        border: none;
        background: transparent;
        box-shadow: none;
        overflow: visible;
    }
    
    .table-header-history {
        padding: 0;
        margin-bottom: 15px;
        background: transparent;
        border-bottom: none;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    .table-header-history h3 {
        font-size: 1.3rem;
    }

    .table-history {
        border-spacing: 0 16px; /* Spacing between cards */
        border-collapse: separate;
    }
    .table-history thead {
        display: none; /* Hide header row */
    }
    .table-history tbody,
    .table-history tr,
    .table-history td {
        display: block;
        width: 100%;
    }
    
    /* Individual Card Style */
    .table-history tr {
        background: #ffffff;
        border-radius: 12px;
        padding: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        border: 1px solid #e5e7eb;
        margin-bottom: 16px; 
    }

    .table-history tbody td {
        padding: 8px 0;
        border: none;
        text-align: right !important;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.95rem;
        word-break: break-word;
    }

    /* Label for Data */
    .table-history td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
        font-size: 0.8rem;
        text-transform: uppercase;
        text-align: left;
        flex-shrink: 0;
    }

    /* Movement Type (1st column) - Primary display */
    .table-history td:nth-child(1) {
        display: block; /* Take full width */
        text-align: left !important;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 12px;
        margin-bottom: 5px;
        font-size: 1rem;
    }
    .table-history td:nth-child(1)::before {
        content: none;
    }

    /* Qty Change (2nd column) - Highlighted */
    .table-history td:nth-child(2) {
        font-size: 1.1rem;
        font-weight: 800;
        color: #0f172a; /* Reset color here, specific change colors still apply */
        padding-top: 10px;
        order: -1; /* Move to the top of the card */
    }
    .table-history td:nth-child(2)::before {
        content: 'Qty Change:';
        color: #0f172a;
    }
    
    /* Running Stock (3rd and 4th columns) */
    .table-history td:nth-child(3),
    .table-history td:nth-child(4) {
        font-size: 1rem;
    }
    
    /* Timestamp (7th column) - Smaller text */
    .table-history td:nth-child(7) {
        font-size: 0.85rem;
    }
    
    /* User (8th column) - Smaller text */
    .table-history td:nth-child(8) {
        font-size: 0.85rem;
    }
}
/* ======================================= */
/* MOBILE CARD VIEW END */
/* ======================================= */
</style>

<div class="inventory-container">
    
    <div class="inventory-header">
        
        <div class="product-info-history">
            <div class="product-image-container flex-shrink-0">
                <?php
                // Check if a specific image path is set, otherwise use a default placeholder
                // NOTE: Update the placeholder path below to a valid image file.
                $imagePath = !empty($product['image_path']) 
                    ? htmlspecialchars($product['image_path']) 
                    : '/path/to/your/default_placeholder.jpg'; // <-- UPDATE THIS PATH
                ?>
                <img src="<?php echo $imagePath; ?>" 
                    alt="<?php echo htmlspecialchars($product['name']); ?> Image" 
                    class="" 
                    style="">
            </div>

            <div class="product-details-history">
                <h1>
                    <?php echo htmlspecialchars($product['name']); ?>
                </h1>
                <p>
                    SKU: <?php echo htmlspecialchars($product['sku'] ?: 'N/A'); ?>
                </p>
                <p>
                    Current Stock: <span class="stock-value"><?php echo $current_stock_display; ?></span>
                </p>
            </div>
        </div>

        <a href="inventory.php" class="btn-back-history">
            <i class="fas fa-arrow-left"></i>
            Back to Inventory
        </a>
    </div>

    <div class="table-card-history">
        <div class="table-header-history">
            <h3>
                <i class="fas fa-history" style="color:#8b5cf6;"></i>
                Stock Movements History 
            </h3>
            <span class="badge-records"><?php echo count($history); ?> records</span>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table-history">
                    <thead>
                        <tr>
                            <th scope="col" class="text-center">Type</th>
                            <th scope="col" class="text-center">Qty Change</th>
                            <th scope="col" class="text-center">Stock Before</th>
                            <th scope="col" class="text-center">Stock After</th>
                            <th scope="col">Location</th>
                            <th scope="col">Remark</th>
                            <th scope="col">Timestamp</th>
                            <th scope="col">User</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="8" class="empty-state-history">
                            <i class="fas fa-box-open"></i>
                            <p>No movement history found for this product.</p>
                        </td></tr>
                    <?php else: foreach ($history as $move): 
                        // Determine visual classes for the Quantity Change column
                        $qty_change = $move['stock_after'] - $move['stock_before'];
                        $qty_change_class = '';
                        $qty_change_prefix = '';
                        
                        if ($qty_change > 0) {
                            $qty_change_class = 'qty-change-in';
                            $qty_change_prefix = '+';
                        } elseif ($qty_change < 0) {
                            $qty_change_class = 'qty-change-out';
                        } else {
                            $qty_change_class = 'text-secondary';
                        }

                        // Determine movement badge class
                        $movement_badge_class = strtolower($move['movement_type']) == 'in' ? 'in' : (strtolower($move['movement_type']) == 'out' ? 'out' : 'adjust');
                        
                        // Formatted timestamp
                        $formatted_timestamp = date('M d, Y • h:i A', strtotime($move['created_at']));

                        // Define all data labels for mobile view
                        $labels = [
                            'Type', 'Qty Change', 'Stock Before', 'Stock After', 
                            'Location', 'Remark', 'Timestamp', 'User'
                        ];

                        // Data values
                        $data_cells = [
                            '<span class="badge-history ' . $movement_badge_class . '">' . htmlspecialchars($move['movement_type']) . '</span>',
                            '<span class="' . $qty_change_class . '">' . $qty_change_prefix . abs($qty_change) . '</span>',
                            '<span class="text-muted">' . $move['stock_before'] . '</span>',
                            '<span class="stock-after">' . $move['stock_after'] . '</span>',
                            htmlspecialchars($move['location_name']),
                            htmlspecialchars($move['remark'] ?: 'N/A'),
                            '<span style="font-size: 0.85rem;">' . $formatted_timestamp . '</span>',
                            '<span style="font-size: 0.85rem;">' . htmlspecialchars($move['user_name']) . '</span>'
                        ];
                    ?>
                        <tr>
                            <?php foreach ($data_cells as $index => $cell_content): ?>
                                <td data-label="<?php echo $labels[$index]; ?>" class="text-center">
                                    <?php echo $cell_content; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>