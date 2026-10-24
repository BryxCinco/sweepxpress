<?php
// /admin/suspend.php
// Suspends a user account for 30 days.

// IMPORTANT: Ensure this file is located in the /admin/ directory
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php'; // Includes $pdo connection

// 1. Authorization Check
if (!is_admin()) {
    // Redirect if not an admin
    header("Location: /sweepxpress/login.php");
    exit;
}

$userId = $_GET['user_id'] ?? null;

// 2. Input Validation
if (!$userId || !is_numeric($userId)) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user ID provided.'];
    header("Location: customer_management.php");
    exit;
}

$suspensionDays = 30; // Define suspension period

try {
    $pdo->beginTransaction();

    // 3. Core Action: Set the suspension expiry date using MySQL's DATE_ADD
    $stmt = $pdo->prepare("
        UPDATE users 
        SET 
            suspended_until = DATE_ADD(NOW(), INTERVAL ? DAY)
        WHERE id = ?
    ");
    $stmt->execute([$suspensionDays, $userId]);
    
    // 4. Fetch user name and new expiry date for the success message
    $userStmt = $pdo->prepare("SELECT name, suspended_until FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    $userName = htmlspecialchars($user['name'] ?? 'User');
    
    $expiry_date = new DateTime($user['suspended_until']);
    $expiry_formatted = $expiry_date->format('M d, Y h:i A');

    $pdo->commit();

    // 5. Set Success Message and Redirect
    $_SESSION['message'] = [
        'type' => 'success',
        'text' => $userName . " has been successfully suspended. Access will be blocked until: " . $expiry_formatted . "."
    ];

} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['message'] = [
        'type' => 'danger',
        'text' => 'Database Error: Could not suspend user. ' . htmlspecialchars($e->getMessage())
    ];
}

// Redirect back to the user actions page (adjust filename if necessary)
header("Location: user_actions.php?id=" . $userId); 
exit;