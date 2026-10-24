<?php
// We need to require config.php which should start the session, 
// or manually start it if config.php doesn't handle session start.
require_once __DIR__ . '/config.php';

// Ensure session is started for access to $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default redirect location for non-admin users
$redirect_url = '/sweepxpress/index.php';

// Check if a user is logged in and if they are an admin
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    // Assuming the 'role' key exists in the $_SESSION['user'] array
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        // If the user is an admin, redirect them to the admin login page
        // Note: The admin_login.php path might need adjustment based on where this file is located.
        // I am assuming admin_login.php is at '/sweepxpress/admin/admin_login.php' or similar 
        // if your current logout is in the root of sweepxpress, but based on your upload,
        // it seems admin_login.php is in the same directory as login.php. 
        // I will use a path relative to /sweepxpress/
        $redirect_url = '/sweepxpress/admin/admin_login.php'; 
        // If admin_login.php is inside an 'admin' folder, you might need this instead:
        // $redirect_url = '/sweepxpress/admin/admin_login.php'; 
    }
}

// Destroy the session data for the logged-in user
unset($_SESSION['user']);
session_destroy(); 
session_write_close(); // Close the session to prevent blocking

// Redirect the user
header('Location: ' . $redirect_url);
exit;
