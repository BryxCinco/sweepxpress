<?php
// order_status_notifier.php
require_once __DIR__ . '/send_email.php'; // <-- Ito yung existing mo
require_once __DIR__ . '/../config.php';

function notifyCustomerOrderStatus($orderId, $newStatus, $pdo) {
    try {
        // Kunin info ng customer + email
        $stmt = $pdo->prepare("
            SELECT o.id, o.customer_name, o.status, u.email 
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            return false; // No order found
        }

        $email = $order['email'];
        if (empty($email)) {
            return false; // No email to notify
        }

        // Email Subject + Body
        $subject = "Your Order #$orderId Status Update";
        $body = "
            Hi {$order['customer_name']},<br><br>
            Your order <strong>#$orderId</strong> status has been updated to:<br>
            <h2 style='color:#06b6d4; text-transform:uppercase;'>$newStatus</h2>
            <br>
            Thank you for choosing SweepXpress!
        ";

        // Send Email (using your existing send_email.php)
        send_email($email, $subject, $body);

        return true;
    }
    catch (Exception $e) {
        error_log("Email Notification Error: " . $e->getMessage());
        return false;
    }
}
