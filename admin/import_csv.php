<?php
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';

// =================================================================================
// 1. DOWNLOAD TEMPLATE (MATCHES INVENTORY EXPORT FORMAT)
// =================================================================================
if (isset($_GET['action']) && $_GET['action'] == 'download_sample') {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_import_template.csv"');
    $out = fopen('php://output', 'w');
    
    // Excel Fixes
    fputs($out, "sep=,\n"); 
    fputs($out, "\xEF\xBB\xBF");
    
    // Exact same headers as inventory.php export
    fputcsv($out, ['ID', 'Product Name', 'SKU', 'Unit Price', 'Current Stock', 'Capital Tied', 'Status', 'Remark (Optional)']);
    
    // Example Rows
    fputcsv($out, ['', 'Sample Product A', 'PROD-001', '0.00', '50', '0.00', 'In Stock', 'Stock Take']);
    fputcsv($out, ['', 'Sample Product B', 'PROD-002', '0.00', '10', '0.00', 'Low Stock', 'New Shipment']);
    
    fclose($out);
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$message = '';
$messageType = '';
$import_results = [];

// =================================================================================
// 2. HANDLE CSV UPLOAD
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $location_id = $_POST['location_id'];
    $mode = $_POST['import_mode']; // 'add' or 'overwrite'

    if (is_uploaded_file($file)) {
        try {
            $pdo->beginTransaction();
            $handle = fopen($file, "r");
            
            // Check for BOM/Sep line and Headers
            $firstLine = fgets($handle);
            if (strpos($firstLine, 'sep=') === false) {
                rewind($handle); // No sep line, reset
            }
            fgetcsv($handle); // Skip Headers Row

            $userName = $_SESSION['user_name'] ?? 'Admin';
            $rowNum = 0;
            $success = 0;
            $errors = 0;

            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $rowNum++;
                
                // --- MAP COLUMNS BASED ON EXPORT LAYOUT ---
                // Export Layout: ID(0), Name(1), SKU(2), Price(3), Stock(4), Capital(5), Status(6)
                // We assume Remark might be in (7)
                
                $sku = trim($data[2] ?? ''); // Column C is SKU
                $qtyString = str_replace(',', '', $data[4] ?? '0'); // Column E is Stock (remove commas from '1,000')
                $qty = (int)$qtyString;
                $remark = trim($data[7] ?? ''); // Column H is Remark (Optional)

                // Validation
                if (empty($sku)) {
                    // Skip empty rows (common in Excel exports)
                    continue;
                }

                // 1. Find Product
                $stmt = $pdo->prepare("SELECT id FROM products WHERE sku = :sku LIMIT 1");
                $stmt->execute([':sku' => $sku]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    $import_results[] = ["Row $rowNum ($sku)", "Failed", "SKU not found in system"];
                    $errors++;
                    continue;
                }

                $pid = $product['id'];

                // 2. Get Current Stock
                $stockStmt = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = :pid AND location_id = :lid");
                $stockStmt->execute([':pid' => $pid, ':lid' => $location_id]);
                $inv = $stockStmt->fetch(PDO::FETCH_ASSOC);

                $currentQty = $inv ? (int)$inv['quantity'] : 0;
                $newQty = 0;
                $delta = 0;

                // 3. Logic
                if ($mode === 'overwrite') {
                    // Perfect for Stock Taking (Export -> Count -> Import)
                    $newQty = $qty;
                    $delta = $qty - $currentQty;
                } else {
                    // Add Mode
                    $newQty = $currentQty + $qty;
                    $delta = $qty;
                }

                // 4. Update DB
                if ($inv) {
                    $pdo->prepare("UPDATE inventory SET quantity = :q, updated_at = NOW() WHERE id = :id")
                        ->execute([':q' => $newQty, ':id' => $inv['id']]);
                } else {
                    $pdo->prepare("INSERT INTO inventory (product_id, location_id, quantity, updated_at) VALUES (:pid, :lid, :q, NOW())")
                        ->execute([':pid' => $pid, ':lid' => $location_id, ':q' => $newQty]);
                }

                // 5. Log History (Only if changed)
                if ($delta != 0) {
                    $movType = ($delta >= 0) ? 'IN' : 'OUT';
                    $logQty = abs($delta);
                    $logRemark = "CSV Import" . ($remark ? ": $remark" : "");
                    
                    $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, created_at, user_name) 
                                   VALUES (:pid, :lid, :mt, :q, :r, NOW(), :u)")
                        ->execute([':pid'=>$pid, ':lid'=>$location_id, ':mt'=>$movType, ':q'=>$logQty, ':r'=>$logRemark, ':u'=>$userName]);
                }

                $import_results[] = ["Row $rowNum ($sku)", "Success", "Stock updated to $newQty"];
                $success++;
            }

            fclose($handle);
            $pdo->commit();
            $message = "Process Complete: $success items processed.";
            $messageType = $errors > 0 ? 'warning' : 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "System Error: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Please upload a valid CSV file.";
        $messageType = 'error';
    }
}

