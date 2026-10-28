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
        // Escaped: galing sa customer ang pangalan; label sa halip na raw status (hal. "Pending PO")
        $labels = ['Pending PO' => 'PO under review', 'cancellation_requested' => 'Cancellation requested'];
        $name   = htmlspecialchars($order['customer_name'] ?: 'Customer', ENT_QUOTES, 'UTF-8');
        $label  = htmlspecialchars($labels[$newStatus] ?? ucfirst($newStatus), ENT_QUOTES, 'UTF-8');
        $orderId = (int)$orderId;

        $subject = "Your Order #$orderId Status Update";
        $body = "
            Hi {$name},<br><br>
            Your order <strong>#$orderId</strong> status has been updated to:<br>
            <h2 style='color:#1e3a8a; text-transform:uppercase;'>$label</h2>
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
