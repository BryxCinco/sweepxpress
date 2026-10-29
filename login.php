<?php
// -------------------------------------------------------------------
// login.php - SweepXpress
// Full working version with OTP verification and logging fix
// -------------------------------------------------------------------
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_utility.php';
require_once __DIR__ . '/includes/log_helper.php'; // FIXED: Added logError function include

// Demo accounts (README, database/demo/accounts.sql): walang totoong mailbox ang tatlong demo address, kaya
// walang OTP kapag DEMO_MODE=1 sa .env. Tatlong eksaktong email lang, at laging patay kung walang DEMO_MODE=1.
// HUWAG buksan ang DEMO_MODE sa site na may totoong customer.
function sx_demo_login_allowed(string $email): bool
{
    return env('DEMO_MODE', '0') === '1'
        && in_array(strtolower($email), ['admin@demo.sweepxpress.com', 'customer@demo.sweepxpress.com', 'business@demo.sweepxpress.com'], true);
}

// --- Initialize variables ---
$msg = '';
$login_error = '';
$success = '';
$show_otp = false;

// Mensaheng iniwan ng google_callback.php (hal. suspendido ang account); isang beses lang ipapakita
if (!empty($_SESSION['login_notice'])) {
    $msg = (string)$_SESSION['login_notice'];
    unset($_SESSION['login_notice']);
}

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    header("Location: " . (is_admin() ? "/sweepxpress/admin/dashboard.php" : "/sweepxpress/index.php"));
    exit;
}

// Check if OTP session is still valid
if (isset($_SESSION['pending_user_id'])) {
    try {
        $stmt_check = $pdo->prepare("SELECT id FROM users WHERE id=? AND otp_expires > NOW() LIMIT 1");
        $stmt_check->execute([$_SESSION['pending_user_id']]);
        if ($stmt_check->fetch()) {
            $show_otp = true;
        } else {
            unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent']);
            $login_error = "Your previous OTP session expired. Please log in again.";
        }
    } catch (Exception $e) {
        logError($e->getMessage());
        $login_error = "An unexpected error occurred. Please try again.";
    }
}

// Handle Back to Login
if (isset($_GET['action']) && $_GET['action'] === 'back_to_login') {
    if (isset($_SESSION['pending_user_id'])) {
        $uid_to_clear = $_SESSION['pending_user_id'];
        if ($uid_to_clear) {
            $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL, otp_last_sent=NULL WHERE id=?")->execute([$uid_to_clear]);
        }
        unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent']);
    }
    header("Location: /sweepxpress/login.php");
    exit;
}

// ----------------------------------------------------
// 1. Handle Login Form Submission
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && !isset($_POST['verify_otp']) && !isset($_POST['resend_otp'])) {
    $email = trim($_POST['email']);
    $pass = trim($_POST['password']);
    // Key para sa per-account limit: hash ng email, hindi ang email mismo
    $emailKey = hash('sha256', strtolower($email));

    if ($email && $pass && (rate_limited($pdo, 'login_fail_ip', 20, 900)
                         || rate_limited($pdo, 'login_fail_email', 10, 900, $emailKey))) {
        $msg = "Too many login attempts. Please try again in 15 minutes.";
    } elseif ($email && $pass) {
        try {
            $stmt = $pdo->prepare("SELECT id, name, username, profile_image, phone, role, email, password_hash, suspended_until FROM users WHERE email=? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($pass, $user['password_hash'])) {
                // Check suspension
                if (!empty($user['suspended_until']) && new DateTime() < new DateTime($user['suspended_until'])) {
                    $expiry_date = (new DateTime($user['suspended_until']))->format('F d, Y h:i A');
                    $msg = "Your account is suspended until $expiry_date";
                    log_user_action($pdo, (int)$user['id'], 'LOGIN_BLOCKED', "Login refused: account suspended until $expiry_date");
                } elseif (sx_demo_login_allowed($user['email'])) {
                    // Demo account: diretso na, walang OTP
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'username' => $user['username'] ?? '',
                        'profile_image' => $user['profile_image'] ?? null,
                        'phone' => $user['phone'] ?? '',
                        'role' => $user['role'],
                        'email' => $user['email']
                    ];
                    log_user_action($pdo, (int)$user['id'], 'LOGIN_SUCCESS', 'Signed in with password (demo account, no OTP)');
                    header("Location: " . ($user['role'] === 'admin' ? "/sweepxpress/admin/dashboard.php" : "/sweepxpress/index.php"));
                    exit;
                } else {
                    // Generate OTP
                    $otp = random_int(100000, 999999);
                    $stmt_otp = $pdo->prepare("UPDATE users SET otp_code=?, otp_expires=DATE_ADD(NOW(), INTERVAL 5 MINUTE), otp_last_sent=NOW() WHERE id=?");
                    $stmt_otp->execute([(string)$otp, $user['id']]);

                    if (send_otp_email($user['email'], $otp) === true) {
                        $_SESSION['pending_user_id'] = $user['id'];
                        $_SESSION['otp_last_sent'] = time();
                        $_SESSION['otp_attempts'] = 0;
                        $show_otp = true;
                        $success = "OTP sent to your email. It will expire in 5 minutes.";
                    } else {
                        $msg = "Failed to send OTP. Contact support.";
                        $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL, otp_last_sent=NULL WHERE id=?")->execute([$user['id']]);
                    }
                }
            } else {
                // Maling email/password lang ang binibilang (hindi ang matagumpay na login)
                rate_limit_record($pdo, 'login_fail_ip');
                rate_limit_record($pdo, 'login_fail_email', $emailKey);
                if ($user) log_user_action($pdo, (int)$user['id'], 'LOGIN_FAILURE', 'Wrong password');
                $msg = "Invalid email or password.";
            }
        } catch (Exception $e) {
            logError($e->getMessage());
            $msg = "An unexpected error occurred. Please try again.";
        }
    } else {
        $msg = "Please enter email and password.";
    }

    unset($_POST['password']);
}