$locations = $pdo->query("SELECT * FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
    body { background: #f8f9fb; font-family: 'Inter', sans-serif; }
    .import-container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
    
    .import-card { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .page-title { font-size: 1.8rem; font-weight: 800; color: #0f172a; margin: 0; }
    
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: #334155; }
    .form-control { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
    
    .btn-submit { background: #0f172a; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; transition: 0.2s; }
    .btn-submit:hover { background: #334155; }
    
    .btn-download { color: #2563eb; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }
    .btn-back { color: #64748b; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; }

    .results-box { margin-top: 30px; border-top: 2px solid #f1f5f9; padding-top: 20px; max-height: 400px; overflow-y: auto; }
    .res-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .res-table th { text-align: left; padding: 10px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 0.85rem; position: sticky; top: 0; }
    .res-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
    
    .status-Success { color: #166534; font-weight: 700; }
    .status-Failed { color: #dc2626; font-weight: 700; }
    
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
    .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
</style>

<div class="import-container">
    <div class="page-header">
        <h1 class="page-title"><i class="fas fa-file-csv" style="color:#2563eb;"></i> Bulk Stock Update</h1>
        <a href="inventory.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <div class="import-card">
        <div style="margin-bottom: 25px; padding: 20px; background: #eff6ff; border-radius: 12px; border: 1px solid #dbeafe;">
            <h4 style="margin:0 0 10px 0; color:#1e40af;">How to use:</h4>
            <ol style="margin:0; padding-left:20px; color:#1e3a8a; line-height: 1.6;">
                <li>Click <strong>Export CSV</strong> on the Inventory page to get your current list.</li>
                <li>Edit the <strong>"Current Stock"</strong> column in Excel/Google Sheets.</li>
                <li>Save as <strong>CSV</strong>.</li>
                <li>Upload here using <strong>"Overwrite Stock"</strong> mode.</li>
            </ol>
            <div style="margin-top: 15px;">
                <a href="import_csv.php?action=download_sample" class="btn-download">
                    <i class="fas fa-file-download"></i> Download Blank Template
                </a>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label class="form-label">Select Location (Required)</label>
                <select name="location_id" class="form-control" required>
                    <?php foreach($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Update Mode</label>
                <select name="import_mode" class="form-control">
                    <option value="overwrite" selected>Overwrite Stock (Stock Taking)</option>
                    <option value="add">Add to Stock (Restocking)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Upload Modified CSV File</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
            </div>

            <button type="submit" class="btn-submit"><i class="fas fa-upload"></i> Process Update</button>
        </form>

        <?php if (!empty($import_results)): ?>
            <div class="results-box">
                <h4>Detailed Results</h4>
                <table class="res-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($import_results as $res): ?>
                            <tr>
                                <td><?= htmlspecialchars($res[0]) ?></td>
                                <td class="status-<?= $res[1] ?>"><?= $res[1] ?></td>
                                <td><?= htmlspecialchars($res[2]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>