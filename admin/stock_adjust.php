<?php
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

$page_title = 'Batch Inventory Adjustment';
$movement_type = 'ADJUST';

// Helper function
function send_alert_and_redirect($msg, $type = 'success') {
    $target_page = ($type === 'success') ? 'inventory.php' : 'stock_adjust.php';
    echo "<script>alert('" . addslashes($msg) . "'); window.location.href='{$target_page}';</script>";
    exit;
}

// Handle POST request (Batch Processing)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Capture Master Remark
    $master_remark = trim($_POST['master_remark'] ?? '');
    
    // 2. Capture Arrays
    $product_ids = $_POST['product_id'] ?? [];
    $location_ids = $_POST['location_id'] ?? [];
    $types = $_POST['adjustment_type'] ?? []; // 'add' or 'deduct'
    $quantities = $_POST['quantity'] ?? [];
    $item_remarks = $_POST['item_remark'] ?? [];

    if (empty($product_ids) || !is_array($product_ids)) {
        send_alert_and_redirect('No items submitted.', 'error');
    }
    if (empty($master_remark)) {
        send_alert_and_redirect('A Master Remark is required for the entire batch.', 'error');
    }

    try {
        $pdo->beginTransaction();
        $user_name = $_SESSION['user_name'] ?? 'Admin';
        
        $count = 0;

        // Loop through parallel arrays
        for ($i = 0; $i < count($product_ids); $i++) {
            $pid = (int)$product_ids[$i];
            $lid = (int)$location_ids[$i];
            $type = $types[$i] ?? 'add';
            $qty = (int)$quantities[$i];
            $specific_remark = trim($item_remarks[$i] ?? '');

            // Skip invalid rows
            if (!$pid || !$lid || $qty <= 0) continue;

            // Combine remarks
            $final_remark = $master_remark . ($specific_remark ? " ({$specific_remark})" : "");
            
            // Calculate Delta
            // If Add: +Qty. If Deduct: -Qty.
            $delta = ($type === 'add') ? $qty : -$qty;

            // 1. Check/Lock Stock
            $sel = $pdo->prepare("SELECT id, quantity, (SELECT name FROM products WHERE id = :pid_name) as prod_name 
                                  FROM inventory 
                                  WHERE product_id = :pid AND location_id = :lid 
                                  FOR UPDATE");
            $sel->execute([':pid' => $pid, ':lid' => $lid, ':pid_name' => $pid]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);

            $current_qty = $row ? (int)$row['quantity'] : 0;
            $new_qty = $current_qty + $delta;

            // Validation: Prevent Negative Stock
            if ($new_qty < 0) {
                $pName = $row['prod_name'] ?? 'Unknown Product';
                throw new Exception("Adjustment results in negative stock for '{$pName}'. Current: $current_qty, Deducting: $qty.");
            }
            
            // Validation: Cannot deduct if record doesn't exist
            if (!$row && $delta < 0) {
                throw new Exception("Cannot deduct from non-existent stock (Product ID: $pid).");
            }

            // 2. Update/Insert Inventory
            if ($row) {
                $pdo->prepare("UPDATE inventory SET quantity=:q, updated_at=NOW() WHERE id=:id")
                    ->execute([':q'=>$new_qty, ':id'=>$row['id']]);
            } else {
                // Only happens if Adding to empty slot
                $pdo->prepare("INSERT INTO inventory (product_id, location_id, quantity, updated_at)
                                 VALUES (:pid, :lid, :q, NOW())")
                    ->execute([':pid'=>$pid, ':lid'=>$lid, ':q'=>$delta]);
            }

            // 3. Log Movement
            // Prefix remark with type for clarity in logs
            $log_remark = ($type === 'add' ? '[ADD] ' : '[DEDUCT] ') . $final_remark;
            
            $ins = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, created_at, user_name)
                                  VALUES (:pid, :lid, :mt, :qty, :remark, NOW(), :user)");
            $ins->execute([
                ':pid' => $pid,
                ':lid' => $lid,
                ':mt'  => $movement_type,
                ':qty' => $qty, // Movement log usually shows positive magnitude
                ':remark' => $log_remark,
                ':user' => $user_name
            ]);

            $count++;
        }

        if ($count === 0) {
            throw new Exception("No valid items entered.");
        }

        $pdo->commit();
        send_alert_and_redirect("Batch adjustment successful. $count items processed.", 'success');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_alert_and_redirect('Error: '.$e->getMessage(), 'error');
    }
}

