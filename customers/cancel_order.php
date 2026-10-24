<?php
// Enable error reporting (disable on production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Since this file is in the 'customers' folder, go up one level to find config.php
require_once __DIR__ . '/../config.php'; // Loads h(), is_logged_in(), and $pdo

// Redirect if user not logged in
if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

// Ensure the request is a POST request (cancellation requests typically submit a form)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /sweepxpress/customers/my_orders.php");
    exit();
}

// Validate Order ID and Reason from the POST data
$order_id = $_POST['order_id'] ?? null;
$reason   = trim($_POST['reason'] ?? ''); // Get the cancellation reason
$user_id  = $_SESSION['user']['id'];

// --- Input Validation ---

if (!$order_id || !is_numeric($order_id)) {
    header("Location: /sweepxpress/customers/my_orders.php");
    exit();
}

// Basic validation for the reason (e.g., minimum 10 characters)
if (empty($reason) || strlen($reason) < 10) {
    // Redirect back to order details with an error for the reason
    header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=reason_error");
    exit();
}

try {
    // 1. Check if order belongs to user and is currently 'pending'
    // We check the 'orders' table's status field (o.status)
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? AND user_id = ?");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch();

    if ($order && $order['status'] === 'pending') {
        // 2. Update order status to 'cancellation_requested' and store the reason
        // NOTE: Requires 'cancellation_reason' column in 'orders' table.
        $update_stmt = $pdo->prepare("
            UPDATE orders 
            SET status = 'cancellation_requested', 
                cancellation_reason = ? 
            WHERE id = ? AND user_id = ?
        ");
        $update_stmt->execute([$reason, $order_id, $user_id]);

        // 3. Redirect back to order details with success message
        header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=request_submitted");
        exit();
    } else {
        // Redirect with error if not 'pending', not found, or already processed/in-progress
        header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=request_denied");
        exit();
    }
} catch (PDOException $e) {
    // Log the error for internal review
    error_log("❌ Cancellation request failed: " . $e->getMessage());
    
    // Redirect with a generic error
    header("Location: /sweepxpress/customers/order_details.php?id={$order_id}&status=error"); 
    exit();
}
?>