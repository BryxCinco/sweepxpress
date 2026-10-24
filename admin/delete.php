<?php
// /admin/delete.php
// Permanently deletes a user account and associated records (e.g., orders).

require_once __DIR__ . '/../includes/auth.php'; // Contains is_admin() and session start
require_once __DIR__ . '/../config.php'; // Includes $pdo connection

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// --- Input Validation ---
$userId = $_GET['user_id'] ?? null;

if (!$userId || !is_numeric($userId)) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user ID provided for deletion.'];
    header("Location: customer_management.php");
    exit;
}

// --- CRITICAL SAFETY CHECK: Prevent Self-Deletion ---
// Assuming the logged-in user's ID is stored in $_SESSION['user']['id']
if (isset($_SESSION['user']['id']) && $_SESSION['user']['id'] == $userId) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'You cannot delete your own admin account.'];
    header("Location: user_actions.php?id=" . $userId);
    exit;
}

try {
    // 1. Fetch user name for the success message (must be done before deletion)
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    $userName = htmlspecialchars($user['name'] ?? 'User');
    
    // Start Transaction: Ensures all related deletions either pass or fail together
    $pdo->beginTransaction();

    // 2. Delete all records associated with this user (e.g., from the 'orders' table)
    // NOTE: If you have more tables (reviews, messages, etc.) linking to user_id, add them here.
    $deleteOrders = $pdo->prepare("DELETE FROM orders WHERE user_id = ?");
    $deleteOrders->execute([$userId]);
    
    // 3. Delete the user record itself
    $deleteUser = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $deleteUser->execute([$userId]);

    // Commit Transaction
    $pdo->commit();

    $_SESSION['message'] = [
        'type' => 'success',
        'text' => $userName . " (ID: $userId) has been permanently deleted, along with their associated records."
    ];

} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['message'] = [
        'type' => 'danger',
        'text' => 'Database Error: Could not delete user. ' . htmlspecialchars($e->getMessage())
    ];
}

// Redirect back to the customer management list
header("Location: customer_management.php"); 
exit;