// Fetch products and locations
$products = $pdo->query("SELECT id, name, sku FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

* { font-family: 'Inter', sans-serif; }
body { background: #f8f9fb; }

/* WIDE CONTAINER */
.stock-adjust-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.stock-adjust-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}
.page-title-adjust {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}
.page-title-adjust i { color: #8b5cf6; } /* Purple for Adjust */

.btn-back-adjust {
    display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px;
    background: #ffffff; color: #0f172a; border: 1px solid #e5e7eb;
    border-radius: 12px; text-decoration: none; font-weight: 600;
    transition: all 0.2s;
}
.btn-back-adjust:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }

/* FORM CARD */
.form-card-adjust {
    background: #ffffff; border-radius: 20px; border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12); padding: 40px;
}

.form-section-title {
    font-size: 1.25rem; font-weight: 700; color: #0f172a;
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
}
.form-section-title i { color: #8b5cf6; }

/* MASTER REMARK AREA */
.master-remark-group { margin-bottom: 30px; }
.form-label-adjust { font-weight: 600; font-size: 0.9rem; color: #0f172a; display: block; margin-bottom: 8px; }
.form-label-required::after { content: '*'; color: #ef4444; margin-left: 4px; }
.form-textarea-adjust {
    width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #e5e7eb;
    font-size: 0.95rem; transition: 0.2s; resize: vertical;
}
.form-textarea-adjust:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); }

/* TABLE STYLES */
.items-table-wrapper { overflow-x: auto; margin-bottom: 20px; border: 1px solid #e5e7eb; border-radius: 12px; }
.items-table { width: 100%; border-collapse: collapse; min-width: 800px; }
.items-table th {
    background: #f3e8ff; /* Light purple bg */
    color: #6b21a8; /* Dark purple text */
    font-weight: 600; font-size: 0.85rem; padding: 14px 16px;
    text-align: left; border-bottom: 1px solid #d8b4fe;
}
.items-table td { padding: 12px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.items-table tr:last-child td { border-bottom: none; }

/* INPUTS IN TABLE */
.form-select-sm, .form-input-sm {
    width: 100%; padding: 10px; font-size: 0.9rem;
    border: 1px solid #e2e8f0; border-radius: 8px; transition: 0.2s;
    box-sizing: border-box; /* Crucial for responsive forms */
}
.form-select-sm:focus, .form-input-sm:focus {
    outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
}

/* Adjustment Type Specific Styling */
.form-select-sm.add-style {
    color: #10b981;
    border-color: #10b981;
}
.form-select-sm.deduct-style {
    color: #ef4444;
    border-color: #ef4444;
}

/* BUTTONS */
.btn-add-item {
    background: #f3e8ff; color: #7e22ce; border: none; padding: 12px 24px;
    border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex;
    align-items: center; gap: 8px; margin-bottom: 24px; transition: 0.2s;
}
.btn-add-item:hover { background: #e9d5ff; }

.btn-remove-row {
    background: #fee2e2; color: #dc2626; border: none; width: 38px; height: 38px;
    border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center;
}
.btn-remove-row:hover { background: #fecaca; }

.btn-submit-adjust {
    display: inline-flex; align-items: center; gap: 10px; padding: 14px 32px;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    color: #ffffff; border: none; border-radius: 12px; font-size: 1rem;
    font-weight: 700; cursor: pointer; box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
    transition: 0.2s;
}
.btn-submit-adjust:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(139, 92, 246, 0.4); }

/* INFO BOX */
.info-box-adjust {
    background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
    border: 1px solid #c4b5fd; border-radius: 12px; padding: 16px 20px; margin-bottom: 32px;
}
.info-box-adjust h6 { font-size: 0.9rem; font-weight: 700; color: #5b21b6; margin-bottom: 8px; }
.info-box-adjust ul { padding-left: 20px; color: #5b21b6; font-size: 0.85rem; margin: 0; }

/* ======================================= */
/* MOBILE CARD VIEW START (Media Query) */
/* ======================================= */
@media (max-width: 992px) {
    .stock-adjust-container { padding: 24px 16px 40px; }
    .stock-adjust-header { flex-direction: column; align-items: flex-start; }
    .btn-back-adjust, .btn-submit-adjust, .btn-add-item { width: 100%; justify-content: center; }
    .form-card-adjust { padding: 20px; }
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
    
    /* Add labels to inputs/selects */
    .items-table td:not(:last-child)::before {
        content: attr(data-label);
        display: block;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 8px;
        font-size: 0.9rem;
    }

    /* Individual Field Styling */
    .form-select-sm,
    .form-input-sm {
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

<div class="stock-adjust-container">
    <div class="stock-adjust-header">
        <h1 class="page-title-adjust">
            <i class="fas fa-balance-scale"></i>
            Batch Adjustment
        </h1>
        <a href="inventory.php" class="btn-back-adjust">
            <i class="fas fa-arrow-left"></i>
            Back to Inventory
        </a>
    </div>

    <div class="info-box-adjust">
        <h6><i class="fas fa-info-circle"></i> Instructions</h6>
        <ul>
            <li>Enter a **Master Remark** for the whole batch (e.g., "Year-end Audit").</li>
            <li>Select **Action** (Add or Deduct) for each item row.</li>
            <li>System prevents deductions that result in negative stock.</li>
        </ul>
    </div>

    <div class="form-card-adjust">
        <form method="POST" action="stock_adjust.php" id="adjustForm">
            
            <div class="master-remark-group">
                <label for="master_remark" class="form-label-adjust form-label-required">Master Remark (Reason)</label>
                <textarea name="master_remark" id="master_remark" class="form-textarea-adjust" rows="2" placeholder="e.g., Annual stock take discrepancy..." required></textarea>
            </div>

            <h5 class="form-section-title">
                <i class="fas fa-list-check"></i> Adjustment Items
            </h5>

            <div class="items-table-wrapper">
                <table class="items-table" id="adjustTable">
                    <thead>
                        <tr>
                            <th width="25%">Product <span style="color:red">*</span></th>
                            <th width="20%">Location <span style="color:red">*</span></th>
                            <th width="15%">Action <span style="color:red">*</span></th>
                            <th width="15%">Quantity <span style="color:red">*</span></th>
                            <th width="20%">Item Note</th>
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

            <div style="text-align: right;">
                <button type="submit" class="btn-submit-adjust">
                    <i class="fas fa-save"></i>
                    Confirm Batch Adjustment
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// PHP Data
const products = <?php echo json_encode($products); ?>;
const locations = <?php echo json_encode($locations); ?>;

// Build Options
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
            <select name="product_id[]" class="form-select-sm" required>
                ${productOptions}
            </select>
        </td>
        <td data-label="Location (Required)">
            <select name="location_id[]" class="form-select-sm" required>
                ${locationOptions}
            </select>
        </td>
        <td data-label="Action (Required)">
            <select name="adjustment_type[]" class="form-select-sm" style="font-weight:600;" onchange="updateColor(this)">
                <option value="add" style="color:#10b981;">+ Add Stock</option>
                <option value="deduct" style="color:#ef4444;">- Deduct Stock</option>
            </select>
        </td>
        <td data-label="Quantity (Required)">
            <input type="number" name="quantity[]" class="form-input-sm" min="1" required placeholder="Qty">
        </td>
        <td data-label="Item Note (Optional)">
            <input type="text" name="item_remark[]" class="form-input-sm" placeholder="Optional note">
        </td>
        <td>
            <button type="button" class="btn-remove-row" onclick="removeRow(this)" title="Remove">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;
    
    tbody.appendChild(tr);
    // Trigger color update for the new row's select
    updateColor(tr.querySelector('select[name="adjustment_type[]"]'));
}

function removeRow(btn) {
    const tbody = document.getElementById('tableBody');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
    } else {
        alert("At least one item is required.");
    }
}

// Visual helper to color code the Add/Deduct dropdown and border
function updateColor(select) {
    // Remove previous style classes
    select.classList.remove('add-style', 'deduct-style');
    
    // Apply new style class
    if(select.value === 'add') {
        select.classList.add('add-style'); // Green style
    } else if (select.value === 'deduct') {
        select.classList.add('deduct-style'); // Red style
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    addRow();
    // Re-run updateColor for all selects just in case
    document.querySelectorAll('select[name="adjustment_type[]"]').forEach(updateColor);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>