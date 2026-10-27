<?php
// customers/UserReceipt.php — official receipt ng order na delivered/completed.
// Layout: includes/order_document.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/order_document.php';

// Login required (dati: nakikita ng kahit sino ang mga order na walang user_id)
$user_id = $_SESSION['user']['id'] ?? null;
if (!$user_id) {
    header("Location: /sweepxpress/login.php");
    exit();
}

if (!isset($_GET['order_id']) || !ctype_digit((string)$_GET['order_id'])) {
    header("Location: /sweepxpress/customers/my_orders.php");
    exit();
}
$order_id = (int)$_GET['order_id'];

try {
    $stmtOrder = $pdo->prepare("
        SELECT o.*, u.email, d.delivery_date
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN (
            SELECT order_id, MAX(delivery_date) AS delivery_date
            FROM deliveries
            GROUP BY order_id
        ) d ON d.order_id = o.id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmtOrder->execute([$order_id, $user_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: /sweepxpress/customers/my_orders.php?status=not_found");
        exit();
    }

    // Resibo lang kapag bayad na (delivered/completed); kung hindi pa, balik sa order
    if (!in_array($order['status'], ['completed', 'delivered'], true)) {
        header("Location: /sweepxpress/customers/order_details.php?id={$order_id}");
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
    error_log("UserReceipt DB Error: " . $e->getMessage());
    die("A database error occurred while fetching your order details.");
}

sx_order_document([
    'title'     => 'Official receipt',
    'order'     => $order,
    'items'     => $items,
    'stamp'     => 'Paid',
    'due'       => null,
    'backHref'  => '/sweepxpress/customers/order_details.php?id=' . $order_id,
    'backLabel' => 'Back to order',
]);
