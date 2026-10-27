<?php
// admin/import_csv.php — bulk stock update mula sa CSV (kaparehong format ng Export CSV sa inventory.php)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_products.php';
require_once __DIR__ . '/../includes/stock_ops.php';

// =================================================================================
// 1. DOWNLOAD TEMPLATE (MATCHES INVENTORY EXPORT FORMAT)
// =================================================================================
if (isset($_GET['action']) && $_GET['action'] == 'download_sample') {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "sep=,\n");
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Product Name', 'SKU', 'Unit Price', 'Current Stock', 'Capital Tied', 'Status', 'Remark (Optional)']);
    fputcsv($out, ['', 'Sample Product A', 'PROD-001', '0.00', '50', '0.00', 'In Stock', 'Stock Take']);
    fputcsv($out, ['', 'Sample Product B', 'PROD-002', '0.00', '10', '0.00', 'Low Stock', 'New Shipment']);
    fclose($out);
    exit;
}

$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$message = null;        // [type, text]
$import_results = [];   // [reference, ok(bool), outcome]

// =================================================================================
// 2. HANDLE CSV UPLOAD (bago ang header)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file        = $_FILES['csv_file']['tmp_name'] ?? '';
    $location_id = (int)($_POST['location_id'] ?? 0);
    $mode        = ($_POST['import_mode'] ?? '') === 'add' ? 'add' : 'overwrite';
    $userName    = $_SESSION['user']['name'] ?? 'Admin'; // dati: $_SESSION['user_name'] (wala, kaya laging "Admin")

    if (!sx_csrf_ok()) {
        $message = ['error', 'Your session expired. Please try again.'];
    } elseif (!isset($locations[$location_id])) {
        $message = ['error', 'Choose a valid location.'];
    } elseif (!$file || !is_uploaded_file($file) || ($_FILES['csv_file']['size'] ?? 0) > 2 * 1024 * 1024) {
        $message = ['error', 'Please upload a CSV file (max 2 MB).'];
    } else {
        $findProduct = $pdo->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
        $findStock   = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = ? FOR UPDATE");
        $updStock    = $pdo->prepare("UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $insStock    = $pdo->prepare("INSERT INTO inventory (product_id, location_id, quantity, updated_at) VALUES (?, ?, ?, NOW())");
        $log         = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

        try {
            $pdo->beginTransaction();
            $handle = fopen($file, 'r');

            // "sep=," na linya (galing sa export) at header row
            $firstLine = fgets($handle);
            if (strpos($firstLine, 'sep=') === false) rewind($handle);
            fgetcsv($handle);

            $rowNum = 0;
            $success = 0;
            $failed = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                $rowNum++;
                $sku = trim($data[2] ?? '');
                if ($sku === '') continue; // blangkong row (karaniwan sa Excel)

                $qtyRaw = str_replace(',', '', trim($data[4] ?? ''));
                $remark = trim($data[7] ?? '');
                $ref    = "Row {$rowNum} ({$sku})";

                // Buong numero, 0 o higit (dati: tinatanggap pati negatibo → negatibong stock)
                if (!preg_match('/^\d+$/', $qtyRaw) || (int)$qtyRaw > SX_STOCK_MAX_QTY) {
                    $import_results[] = [$ref, false, 'Stock must be a whole number from 0 to ' . number_format(SX_STOCK_MAX_QTY)];
                    $failed++;
                    continue;
                }
                $qty = (int)$qtyRaw;

                $findProduct->execute([$sku]);
                $pid = $findProduct->fetchColumn();
                if (!$pid) {
                    $import_results[] = [$ref, false, 'SKU not found'];
                    $failed++;
                    continue;
                }

                $findStock->execute([$pid, $location_id]);
                $inv = $findStock->fetch(PDO::FETCH_ASSOC);
                $currentQty = $inv ? (int)$inv['quantity'] : 0;
                $newQty = $mode === 'overwrite' ? $qty : $currentQty + $qty;
                $delta  = $newQty - $currentQty;

                if ($inv) $updStock->execute([$newQty, $inv['id']]);
                else      $insStock->execute([$pid, $location_id, $newQty]);

                if ($delta !== 0) {
                    // Overwrite = stock count → ADJUST na may [ADD]/[DEDUCT] (para tama ang running balance sa history)
                    if ($mode === 'overwrite') {
                        $logType = 'ADJUST';
                        $logRemark = ($delta > 0 ? '[ADD] ' : '[DEDUCT] ') . 'CSV stock count' . ($remark !== '' ? " ({$remark})" : '');
                    } else {
                        $logType = 'IN';
                        $logRemark = 'CSV Import' . ($remark !== '' ? ": {$remark}" : '');
                    }
                    $log->execute([$pid, $location_id, $logType, abs($delta), mb_substr($logRemark, 0, 255), $userName, $userName]);
                }

                $import_results[] = [$ref, true, $delta === 0 ? "No change ({$newQty})" : "{$currentQty} → {$newQty}"];
                $success++;
            }
            fclose($handle);
            $pdo->commit();

            $message = $success === 0 && $failed === 0
                ? ['warning', 'No rows with a SKU were found in the file.']
                : [$failed ? 'warning' : 'success', "{$success} product" . ($success === 1 ? '' : 's') . " updated" . ($failed ? ", {$failed} row" . ($failed === 1 ? '' : 's') . " skipped." : '.')];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('CSV import failed: ' . $e->getMessage());
            $message = ['error', 'The import failed and nothing was changed. Check that the file is a valid CSV.'];
            $import_results = [];
        }
    }
}

