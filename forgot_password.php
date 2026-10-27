<?php
// forgot_password.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_config.php';

$message = '';
$message_type = '';          // 'error' o 'success' (dati: hinuhulaan mula sa Bootstrap class sa HTML)
$form_was_submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_was_submitted = true;
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT id, reset_token_expiry FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Rate limit: isang reset email lang kada 60 segundo bawat email.
        // 1 oras ang validity ng token, kaya kung > 59 min pa ang natitira, kakapadala lang.
        $recentlySent = $user && !empty($user['reset_token_expiry'])
            && strtotime($user['reset_token_expiry']) - time() > 3540;
        // Rate limit kada IP: max 10 reset email kada oras (kahit iba-ibang email)
        $ipLimited = rate_limited($pdo, 'forgot_ip', 10, 3600);

        if ($user && !$recentlySent && !$ipLimited) {
            rate_limit_record($pdo, 'forgot_ip');
            try {
                $token = bin2hex(random_bytes(32));
                $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));

                // ✅ Store token & expiry inside `users` table
                $update = $pdo->prepare("
                    UPDATE users 
                    SET password_reset_token = ?, reset_token_expiry = ? 
                    WHERE email = ?
                ");
                $update->execute([$token, $expires, $email]);

                $reset_link = rtrim(env('APP_URL', 'http://localhost/sweepxpress'), '/')
                    . "/reset_password.php?token=$token&email=" . urlencode($email);

                // Send email
                $mail->ClearAllRecipients();
                $mail->addAddress($email);
                $mail->Subject = "Password Reset Link";
                $mail->Body = "
                    Hello,<br><br>
                    Click the link below to reset your password:<br><br>
                    <a href='$reset_link'>$reset_link</a><br><br>
                    This link will expire in 1 hour. If you did not request this, please ignore this email.
                ";
                $mail->isHTML(true);
                $mail->send();

            } catch (Exception $e) {
                error_log("Mailer Error: " . $e->getMessage());
            }
        }

        // Generic success message for security
        $message = 'If your email is registered, a password reset link has been sent. Please check your inbox or spam folder.';
        $message_type = 'success';
    }
}
?>
<?php
require_once __DIR__ . '/includes/auth_layout.php';
sx_auth_start('Forgot password', '/sweepxpress/login.php', 'Back to login');
?>
            <h1>Forgot your password?</h1>
            <p class="sx-auth-sub">Enter your account email and we'll send you a link to set a new password.</p>

            <?php if ($message): ?>
                <div class="sx-alert sx-alert-<?= $message_type === 'error' ? 'error' : 'success' ?>" role="<?= $message_type === 'error' ? 'alert' : 'status' ?>">
                    <i class="fa-solid <?= $message_type === 'error' ? 'fa-circle-exclamation' : 'fa-envelope-circle-check' ?>"></i><div><?= htmlspecialchars($message) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!$form_was_submitted || $message_type === 'error'): ?>
                <form method="post" id="forgotForm">
                    <div class="sx-field">
                        <label class="sx-label" for="email_input">Email address</label>
                        <input type="email" class="sx-input" id="email_input" name="email" placeholder="you@example.com" autocomplete="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <button type="submit" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block">
                        <i class="fa-solid fa-paper-plane"></i> Send reset link
                    </button>
                </form>
            <?php else: ?>
                <a href="/sweepxpress/login.php" class="sx-btn sx-btn-outline sx-btn-lg sx-btn-block">Back to login</a>
            <?php endif; ?>

            <p class="sx-auth-foot">Remembered it? <a href="/sweepxpress/login.php">Log in</a></p>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('forgotForm');
    if (form) form.addEventListener('submit', () => { form.querySelector('button').disabled = true; });
});
</script>
<?php sx_auth_end(); ?>
