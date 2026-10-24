<?php
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

$page_title = 'Stock-In (Add Stock)';
$movement_type = 'IN';

// Helper function
function send_alert_and_redirect($msg, $type = 'success') {
    $target_page = ($type === 'success') ? 'inventory.php' : 'stock_in.php';
    echo "<script>alert('". addslashes($msg) ."'); window.location.href='{$target_page}';</script>";
    exit;
}

// Handle POST request (Multi-Item Support)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Inputs are now arrays
    $product_ids = $_POST['product_id'] ?? [];
    $location_ids = $_POST['location_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $remarks = $_POST['remark'] ?? [];

    if (empty($product_ids) || !is_array($product_ids)) {
        send_alert_and_redirect('No items submitted.', 'error');
    }

    try {
        $pdo->beginTransaction();
        $user_name = $_SESSION['user_name'] ?? 'Admin';
        
        $count = 0;

        // Loop through the arrays
        for ($i = 0; $i < count($product_ids); $i++) {
            $pid = (int)$product_ids[$i];
            $lid = (int)$location_ids[$i];
            $qty = (int)$quantities[$i];
            $rmk = trim($remarks[$i] ?? '');

            // Skip invalid rows
            if (!$pid || !$lid || $qty <= 0) continue;

            $delta = $qty;

            // 1. Insert stock movement record
            $ins = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, created_at, user_name)
                                 VALUES (:pid, :lid, :mt, :qty, :remark, NOW(), :user)");
            $ins->execute([
                ':pid' => $pid,
                ':lid' => $lid,
                ':mt'  => $movement_type,
                ':qty' => $qty,
                ':remark' => $rmk,
                ':user' => $user_name
            ]);

            // 2. Update/Insert inventory record
            $sel = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = :pid AND location_id = :lid FOR UPDATE");
            $sel->execute([':pid' => $pid, ':lid' => $lid]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $newQty = (int)$row['quantity'] + $delta;
                $pdo->prepare("UPDATE inventory SET quantity=:q, updated_at=NOW() WHERE id=:id")
                    ->execute([':q'=>$newQty, ':id'=>$row['id']]);
            } else {
                $pdo->prepare("INSERT INTO inventory (product_id, location_id, quantity, updated_at)
                               VALUES (:pid, :lid, :q, NOW())")
                    ->execute([':pid'=>$pid, ':lid'=>$lid, ':q'=>$delta]);
            }
            $count++;
        }

        if ($count === 0) {
            throw new Exception("No valid items entered.");
        }

        $pdo->commit();
        send_alert_and_redirect("Stock-In successful. $count item(s) added.", 'success');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_alert_and_redirect('Error: '.$e->getMessage(), 'error');
    }
}

