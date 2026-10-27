<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';
$error = '';
$success = '';
$user = null;
$min_length = 10; // pareho sa register.php
$password_regex = '/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{10,}$/'; // letra + numero + special, 10+ chars

// --- Initial Token and Expiry Validation ---
if (empty($token) || empty($email)) {
    $error = "Invalid or missing reset link parameters.";
} else {
    try {
        $stmt = $pdo->prepare("
            SELECT id, reset_token_expiry 
            FROM users 
            WHERE password_reset_token = :token AND email = :email 
            LIMIT 1
        ");
        $stmt->execute([':token' => $token, ':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "The password reset link is invalid or has already been used.";
        } elseif (strtotime($user['reset_token_expiry']) < time()) {
            $error = "The reset link has expired. Please request a new one.";
        }
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        $error = "A system error occurred. Please try again later.";
    }
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && $user) {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (!preg_match($password_regex, $new_password)) {
        $error = "Password must be at least {$min_length} characters long and include letters, numbers, and special characters.";
    } else {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("
                UPDATE users 
                SET password_hash = :password, password_reset_token = NULL, reset_token_expiry = NULL 
                WHERE id = :id
            ");
            $update->execute([':password' => $hashed_password, ':id' => $user['id']]);

            $success = "Your password has been reset. You can now log in with your new password.";
            $user = null; // Prevent form from showing
        } catch (PDOException $e) {
            error_log("Password Update Error: " . $e->getMessage());
            $error = "Database update error. Please try again.";
        }
    }
}
?>
<?php
require_once __DIR__ . '/includes/auth_layout.php';
sx_auth_start('Reset password', '/sweepxpress/login.php', 'Back to login');
?>
            <h1>Set a new password</h1>
            <p class="sx-auth-sub">Choose a strong password you haven't used before.</p>

            <?php if ($error): ?>
                <div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= htmlspecialchars($error) ?></div></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="sx-alert sx-alert-success" role="status"><i class="fa-solid fa-circle-check"></i><div><?= htmlspecialchars($success) ?></div></div>
                <a href="/sweepxpress/login.php" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block sx-btn-arrow">
                    Go to login <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </a>
            <?php elseif (empty($error) && $user): ?>
                <form method="POST" id="resetForm">
                    <div class="sx-field">
                        <label class="sx-label" for="new_password">New password</label>
                        <div class="sx-pass">
                            <input type="password" class="sx-input" id="new_password" name="new_password" minlength="<?= $min_length ?>" autocomplete="new-password" required aria-describedby="passRules">
                            <button type="button" data-sx-toggle="new_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <ul class="sx-rules" id="passRules">
                            <li data-rule="length"><i class="fa-regular fa-circle"></i> 10+ characters</li>
                            <li data-rule="letter"><i class="fa-regular fa-circle"></i> A letter</li>
                            <li data-rule="number"><i class="fa-regular fa-circle"></i> A number</li>
                            <li data-rule="special"><i class="fa-regular fa-circle"></i> A special character</li>
                        </ul>
                    </div>
                    <div class="sx-field">
                        <label class="sx-label" for="confirm_password">Confirm new password</label>
                        <div class="sx-pass">
                            <input type="password" class="sx-input" id="confirm_password" name="confirm_password" minlength="<?= $min_length ?>" autocomplete="new-password" required>
                            <button type="button" data-sx-toggle="confirm_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <span class="sx-error" id="matchError" hidden>Passwords do not match.</span>
                    </div>
                    <button type="submit" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block"><i class="fa-solid fa-key"></i> Reset password</button>
                </form>
            <?php else: ?>
                <a href="/sweepxpress/forgot_password.php" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block">Request a new link</a>
                <p class="sx-auth-foot"><a href="/sweepxpress/login.php">Back to login</a></p>
            <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    sxPasswordToggles();
    sxPasswordRules(document.getElementById('new_password'), document.getElementById('passRules'));

    const form = document.getElementById('resetForm');
    if (!form) return;
    const pass = document.getElementById('new_password');
    const confirmPass = document.getElementById('confirm_password');
    const matchError = document.getElementById('matchError');
    const check = () => {
        const mismatch = confirmPass.value !== '' && confirmPass.value !== pass.value;
        matchError.hidden = !mismatch;
        confirmPass.setCustomValidity(mismatch ? 'Passwords do not match.' : '');
    };
    pass.addEventListener('input', check);
    confirmPass.addEventListener('input', check);
    form.addEventListener('submit', () => { form.querySelector('button[type="submit"]').disabled = true; });
});
</script>
<?php sx_auth_end(); ?>
