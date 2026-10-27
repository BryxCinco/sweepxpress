<?php
// admin/stock_in.php — tumanggap ng stock (hal. delivery mula sa supplier). Logic at form: includes/stock_ops.php
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
            $count = sx_stock_apply($pdo, 'in', $rows, $_SESSION['user']['name'] ?? 'Admin');
            sx_flash(['success', "Stock in saved: {$count} item" . ($count === 1 ? '' : 's') . "."]);
            header('Location: inventory.php');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('in stock error: ' . $e->getMessage());
            $error = 'Could not save. Please try again.';
        }
    }
}

$products  = sx_stock_products($pdo);
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';

sx_stock_form([
    'mode'       => 'in',
    'title'      => 'Stock in',
    'intro'      => 'Record stock you received, e.g. a supplier delivery.',
    'submit'     => 'Confirm stock in',
    'icon'       => 'fa-arrow-down',
    'remarkHint' => 'e.g. Supplier, delivery receipt no.',
    'products'   => $products,
    'locations'  => $locations,
    'csrf'       => sx_csrf_token(),
    'preselect'  => (int)($_GET['product_id'] ?? 0),
    'error'      => $error,
    'rows'       => $rows,
    'master'     => $master,
]);

require_once __DIR__ . '/../includes/footer.php';
