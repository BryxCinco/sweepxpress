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
        // Admin → bumalik sa iisang login page (may OTP at rate limit)
        $redirect_url = '/sweepxpress/login.php';
    }
}

// Destroy the session data for the logged-in user
unset($_SESSION['user']);
session_destroy(); 
session_write_close(); // Close the session to prevent blocking

// Redirect the user
header('Location: ' . $redirect_url);
exit;
