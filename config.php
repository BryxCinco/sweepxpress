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
// Galing sa .env (DB_HOST, DB_NAME, DB_USER, DB_PASS). Kapag wala sa .env,
// default ng local XAMPP ang gagamitin, kaya gumagana pa rin ang local nang walang binabago.
require_once __DIR__ . '/includes/env_loader.php';
$DB_HOST = env('DB_HOST', 'localhost');
$DB_NAME = env('DB_NAME', 'sweepxpress_db');
$DB_USER = env('DB_USER', 'root');
$DB_PASS = env('DB_PASS', ''); // default XAMPP: walang password

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
    // Pareho ang oras ng database at PHP (Asia/Manila, walang DST). Kung hindi, "OTP expired" agad
    // sa host na iba ang timezone ng database (hal. -15 oras sa InfinityFree).
    $pdo->exec("SET time_zone = '+08:00'");
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
// SPAM GUARD (honeypot + time trap) — register.php, contact.php
// --------------------------------------------------------------------
// Tawagin sa pag-render ng form: naglalabas ng nakatagong honeypot field
// at itinatala sa session kung kailan binuksan ang form.
function spam_guard_field($form) {
    $_SESSION['spam_guard'][$form] = time();
    return '<div style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">'
         . '<label>Leave this field empty <input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>'
         . '</div>';
}

// Tawagin sa POST: true kapag mukhang bot — napunan ang honeypot,
// walang record na binuksan ang form, o na-submit nang wala pang $min_seconds.
function spam_guard_failed($form, $min_seconds = 3) {
    $opened = $_SESSION['spam_guard'][$form] ?? 0;
    return ($_POST['website'] ?? '') !== ''
        || $opened === 0
        || time() - $opened < $min_seconds;
}

// --------------------------------------------------------------------
// RATE LIMIT (rate_limits table) — register, login, contact, forgot password
// --------------------------------------------------------------------
// REMOTE_ADDR lang ang pinagkakatiwalaan; madaling pekein ang X-Forwarded-For.
function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// true kapag naabot na ang $max na attempt sa huling $seconds (hindi nagtatala).
// Kapag may DB error (hal. wala pang table sa server), hinahayaan ang request.
function rate_limited(PDO $pdo, $action, $max, $seconds, $key = null) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = ? AND rl_key = ? AND created_at > ?");
        $stmt->execute([$action, substr((string)($key ?? client_ip()), 0, 64), date('Y-m-d H:i:s', time() - $seconds)]);
        return (int)$stmt->fetchColumn() >= $max;
    } catch (PDOException $e) {
        error_log("rate_limited failed: " . $e->getMessage());
        return false;
    }
}

// Nagtatala ng isang attempt. Mga 1 sa 100 tawag, binubura ang rows na lampas 24 oras.
function rate_limit_record(PDO $pdo, $action, $key = null) {
    try {
        $pdo->prepare("INSERT INTO rate_limits (action, rl_key, created_at) VALUES (?, ?, ?)")
            ->execute([$action, substr((string)($key ?? client_ip()), 0, 64), date('Y-m-d H:i:s')]);
        if (random_int(1, 100) === 1) {
            $pdo->prepare("DELETE FROM rate_limits WHERE created_at < ?")
                ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
    } catch (PDOException $e) {
        error_log("rate_limit_record failed: " . $e->getMessage());
    }
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