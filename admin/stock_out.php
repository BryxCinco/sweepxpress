<?php
// admin/stock_out.php — magbawas ng stock (hal. sira, sample, manual na benta). Logic at form: includes/stock_ops.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_products.php';
require_once __DIR__ . '/../includes/stock_ops.php';

$error = null;
$rows = [];
$master = trim($_POST['master_remark'] ?? '');

// Processing bago ang header; PRG pabalik sa inventory (dati: JS alert())
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rows = sx_stock_rows_from_post();
    if (!sx_csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            // Totoong pangalan ng admin (dati: $_SESSION['user_name'], na wala — laging "Admin")
            $count = sx_stock_apply($pdo, 'out', $rows, $_SESSION['user']['name'] ?? 'Admin');
            sx_flash(['success', "Stock out saved: {$count} item" . ($count === 1 ? '' : 's') . "."]);
            header('Location: inventory.php');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('out stock error: ' . $e->getMessage());
            $error = 'Could not save. Please try again.';
        }
    }
}

$products  = sx_stock_products($pdo);
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';

sx_stock_form([
    'mode'       => 'out',
    'title'      => 'Stock out',
    'intro'      => 'Remove stock that left the warehouse outside of online orders. If any item is short, nothing is saved.',
    'submit'     => 'Confirm stock out',
    'icon'       => 'fa-arrow-up',
    'remarkHint' => 'e.g. Damaged, walk-in sale',
    'products'   => $products,
    'locations'  => $locations,
    'csrf'       => sx_csrf_token(),
    'preselect'  => (int)($_GET['product_id'] ?? 0),
    'error'      => $error,
    'rows'       => $rows,
    'master'     => $master,
]);

require_once __DIR__ . '/../includes/footer.php';
