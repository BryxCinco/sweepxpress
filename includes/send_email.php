<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';
 // Adjust path if needed

function send_email($to, $subject, $body) {
    $mail = null;
    try {
        // SMTP settings + sender come from includes/email_config.php (.env)
        // Loaded here (not at the top) so pages that include this file
        // don't crash when mail isn't configured yet.
        require_once __DIR__ . '/email_config.php';
        $mail = make_mailer();

        // Recipients
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('Email could not be sent. Mailer Error: ' . ($mail ? $mail->ErrorInfo : $e->getMessage()));
        return false;
    }
}
