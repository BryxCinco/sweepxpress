<?php
// ============================================================
// SweepXpress - Email Utility (FIXED)
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// env() helper para sa mail() fallback (walang Composer dependency, hindi nagtha-throw)
require_once __DIR__ . '/env_loader.php';

// Check if vendor/autoload.php exists
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
} else {
    error_log("PHPMailer not found. Please run: composer require phpmailer/phpmailer");
}

/**
 * Send OTP email using PHPMailer
 */
function send_otp_email($recipient_email, $otp_code) {
    // Check if PHPMailer is available
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer not available, using fallback mail function");
        return send_otp_email_fallback($recipient_email, $otp_code);
    }
    
    $mail = null;

    try {
        // Server settings galing sa includes/email_config.php (.env)
        // Nilo-load dito (hindi sa taas) para hindi mag-crash ang login.php
        // kapag wala pang mail config.
        require_once __DIR__ . '/email_config.php';
        $mail = make_mailer();
        $mail->CharSet    = 'UTF-8';

        // Recipients
        $mail->setFrom(MAIL_USERNAME, 'SweepXpress Support');
        $mail->addAddress($recipient_email);

        // Content
        $mail->isHTML(true);
        $mail->Subject = '🔐 SweepXpress: Your One-Time Login Code';
        $mail->Body    = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background: #f8f9fa; padding: 20px; }
                    .container { max-width: 500px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
                    .logo { color: #3366ff; font-size: 24px; font-weight: 800; }
                    .otp-code { background: #f0f7ff; padding: 20px; border-radius: 8px; text-align: center; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #1a1a1a; border: 2px dashed #dbeafe; margin: 20px 0; }
                    .footer { color: #9ca3af; font-size: 12px; text-align: center; margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2 class='logo'>sweepXpress</h2>
                    <p style='color: #374151; font-size: 16px;'>Hello!</p>
                    <p style='color: #374151; font-size: 16px;'>Use the following code to complete your login attempt:</p>
                    <div class='otp-code'>{$otp_code}</div>
                    <p style='color: #6b7280; font-size: 14px;'>
                        This code will expire in <strong>5 minutes</strong>.<br>
                        If you did not request this, please ignore this email.
                    </p>
                    <div class='footer'>
                        &copy; " . date('Y') . " SweepXpress. All rights reserved.
                    </div>
                </div>
            </body>
            </html>
        ";
        $mail->AltBody = "Your SweepXpress OTP is: {$otp_code}. This code will expire in 5 minutes.";

        return $mail->send();
        
    } catch (Throwable $e) {
        $error = $mail ? $mail->ErrorInfo : $e->getMessage();
        error_log("Email sending failed. Mailer Error: {$error}");
        // Try fallback
        return send_otp_email_fallback($recipient_email, $otp_code);
    }
}

/**
 * Fallback email function using PHP mail()
 */
function send_otp_email_fallback($recipient_email, $otp_code) {
    $subject = "🔐 SweepXpress: Your One-Time Login Code";
    $message = "Your SweepXpress OTP is: {$otp_code}\n\nThis code will expire in 5 minutes.\n\nIf you did not request this, please ignore this email.\n\nSweepXpress Team";
    $from    = env('MAIL_USERNAME');
    $headers = $from ? "From: {$from}\r\nReply-To: {$from}\r\n" : '';
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($recipient_email, $subject, $message, $headers);
}
?>