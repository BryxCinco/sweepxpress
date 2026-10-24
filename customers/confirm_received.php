<?php
require_once __DIR__ . '/../config.php';

if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$user_id = $_SESSION['user']['id'] ?? 0;
$order_id = intval($_POST['order_id'] ?? 0);
$received = $_POST['received'] ?? '';

if (!$order_id || !in_array($received, ['yes', 'no'])) {
    header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=invalid_confirm");
    exit();
}

try {
    // Fetch order
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || $order['status'] !== 'delivered') {
        header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=invalid_confirm");
        exit();
    }

    if ($received === 'yes') {
        // ✅ Mark as completed
        $pdo->prepare("UPDATE orders SET status = 'completed' WHERE id = ?")->execute([$order_id]);
        $pdo->prepare("UPDATE deliveries SET status = 'completed' WHERE order_id = ?")->execute([$order_id]);
        header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=received_success");
        exit();
    } else {
        // ⚠️ User says they didn’t receive it, revert to preparing
        $pdo->prepare("UPDATE orders SET status = 'preparing' WHERE id = ?")->execute([$order_id]);
        $pdo->prepare("UPDATE deliveries SET status = 'preparing' WHERE order_id = ?")->execute([$order_id]);
        header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=received_error");
        exit();
    }

} catch (PDOException $e) {
    error_log("confirm_received.php error: " . $e->getMessage());
    header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=error");
    exit();
}
