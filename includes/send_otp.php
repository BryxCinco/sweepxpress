<?php
// includes/send_otp.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Tiyakin na ang path ay tama. Ito ay lalabas sa root folder ng project.
require __DIR__ . '/../vendor/autoload.php';

function sendOTP($email, $otp) {
    $mail = null;

    try {
        // Server Settings galing sa includes/email_config.php (.env)
        // Nilo-load dito (hindi sa taas) para hindi mag-crash ang register.php
        // kapag wala pang mail config.
        require_once __DIR__ . '/email_config.php';
        $mail = make_mailer();

        // Recipients
        $mail->setFrom(MAIL_USERNAME, 'SweepXpress Verification');
        $mail->addAddress($email);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'SweepXpress: Your Account Verification Code';
        $mail->Body = "
            <html>
            <body>
                <h3 style='color: #3366ff;'>Hello!</h3>
                <p>Use the following code to complete your registration:</p>
                <h1 style='background-color: #f0f7ff; padding: 15px; border-radius: 8px; text-align: center; color: #000000; font-size: 24px;'>
                    {$otp}
                </h1>
                <p>This code will expire in 5 minutes. If you did not request this, please ignore this email.</p>
                <p>Thank you,<br>SweepXpress Team</p>
            </body>
            </html>
        ";

        $mail->send();
        return true;
    } catch (Throwable $e) {
        // Ito ay magre-return ng FALSE kung may error sa pagpapadala ng email
        $error = $mail ? $mail->ErrorInfo : $e->getMessage();
        error_log("OTP Email failed to send to {$email}. Error: {$error}");
        return false;
    }
}