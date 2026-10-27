<?php
// Invoice.php — invoice ng isang order (lahat ng status). Layout: includes/order_document.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/order_document.php';

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$orderId = $_GET['order_id'] ?? '';
if (!ctype_digit((string)$orderId)) {
    header("Location: /sweepxpress/customers/my_orders.php");
    exit();
}

try {
    // Order ng naka-login na user lang
    $stmt = $pdo->prepare("
        SELECT o.*, u.email, d.delivery_date
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN (
            SELECT order_id, MAX(delivery_date) AS delivery_date
            FROM deliveries
            GROUP BY order_id
        ) d ON d.order_id = o.id
        WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: /sweepxpress/customers/my_orders.php?status=not_found");
        exit();
    }

    // LEFT JOIN: kasama pa rin ang item kahit na-delete na ang produkto
    $stmt = $pdo->prepare("
        SELECT oi.quantity, oi.price, p.name
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Invoice error: " . $e->getMessage());
    die("We couldn't load this invoice. Please try again later.");
}

// Net 30/45/60 → due date mula sa araw ng order
$due = null;
if (preg_match('/^Net (\d+)$/', $order['payment_terms'] ?? '', $m)) {
    $due = date('M j, Y', strtotime($order['created_at'] . " +{$m[1]} days"));
}

$stamp = match ($order['status']) {
    'completed', 'delivered' => 'Paid',
    'cancelled'              => 'Cancelled',
    default                  => null,
};

sx_order_document([
    'title'     => 'Invoice',
    'order'     => $order,
    'items'     => $items,
    'stamp'     => $stamp,
    'due'       => $due,
    'backHref'  => '/sweepxpress/customers/order_details.php?id=' . (int)$order['id'],
    'backLabel' => 'Back to order',
]);
