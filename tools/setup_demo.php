<?php
// tools/setup_demo.php — gumagawa ng tatlong demo account (admin, customer, business). CLI lang:
//
//     php tools/setup_demo.php                      gawin ang mga account na wala pa
//     php tools/setup_demo.php --reset-password     ibalik din ang password ng mga umiiral na demo account
//     php tools/setup_demo.php --password="..."     gumamit ng ibang password kaysa sa default sa ibaba
//     php tools/setup_demo.php --allow-remote       payagan kahit hindi localhost ang database (iwasan)
//
// Ligtas patakbuhin ulit: hindi gumagawa ng doble at hindi ginagalaw ang account na umiiral na.
// Ang password ay hina-hash dito mismo (password_hash); ang plain text ay hindi iniimbak sa database.
//
// PAALALA: ang default na password sa ibaba ay nakasulat sa source code, at pampubliko ang repository. Para sa
// demo lang ito. Ang tatlong account ay may kilalang password, kaya HUWAG patakbuhin ito sa database na may
// totoong customer. Para sa mga demo account ay mag-login nang walang OTP, ilagay ang DEMO_MODE=1 sa .env ng
// LOCAL na site (tingnan ang README).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const DEMO_DEFAULT_PASSWORD = 'SweepXpress2026!';

$opts = getopt('', ['password::', 'reset-password', 'allow-remote', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php tools/setup_demo.php [--reset-password] [--password=...] [--allow-remote]\n";
    exit(0);
}

// Basahin muna ang .env at tingnan kung saang database tatama, BAGO kumonekta
require dirname(__DIR__) . '/includes/env_loader.php';
$dbHost = (string)env('DB_HOST', 'localhost');
$dbName = (string)env('DB_NAME', 'sweepxpress_db');
$isLocal = in_array(strtolower($dbHost), ['localhost', '127.0.0.1', '::1'], true);
if (!$isLocal && !isset($opts['allow-remote'])) {
    fwrite(STDERR, "Refusing to run: DB_HOST is \"{$dbHost}\", not this computer.\n");
    fwrite(STDERR, "The demo accounts have a known password, so they must not be created in a database with real customers.\n");
    fwrite(STDERR, "If this really is a demo-only database, run again with --allow-remote.\n");
    exit(1);
}

$password = isset($opts['password']) && $opts['password'] !== false && $opts['password'] !== '' ? (string)$opts['password'] : DEMO_DEFAULT_PASSWORD;
if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{10,}$/', $password)) {
    echo "Note: this password does not meet the site's rule (10+ characters with a letter, a number and a symbol).\n";
    echo "It will still sign in, but the demo user could not set the same password in Settings.\n\n";
}

require dirname(__DIR__) . '/config.php';   // $pdo

$accounts = [
    ['name' => 'Demo Admin',    'username' => 'demo_admin',    'email' => 'admin@demo.sweepxpress.com',    'role' => 'admin',
     'phone' => null, 'street' => null, 'city' => null, 'zip' => null],
    ['name' => 'Demo Customer', 'username' => 'demo_customer', 'email' => 'customer@demo.sweepxpress.com', 'role' => 'customer',
     'phone' => '09170000001', 'street' => '123 Demo Street, Brgy. Sample', 'city' => 'Quezon City', 'zip' => '1100'],
    ['name' => 'Demo Business', 'username' => 'demo_business', 'email' => 'business@demo.sweepxpress.com', 'role' => 'business',
     'phone' => '09170000002', 'street' => '456 Sample Avenue, Brgy. Demo', 'city' => 'Makati City', 'zip' => '1200'],
];

echo "Database: {$dbName} on {$dbHost}\n\n";

try {
    $hash   = password_hash($password, PASSWORD_DEFAULT);
    $find   = $pdo->prepare('SELECT id, email FROM users WHERE email = ? OR username = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, is_verified, role, phone, streetaddress, city, zipcode)
                             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)');
    $reset  = $pdo->prepare('UPDATE users SET password_hash = ?, is_verified = 1, otp_code = NULL, otp_expires = NULL WHERE id = ?');
    $result = [];

    $pdo->beginTransaction();
    foreach ($accounts as $a) {
        $find->execute([$a['email'], $a['username']]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $insert->execute([$a['name'], $a['username'], $a['email'], $hash, $a['role'], $a['phone'], $a['street'], $a['city'], $a['zip']]);
            $result[] = [$a['email'], $a['role'], 'created'];
        } elseif (strcasecmp($row['email'], $a['email']) !== 0) {
            // may ibang account na gumagamit ng username na ito; huwag galawin
            $result[] = [$a['email'], $a['role'], 'SKIPPED: username "' . $a['username'] . '" is used by ' . $row['email']];
        } elseif (isset($opts['reset-password'])) {
            $reset->execute([$hash, $row['id']]);
            $result[] = [$a['email'], $a['role'], 'already existed, password reset'];
        } else {
            $result[] = [$a['email'], $a['role'], 'already exists (left as is)'];
        }
    }

    // Ikabit ang ilang demo order (galing sa database/demo/sales.sql) para may laman ang "My orders"
    $linked = [];
    foreach ([['customer@demo.sweepxpress.com', 'B2C', 'Demo Customer A'], ['business@demo.sweepxpress.com', 'B2B', 'Demo Company A']] as [$email, $type, $who]) {
        $n = $pdo->prepare("UPDATE orders o JOIN users u ON u.email = ?
                               SET o.user_id = u.id
                             WHERE o.notes LIKE '[DEMO]%' AND o.order_type = ? AND o.customer_name = ? AND o.user_id IS NULL");
        $n->execute([$email, $type, $who]);
        $linked[$email] = $n->rowCount();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Failed, nothing was changed: ' . $e->getMessage() . "\n");
    exit(1);
}

foreach ($result as [$email, $role, $status]) {
    printf("  %-32s %-9s %s\n", $email, $role, $status);
}
$linkedTotal = array_sum($linked);
if ($linkedTotal > 0) {
    echo "\nLinked {$linkedTotal} demo order(s) to the demo customer and business accounts.\n";
}

echo "\nDone. Password: " . (isset($opts['password']) ? 'the one you passed with --password' : 'the default defined at the top of tools/setup_demo.php') . ".\n";
echo "Sign-in normally asks for an emailed code, and these addresses have no mailbox.\n";
echo "On your LOCAL site add  DEMO_MODE=1  to .env to skip the code for these three accounts only. Keep it 0 on the live site.\n";
exit(0);
