<?php
// admin/adminreceipt.php — printable na resibo/invoice ng kahit anong order (admin).
// Kaparehong layout ng customer Invoice/Receipt: includes/order_document.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_document.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$order_id = (int)($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
    header("Location: allorders.php");
    exit();
}

try {
    // Isang delivery row lang (dati: LEFT JOIN na puwedeng kumuha ng kahit alin)
    $stmtOrder = $pdo->prepare("
        SELECT o.*, u.email, d.delivery_date
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN (SELECT order_id, MAX(delivery_date) AS delivery_date FROM deliveries GROUP BY order_id) d ON d.order_id = o.id
        WHERE o.id = ?
    ");
    $stmtOrder->execute([$order_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        header("Location: allorders.php");
        exit();
    }

    $stmtItems = $pdo->prepare("
        SELECT oi.quantity, oi.price, p.name
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Admin receipt error: " . $e->getMessage());
    die("Could not load this order. Please try again.");
}

$isPaid = in_array($order['status'], ['delivered', 'completed'], true);

// Stamp: awtomatiko kapag bayad na; ?stamped=true / false para i-override (gaya ng dati)
$stamp = match ($_GET['stamped'] ?? '') {
    'true'  => 'Paid',
    'false' => null,
    default => $isPaid ? 'Paid' : ($order['status'] === 'cancelled' ? 'Cancelled' : null),
};

$due = null;
if (preg_match('/^Net (\d+)$/', $order['payment_terms'] ?? '', $m)) {
    $due = date('M j, Y', strtotime($order['created_at'] . " +{$m[1]} days"));
}

sx_order_document([
    'title'     => $isPaid ? 'Official receipt' : 'Invoice',
    'order'     => $order,
    'items'     => $items,
    'stamp'     => $stamp,
    'due'       => $due,
    'backHref'  => '/sweepxpress/admin/order_details.php?id=' . $order_id,
    'backLabel' => 'Back to order',
]);
