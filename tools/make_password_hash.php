<?php
// tools/make_password_hash.php — gumagawa ng password hash para sa database/demo/accounts.sql. CLI lang:
//     php tools/make_password_hash.php
// Itatanong ang password na gusto mo. Ang HASH lang ang ipapakita; ang password ay hindi nililimbag o iniimbak.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDOUT, "Choose the password for the demo accounts (you will type it visibly; it is never saved or printed).\n");
fwrite(STDOUT, "Password: ");
$password = rtrim((string)fgets(STDIN), "\r\n");

if ($password === '') {
    fwrite(STDERR, "No password entered.\n");
    exit(1);
}

// Parehong patakaran ng register at change-password ng site: 10+ character, may titik, numero at simbolo
if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{10,}$/', $password)) {
    fwrite(STDOUT, "Note: this does not meet the site's password rule (10+ characters with a letter, a number and a symbol).\n");
    fwrite(STDOUT, "It will still work for logging in, but the demo user could not set the same password in Settings.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);
fwrite(STDOUT, "\nPaste this line into database/demo/accounts.sql, replacing the existing SET @demo_hash line:\n\n");
fwrite(STDOUT, "SET @demo_hash := '" . $hash . "';\n\n");