$csrf = sx_csrf_token();
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   IMPORT CSV (Phase 9e) — tokens + sx-field / sx-table mula sa assets/style.css
   ============================================================ */
.ic { max-width: 900px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ic-steps { margin: 0 0 var(--sx-space-4); padding-left: 1.2rem; color: var(--sx-muted); line-height: 1.7; }
.ic-steps strong { color: var(--sx-dark); }
.ic-modes { display: grid; gap: var(--sx-space-2); }
.ic-mode { display: flex; gap: var(--sx-space-3); padding: var(--sx-space-3) var(--sx-space-4); border: 1px solid var(--sx-border); border-radius: var(--sx-radius-md); cursor: pointer; }
.ic-mode input { margin-top: 4px; accent-color: var(--sx-primary); }
.ic-mode strong { display: block; color: var(--sx-dark); font-size: var(--sx-text-sm); }
.ic-mode span { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ic-mode:has(input:checked) { border-color: var(--sx-primary); background: var(--sx-primary-soft); }
.ic-results { margin-top: var(--sx-space-5); }
.ic-results .ok { color: var(--sx-accent-dark); font-weight: var(--sx-weight-bold); }
.ic-results .bad { color: var(--sx-status-cancelled); font-weight: var(--sx-weight-bold); }
@media (min-width: 640px) { .ic-modes { grid-template-columns: 1fr 1fr; } }
</style>

<div class="ic sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/admin/inventory.php">Inventory</a> <span aria-hidden="true">/</span> <span>Import CSV</span>
    </nav>
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Bulk stock update</h1>
            <p>Update many products at once from a spreadsheet, matched by SKU.</p>
        </div>
        <a href="import_csv.php?action=download_sample" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-file-arrow-down"></i> Blank template</a>
    </div>

    <?php if ($message): ?>
        <div class="sx-alert sx-alert-<?= h($message[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $message[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($message[1]) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="sx-card">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <h2 class="pf-title">How it works</h2>
        <ol class="ic-steps">
            <li>Click <strong>Export CSV</strong> on the <a href="inventory.php">Inventory</a> page.</li>
            <li>Edit the <strong>Current Stock</strong> column in Excel or Google Sheets, then save as CSV.</li>
            <li>Upload it below. Rows are matched by <strong>SKU</strong>; other columns are ignored.</li>
        </ol>

        <div class="sx-field">
            <span class="sx-label">Update mode</span>
            <div class="ic-modes">
                <label class="ic-mode">
                    <input type="radio" name="import_mode" value="overwrite" checked>
                    <div><strong>Set stock to the file's number</strong><span>For a stock count. Logged as an adjustment.</span></div>
                </label>
                <label class="ic-mode">
                    <input type="radio" name="import_mode" value="add">
                    <div><strong>Add the file's number to stock</strong><span>For a delivery. Logged as stock in.</span></div>
                </label>
            </div>
        </div>

        <div class="pf-grid">
            <div class="sx-field">
                <label class="sx-label" for="location_id">Location</label>
                <select name="location_id" id="location_id" class="sx-select" required>
                    <?php foreach ($locations as $id => $name): ?>
                        <option value="<?= (int)$id ?>"><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="csv_file">CSV file</label>
                <input type="file" name="csv_file" id="csv_file" class="sx-input" accept=".csv,text/csv" required>
            </div>
        </div>

        <div class="pf-actions">
            <a href="inventory.php" class="sx-btn sx-btn-ghost">Cancel</a>
            <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid fa-upload"></i> Import</button>
        </div>
    </form>

    <?php if ($import_results): ?>
        <section class="sx-card ic-results" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0; max-height: 420px">
                <table class="sx-table">
                    <thead><tr><th>Row</th><th>Status</th><th>Result</th></tr></thead>
                    <tbody>
                        <?php foreach ($import_results as [$ref, $ok, $outcome]): ?>
                            <tr>
                                <td><?= h($ref) ?></td>
                                <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'Updated' : 'Skipped' ?></td>
                                <td><?= h($outcome) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
