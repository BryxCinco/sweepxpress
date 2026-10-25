<?php
// ====================================================================
// config.php — database connection (PDO) and app settings
// ====================================================================

// Start session safely — prevents "session already active" warning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --------------------------------------------------------------------
// 🛒 SANITIZE & INITIALIZE SESSION CART
// --------------------------------------------------------------------
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
} else {
    // Clean invalid entries (fixes ghost “Cart (1)” bug)
    foreach ($_SESSION['cart'] as $k => $v) {
        if (
            !is_numeric($k) || intval($k) <= 0 ||
            !is_numeric($v) || intval($v) <= 0
        ) {
            unset($_SESSION['cart'][$k]);
        } else {
            $cleanKey = intval($k);
            $cleanVal = intval($v);
            if ($cleanKey !== (int)$k) {
                unset($_SESSION['cart'][$k]);
            }
            $_SESSION['cart'][$cleanKey] = $cleanVal;
        }
    }
}

// --------------------------------------------------------------------
// DATABASE CONNECTION SETTINGS
// --------------------------------------------------------------------
$DB_HOST = 'localhost';
$DB_NAME = 'sweepxpress_db';
$DB_USER = 'root';
$DB_PASS = ''; // default XAMPP has empty password

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Sorry, the site is temporarily unavailable. Please try again later.");
}

// --------------------------------------------------------------------
// USER ROLE CHECK FUNCTIONS
// --------------------------------------------------------------------
function is_logged_in() {
    // I-check lang kung may 'user' array sa session
    return isset($_SESSION['user']);
}

// *** Layo 65: Inayos upang iwasan ang Undefined Array Key Warning ***
function is_admin() {
    // I-check kung naka-set ang $_SESSION['user'] AT kung may 'role' key AT kung ang value ay 'admin'
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
}

/**
 * Checks if the currently logged-in user has the 'supplier' role.
 * @return bool
 */
// *** Layo 73: Inayos upang iwasan ang Undefined Array Key Warning ***
function is_supplier() {
    // I-check kung naka-set ang $_SESSION['user'] AT kung may 'role' key AT kung ang value ay 'supplier'
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'supplier';
}

// --------------------------------------------------------------------
// SANITIZATION HELPER
// --------------------------------------------------------------------
function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// --------------------------------------------------------------------
// USERNAME GENERATOR (register.php, google_callback.php)
// --------------------------------------------------------------------
// Unang salita ng pangalan + 4 random digits, hal. "juan4821".
// Hindi email prefix para hindi malantad ang email address ng user.
function generate_username(PDO $pdo, $name) {
    $first = strtolower(strtok(trim((string)$name), ' ') ?: '');
    $base  = substr(preg_replace('/[^a-z0-9]/', '', $first), 0, 20);
    if ($base === '') {
        $base = 'user';
    }

    $check = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    for ($i = 0; $i < 5; $i++) {
        $candidate = $base . random_int(1000, 9999);
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
    }
    // Napakabihira: 5 beses nagkapareho, kaya gumamit ng mas mahabang suffix
    return $base . bin2hex(random_bytes(4));
}

// --------------------------------------------------------------------
// SOCIAL LOGIN (GOOGLE & FACEBOOK)
// --------------------------------------------------------------------

// Make sure Composer dependencies exist (for Google/Facebook SDK)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    // Composer packages aren't installed yet — don't crash the whole site,
    // just skip loading them. Run `composer install` on the server to fix
    // Google/Facebook login.
    error_log('vendor/autoload.php not found — run composer install.');
}


// Include OAuth credentials if file exists
if (file_exists(__DIR__ . '/config_oauth.php')) {
    require_once __DIR__ . '/config_oauth.php';
}

if (!function_exists('generateSKU')) {
    function generateSKU($productName) {
        $prefix = strtoupper(substr(preg_replace('/\s+/', '', $productName), 0, 3));
        $randomNumber = rand(1000, 9999);
        return $prefix . $randomNumber;
    }
}

date_default_timezone_set('Asia/Manila');


// --------------------------------------------------------------------
// END OF CONFIG
// --------------------------------------------------------------------
?>