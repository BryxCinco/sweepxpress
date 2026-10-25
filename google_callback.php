<?php
require_once 'config.php';

use League\OAuth2\Client\Provider\Google;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$provider = new Google(options: [
    'clientId'      => GOOGLE_CLIENT_ID,
    'clientSecret'  => GOOGLE_CLIENT_SECRET,
    'redirectUri'   => GOOGLE_REDIRECT_URI,
]);

if (!isset($_GET['code'])) {
    // If we don't have an authorization code, get one.
    $authUrl = $provider->getAuthorizationUrl([
        'scope' => ['profile', 'email'],
    ]);
    header('Location: ' . $authUrl);
    exit;
} else {
    try {
        // Try to get an access token using the authorization code.
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);

        $user = $provider->getResourceOwner($token);
        $userData = $user->toArray();

        $email      = $userData['email'] ?? null;
        $google_id  = $userData['id'] ?? $userData['sub'] ?? null;
        $name       = $userData['name'] ?? '';

        if (!$google_id && !$email) {
            die('Sign-in with Google failed. Please try again or use email login.');
        }

        // Check if user already exists in your database
        // Assuming $pdo is available from 'config.php'
        // Check if user already exists in your database
// Assuming $pdo is available from 'config.php'
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
$stmt->execute([$google_id, $email]);
$existingUser = $stmt->fetch(PDO::FETCH_ASSOC); // Ensure array keys are strings

$redirectUrl = "/sweepxpress/index.php"; // Default redirect for regular users

if ($existingUser) {
    // FIXED: 1. Synchronization - I-update ang pangalan sa DB kung magkaiba sa Google name
    if (isset($existingUser['name']) && $existingUser['name'] !== $name) {
        $update_stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
        $update_stmt->execute([$name, $existingUser['id']]);
    }
    // FIXED: 2. I-update ang google_id kung wala pa (para sa user na nag-login muna sa OTP)
    if (empty($existingUser['google_id']) && $google_id) {
        $update_id_stmt = $pdo->prepare("UPDATE users SET google_id = ? WHERE id = ?");
        $update_id_stmt->execute([$google_id, $existingUser['id']]);
    }

    // FIXED: 3. I-set ang session gamit ang pangalan galing sa Google ($name)
    $_SESSION['user'] = [
        'id'        => $existingUser['id'],
        'name'      => $name, // Gagamitin ang pangalan mula sa Google
        'username'  => $existingUser['username'] ?? '',
        'profile_image' => $existingUser['profile_image'] ?? null,
        'phone'     => $existingUser['phone'] ?? '',
        'email'     => $existingUser['email'],
        'google_id' => $google_id,
        'role'      => $existingUser['role']
    ];
    
    // Check if the existing user is an admin
    if ($existingUser['role'] === 'admin') {
        $redirectUrl = "/sweepxpress/admin/dashboard.php";
    }
} else {
    // New user, insert into database with default 'customer' role
    // ('user' ay wala sa role enum: customer/admin/business)
    $role = 'customer';
    $username = generate_username($pdo, $name);
    // Walang password ang Google user; '' para pumasa sa NOT NULL (password_verify('', ...) ay laging false)
    $stmt = $pdo->prepare("INSERT INTO users (name, username, email, password_hash, google_id, role) VALUES (?, ?, ?, '', ?, ?)");
    $stmt->execute([$name, $username, $email, $google_id, $role]);
    $newUserId = $pdo->lastInsertId();

    $_SESSION['user'] = [
        'id'        => $newUserId,
        'name'      => $name, // Gagamitin ang pangalan galing sa Google
        'username'  => $username,
        'email'     => $email,
        'google_id' => $google_id,
        'role'      => $role
    ];
    // No need to check for admin role here as new users default to 'customer'
}

// Redirect based on the determined URL
header("Location: " . $redirectUrl);
exit;
    } catch (Exception $e) {
        error_log('Google sign-in failed: ' . $e->getMessage());
        die('Sign-in with Google failed. Please try again or use email login.');
    }
}