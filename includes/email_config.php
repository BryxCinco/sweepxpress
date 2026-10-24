<?php
// includes/email_config.php — SINGLE source of truth for mail credentials.
// All values come from .env (see .env.example). No hardcoded secrets here.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php'; // siguraduhing naka-install ang PHPMailer via Composer
require_once __DIR__ . '/env_loader.php';

// Mail Server Configuration (from .env)
if (!defined('MAIL_APP_PASSWORD')) {
    $appPassword = str_replace(' ', '', (string) env('MAIL_APP_PASSWORD', ''));
    if ($appPassword === '' || $appPassword === 'changeme') {
        throw new RuntimeException('MAIL_APP_PASSWORD is not set. Add it to the .env file in the project root (see .env.example).');
    }
    if (!env('MAIL_USERNAME')) {
        throw new RuntimeException('MAIL_USERNAME is not set. Add it to the .env file in the project root (see .env.example).');
    }

    define('MAIL_HOST', env('MAIL_HOST', 'smtp.gmail.com'));
    define('MAIL_PORT', (int) env('MAIL_PORT', 587));
    define('MAIL_USERNAME', env('MAIL_USERNAME'));
    define('MAIL_APP_PASSWORD', $appPassword);
    define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'SweepXpress'));
    define('MAIL_SECURE', MAIL_PORT === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS);
    unset($appPassword);
}

// Returns a ready-to-use, pre-authenticated PHPMailer object.
if (!function_exists('make_mailer')) {
    function make_mailer(): PHPMailer {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_APP_PASSWORD;
        $mail->SMTPSecure = MAIL_SECURE;
        $mail->Port       = MAIL_PORT;
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->isHTML(true);
        return $mail;
    }
}

// Gumawa ng ready-to-use PHPMailer object (used by forgot_password.php)
try {
    $mail = make_mailer();
} catch (Exception $e) {
    echo "Mailer Error: {$e->getMessage()}";
    exit;
}
?>
