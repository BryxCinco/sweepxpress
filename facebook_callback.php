<?php
require_once 'config.php';

use League\OAuth2\Client\Provider\Facebook;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if FACEBOOK_APP_ID is defined to avoid errors
if (!defined('FACEBOOK_APP_ID') || !defined('FACEBOOK_APP_SECRET')) {
    die("Facebook credentials are not defined in config.php. Please check your settings.");
}

$provider = new Facebook([
    'clientId'          => FACEBOOK_APP_ID,
    'clientSecret'      => FACEBOOK_APP_SECRET,
    'redirectUri'       => FACEBOOK_REDIRECT_URI,
    'graphApiVersion'   => 'v19.0',
]);

if (!isset($_GET['code'])) {
    // If we don't have an authorization code, get one.
    $authUrl = $provider->getAuthorizationUrl([
        'scope' => ['email', 'public_profile'],
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
        $email = $userData['email'];
        $facebook_id = $userData['id'];
        $name = $userData['name'];
        
        // I-check kung existing user na sa database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE facebook_id = ? OR email = ?");
        $stmt->execute([$facebook_id, $email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // Update the existing user if they logged in with a different method before
            $stmt = $pdo->prepare("UPDATE users SET facebook_id = ? WHERE id = ? AND facebook_id IS NULL");
            $stmt->execute([$facebook_id, $existingUser['id']]);
            
            $_SESSION['user'] = $existingUser;
        } else {
            // Bagong user, i-insert sa database
            $stmt = $pdo->prepare("INSERT INTO users (name, email, facebook_id, role) VALUES (?, ?, ?, 'user')");
            $stmt->execute([$name, $email, $facebook_id]);
            
            $newUserId = $pdo->lastInsertId();
            $_SESSION['user'] = [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'facebook_id' => $facebook_id,
                'role' => 'user'
            ];
        }

        // Redirect to dashboard or home page
        header("Location: /sweepxpress/index.php");
        exit;
    } catch (Exception $e) {
        die('Failed to authenticate with Facebook: ' . $e->getMessage());
    }
}