// ----------------------------------------------------
// 2. Handle OTP Verification
// ----------------------------------------------------
if (isset($_POST['verify_otp'])) {
    $show_otp = true;
    $login_error = '';

    if (isset($_SESSION['pending_user_id'])) {
        $uid = $_SESSION['pending_user_id'];
        $otp_input = trim($_POST['otp_code'] ?? '');

        if (empty($otp_input) || strlen($otp_input) !== 6) {
            $login_error = "Please enter the full 6-digit OTP code.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, username, profile_image, phone, role, email, otp_code, otp_expires FROM users WHERE id=? LIMIT 1");
                $stmt->execute([$uid]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);

                $db_otp_clean = trim((string)($u['otp_code'] ?? ''));
                $input_otp_clean = trim($otp_input);

                if ($u && !empty($db_otp_clean) && $input_otp_clean === $db_otp_clean) {
                    if (strtotime($u['otp_expires']) > time()) {
                        $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL, otp_last_sent=NULL WHERE id=?")->execute([$uid]);

                        unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent'], $_SESSION['otp_attempts']);
                        $_SESSION['user'] = [
                            'id' => $u['id'],
                            'name' => $u['name'],
                            'username' => $u['username'] ?? '',
                            'profile_image' => $u['profile_image'] ?? null,
                            'phone' => $u['phone'] ?? '',
                            'role' => $u['role'],
                            'email' => $u['email']
                        ];
                        log_user_action($pdo, (int)$u['id'], 'LOGIN_SUCCESS', 'Signed in with email and OTP');

                        // Admin → dashboard; lahat ng iba → homepage
                        header("Location: " . ($u['role'] === 'admin' ? "/sweepxpress/admin/dashboard.php" : "/sweepxpress/index.php"));
                        exit;
                    } else {
                        $login_error = "OTP expired. Please log in again.";
                        $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL WHERE id=?")->execute([$uid]);
                        unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent']);
                        $show_otp = false;
                    }
                } else {
                    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;
                    if ($_SESSION['otp_attempts'] >= 5) {
                        $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL WHERE id=?")->execute([$uid]);
                        unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent'], $_SESSION['otp_attempts']);
                        $show_otp = false;
                        log_user_action($pdo, (int)$uid, 'LOGIN_FAILURE', 'Too many wrong OTP codes');
                        $login_error = "Too many incorrect attempts. Please log in again.";
                    } else {
                        $login_error = "Incorrect OTP. Try again or resend.";
                    }
                }
            } catch (Exception $e) {
                logError($e->getMessage());
                $login_error = "An unexpected error occurred. Please try again.";
            }
        }
    } else {
        $login_error = "Session expired. Please log in again.";
        $show_otp = false;
    }

    if ($login_error) {
        $success = '';
        $msg = '';
    }
}

