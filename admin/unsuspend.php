<?php
// /admin/unsuspend.php
// Lifts a user suspension by setting the 'suspended_until' field to NULL.

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
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user ID provided for unsuspend action.'];
    header("Location: customer_management.php");
    exit;
}

try {
    // 3. Core Action: Setting suspended_until to NULL lifts the ban
    $stmt = $pdo->prepare("
        UPDATE users 
        SET 
            suspended_until = NULL
        WHERE id = ?
    ");
    
    $stmt->execute([$userId]);
    
    // 4. Fetch user name for the success message
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    $userName = htmlspecialchars($user['name'] ?? 'User');

    // 5. Set Success Message and Redirect
    $_SESSION['message'] = [
        'type' => 'success',
        'text' => $userName . " has been successfully unsuspended. The account is now fully active."
    ];

} catch (PDOException $e) {
    $_SESSION['message'] = [
        'type' => 'danger',
        'text' => 'Database Error: Could not unsuspend user. ' . htmlspecialchars($e->getMessage())
    ];
}

// Redirect back to the user actions page (adjust filename if necessary)
header("Location: user_actions.php?id=" . $userId); 
exit;