// Fetch products and locations for the form
$products = $pdo->query("SELECT id, name, sku, price FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

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

/* WIDENED CONTAINER */
.stock-in-container {
    max-width: 1600px; 
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.stock-in-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-stock-in {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-stock-in i {
    color: #10b981;
}

.btn-back-stock-in {
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

.btn-back-stock-in:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* FORM CARD */
.form-card-stock-in {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    padding: 40px;
}

.form-section-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-section-title i {
    color: #10b981;
}

/* TABLE STYLES FOR MULTI-ITEM */
.items-table-wrapper {
    overflow-x: auto;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 600px; 
}

.items-table th {
    background: #f1f5f9;
    color: #475569;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 14px 16px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.items-table td {
    padding: 12px 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
}

.items-table tr:last-child td {
    border-bottom: none;
}

/* FORM INPUTS IN TABLE */
.form-select-stock-in,
.form-input-stock-in {
    width: 100%;
    padding: 12px 14px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    transition: all 0.2s;
    box-sizing: border-box; /* Crucial for responsive forms */
}

.form-select-stock-in:focus,
.form-input-stock-in:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
}

/* ACTION BUTTONS */
.btn-add-item {
    background: #e0f2fe;
    color: #0369a1;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.95rem;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 24px;
}

.btn-add-item:hover {
    background: #bae6fd;
}

.btn-remove-row {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.2s;
}

.btn-remove-row:hover {
    background: #fecaca;
}

/* SUBMIT BUTTON */
.btn-submit-stock-in {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 32px;
    background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
    transition: all 0.2s;
}

.btn-submit-stock-in:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(16, 185, 129, 0.4);
}

/* INFO BOX */
.info-box-stock-in {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 1px solid #93c5fd;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 32px;
}

.info-box-stock-in h6 {
    font-size: 0.9rem;
    font-weight: 700;
    color: #1e40af;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-box-stock-in ul {
    margin: 0;
    padding-left: 20px;
    color: #1e40af;
    font-size: 0.85rem;
}

.info-box-stock-in li {
    margin-bottom: 4px;
}

/* ======================================= */
/* MOBILE CARD VIEW START (Media Query) */
/* ======================================= */
@media (max-width: 992px) {
    .stock-in-container { padding: 24px 16px 40px; }
    .stock-in-header { flex-direction: column; align-items: flex-start; }
    .btn-back-stock-in, .btn-submit-stock-in, .btn-add-item { width: 100%; justify-content: center; }
    .form-card-stock-in { padding: 20px; }
    .btn-add-item { margin-bottom: 15px; }
    
    .items-table-wrapper {
        border: none; /* Remove table wrapper border */
        overflow-x: hidden;
    }

    .items-table {
        min-width: 100%; /* Full width */
        border-spacing: 0 15px; /* Spacing between rows/cards */
        border-collapse: separate;
    }

    /* Hide table header */
    .items-table thead {
        display: none;
    }

    /* Make TR look like a Card */
    .items-table tbody tr {
        display: block;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 15px;
        background: #ffffff;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    }

    /* Make TD look like a Form Section */
    .items-table td {
        display: block;
        padding: 0 0 15px 0;
        border-bottom: none;
    }
    
    .items-table tr:last-child td {
        border-bottom: none;
    }
    
    .items-table td:not(:last-child)::before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 8px;
        font-size: 0.9rem;
    }

    /* Individual Field Styling */
    .form-select-stock-in,
    .form-input-stock-in {
        padding: 10px 12px;
    }

    /* Remove Button (Last TD) */
    .items-table td:last-child {
        text-align: right;
        padding: 10px 0 0;
        border-top: 1px solid #f1f5f9;
    }
    
    /* Remove required red asterisk in mobile view since we use data-label */
    .items-table th span {
        display: none;
    }
}
/* ======================================= */
/* MOBILE CARD VIEW END */
/* ======================================= */
</style>

<div class="stock-in-container">
    <div class="stock-in-header">
        <h1 class="page-title-stock-in">
            <i class="fas fa-box-open"></i>
            Stock-In (Bulk)
        </h1>
        <a href="inventory.php" class="btn-back-stock-in">
            <i class="fas fa-arrow-left"></i>
            Back to Inventory
        </a>
    </div>

    <div class="info-box-stock-in">
        <h6><i class="fas fa-info-circle"></i> Stock-In Instructions</h6>
        <ul>
            <li>Select the product, location, and quantity for each item.</li>
            <li>Click <strong>"Add Another Item"</strong> to add more rows.</li>
            <li>Click <strong>"Confirm Stock-In"</strong> to save all items at once.</li>
        </ul>
    </div>

    <div class="form-card-stock-in">
        <h5 class="form-section-title">
            <i class="fas fa-clipboard-list"></i>
            Add Stock to Inventory
        </h5>

        <form method="POST" action="stock_in.php" id="stockInForm">
            <input type="hidden" name="action" value="stock_in">

            <div class="items-table-wrapper">
                <table class="items-table" id="stockTable">
                    <thead>
                        <tr>
                            <th width="30%">Product <span style="color:red">*</span></th>
                            <th width="25%">Location <span style="color:red">*</span></th>
                            <th width="15%">Quantity <span style="color:red">*</span></th>
                            <th width="25%">Remark</th>
                            <th width="5%"></th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        </tbody>
                </table>
            </div>

            <button type="button" class="btn-add-item" onclick="addRow()">
                <i class="fas fa-plus"></i> Add Another Item
            </button>

            <div style="text-align: right; margin-top: 10px;">
                <button type="submit" class="btn-submit-stock-in">
                    <i class="fas fa-check-circle"></i>
                    Confirm Stock-In
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// PHP Data passed to JS
const products = <?php echo json_encode($products); ?>;
const locations = <?php echo json_encode($locations); ?>;

// Build options strings once
let productOptions = '<option value="">-- Select Product --</option>';
products.forEach(p => {
    let sku = p.sku ? ` (${p.sku})` : '';
    productOptions += `<option value="${p.id}">${p.name}${sku}</option>`;
});

let locationOptions = '<option value="">-- Select Location --</option>';
locations.forEach(l => {
    locationOptions += `<option value="${l.id}">${l.name}</option>`;
});

function addRow() {
    const tbody = document.getElementById('tableBody');
    const tr = document.createElement('tr');
    
    // Use data-label attributes for mobile display
    tr.innerHTML = `
        <td data-label="Product (Required)">
            <select name="product_id[]" class="form-select-stock-in" required>
                ${productOptions}
            </select>
        </td>
        <td data-label="Location (Required)">
            <select name="location_id[]" class="form-select-stock-in" required>
                ${locationOptions}
            </select>
        </td>
        <td data-label="Quantity (Required)">
            <input type="number" name="quantity[]" class="form-input-stock-in" min="1" required placeholder="Qty">
        </td>
        <td data-label="Remark (Optional)">
            <input type="text" name="remark[]" class="form-input-stock-in" placeholder="Optional remark (e.g., Supplier)">
        </td>
        <td>
            <button type="button" class="btn-remove-row" onclick="removeRow(this)" title="Remove">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
}

function removeRow(btn) {
    const tbody = document.getElementById('tableBody');
    // Allow removing the last row, but prompt the user that a new one will be added on refresh
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
    } else {
        alert("At least one item is required for submission.");
    }
}

// Initialize with one row
document.addEventListener('DOMContentLoaded', () => {
    addRow();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>