// ----------------------------------------------------
// 3. Handle OTP Resend
// ----------------------------------------------------
if (isset($_POST['resend_otp'])) {
    $show_otp = true;
    $login_error = '';

    if (isset($_SESSION['pending_user_id'])) {
        $uid = $_SESSION['pending_user_id'];
        $cooldown_passed = (time() - ($_SESSION['otp_last_sent'] ?? 0) >= 60);

        if (!$cooldown_passed) {
            $remain = 60 - (time() - ($_SESSION['otp_last_sent'] ?? 0));
            $login_error = "Wait $remain seconds before resending OTP.";
        } else {
            try {
                $stmt_email = $pdo->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
                $stmt_email->execute([$uid]);
                $e_data = $stmt_email->fetch(PDO::FETCH_ASSOC);
                $email_to_send = $e_data['email'] ?? null;
                $otp = random_int(100000, 999999);

                $pdo->prepare("UPDATE users SET otp_code=?, otp_expires=DATE_ADD(NOW(), INTERVAL 5 MINUTE), otp_last_sent=NOW() WHERE id=?")
                    ->execute([(string)$otp, $uid]);

                if ($email_to_send && send_otp_email($email_to_send, $otp) === true) {
                    $_SESSION['otp_last_sent'] = time();
                    $_SESSION['otp_attempts'] = 0;
                    $success = "A new OTP has been sent. Check inbox/spam. It will expire in 5 minutes.";
                } else {
                    $login_error = "Failed to resend OTP. Contact support.";
                }
            } catch (Exception $e) {
                logError($e->getMessage());
                $login_error = "An unexpected error occurred. Please try again.";
            }
        }
    } else {
        $login_error = "Session expired. Please log in again.";
        $show_otp = false;
    }

    if ($login_error) {
        $success = '';
        $msg = '';
    }
}
?>
<?php
require_once __DIR__ . '/includes/auth_layout.php';
sx_auth_start(
    $show_otp ? 'Verify sign-in' : 'Log in',
    $show_otp ? '/sweepxpress/login.php?action=back_to_login' : '/sweepxpress/index.php',
    $show_otp ? 'Back to login' : 'Back to shop'
);
?>
            <?php if ($show_otp): ?>
                <h1>Check your email</h1>
                <p class="sx-auth-sub">Enter the 6-digit code we sent to your email to finish signing in.</p>
            <?php else: ?>
                <h1>Welcome back</h1>
                <p class="sx-auth-sub">Log in to your SweepXpress account.</p>
            <?php endif; ?>

            <?php if ($msg || $login_error): ?>
                <div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= htmlspecialchars($msg ?: $login_error) ?></div></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="sx-alert sx-alert-success" role="status"><i class="fa-solid fa-circle-check"></i><div><?= htmlspecialchars($success) ?></div></div>
            <?php endif; ?>

            <?php if ($show_otp): ?>
                <form method="POST" id="otpForm">
                    <label class="sx-label" for="otp-0" style="display:block; margin-bottom: var(--sx-space-2)">Verification code</label>
                    <div class="sx-otp" id="otpBoxes">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" id="otp-<?= $i ?>" inputmode="numeric" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" maxlength="6" aria-label="Digit <?= $i + 1 ?>">
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="otp_code" id="hidden_otp_code" required>
                    <button type="submit" name="verify_otp" id="verifyOtpButton" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block" disabled>
                        <i class="fa-solid fa-shield-halved"></i> Verify &amp; log in
                    </button>
                </form>
                <div class="sx-otp-resend">
                    <span>Didn't get the code?</span>
                    <form method="POST"><button type="submit" name="resend_otp" class="sx-link-btn">Resend code</button></form>
                </div>
            <?php else: ?>
                <form method="POST" id="loginForm">
                    <div class="sx-field">
                        <label class="sx-label" for="email">Email address</label>
                        <input type="email" id="email" name="email" class="sx-input" placeholder="you@example.com" autocomplete="email" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="sx-field">
                        <label class="sx-label" for="password">Password</label>
                        <div class="sx-pass">
                            <input type="password" id="password" name="password" class="sx-input" placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" data-sx-toggle="password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="sx-auth-link-row"><a href="/sweepxpress/forgot_password.php">Forgot password?</a></div>
                    <button type="submit" id="loginButton" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block sx-btn-arrow">
                        Log in <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                    </button>
                </form>

                <div class="sx-divider">or</div>
                <a href="/sweepxpress/google_login.php" class="sx-btn sx-btn-outline sx-btn-lg sx-btn-block"><?= sx_google_icon() ?> Continue with Google</a>

                <p class="sx-auth-foot">Don't have an account? <a href="/sweepxpress/register.php">Sign up</a></p>
            <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    sxPasswordToggles();
    sxOtpBoxes(document.getElementById('otpBoxes'), document.getElementById('hidden_otp_code'), document.getElementById('verifyOtpButton'));

    // Iwas dobleng submit
    ['loginForm', 'otpForm'].forEach(id => {
        const form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            setTimeout(() => { btn.disabled = true; }, 0); // pagkatapos maipadala ang name ng button (verify_otp)
        });
    });
});
</script>
<?php sx_auth_end(); ?>
