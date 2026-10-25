<?php
// -------------------------------------------------------------------
// login.php - SweepXpress
// Full working version with OTP verification and logging fix
// -------------------------------------------------------------------
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_utility.php';
require_once __DIR__ . '/includes/log_helper.php'; // FIXED: Added logError function include

// --- Initialize variables ---
$msg = '';
$login_error = '';
$success = '';
$show_otp = false;

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
            $stmt = $pdo->prepare("SELECT id, name, role, email, password_hash, suspended_until FROM users WHERE email=? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($pass, $user['password_hash'])) {
                // Check suspension
                if (!empty($user['suspended_until']) && new DateTime() < new DateTime($user['suspended_until'])) {
                    $expiry_date = (new DateTime($user['suspended_until']))->format('F d, Y h:i A');
                    $msg = "Your account is suspended until $expiry_date";
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

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SweepXpress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SweepXpress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Modern Clean Login Design (Standard CSS retained from previous response) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #ffffff;
            overflow: hidden;
        }
        
        /* Perfect 50/50 Split */
        .login-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            height: 100vh;
            width: 100vw;
            overflow: hidden;
        }
        
        /* Left Side - Form */
        .form-side {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 2.5rem;
            background: #ffffff;
            overflow: hidden;
            height: 100vh;
            position: relative;
        }
        
        /* Back Button */
        .back-button {
            position: absolute;
            top: 2rem;
            left: 2.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid #e9ecef;
        }
        
        .back-button:hover {
            background: #e9ecef;
            color: #212529;
            transform: translateX(-2px);
        }
        
        .back-button i {
            font-size: 1rem;
        }
        
        .form-content {
            width: 100%;
            max-width: 420px;
        }
        
        /* Brand Logo - Following Design */
        .brand-logo {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 0.375rem;
            letter-spacing: -0.02em;
        }
        
        .brand-logo .sweep {
            color: #3366ff;
        }
        
        .brand-logo .x {
            color: #3366ff;
            text-transform: uppercase;
        }
        
        .brand-logo .press {
            color: #000000;
        }
        
        .brand-tagline {
            font-size: 0.875rem;
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
        
        .welcome-title {
            font-size: 1.625rem;
            font-weight: 800;
            color: #212529;
            margin-bottom: 0.375rem;
            letter-spacing: -0.02em;
        }
        
        .welcome-subtitle {
            font-size: 0.9375rem;
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
        
        /* Alerts */
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 0.875rem;
            margin-bottom: 1.25rem;
            font-size: 0.8125rem;
        }

        .alert-success {
            background: #d1e7dd;
            color: #0a3622;
            border: 1px solid #badbcc;
            border-radius: 10px;
            padding: 0.875rem;
            margin-bottom: 1.25rem;
            font-size: 0.8125rem;
        }
        
        /* Form Inputs */
        .input-group-custom {
            margin-bottom: 1rem;
        }
        
        .input-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.375rem;
        }
        
        .input-field {
            width: 100%;
            padding: 0.75rem 0.875rem;
            border: 1.5px solid #dee2e6;
            border-radius: 10px;
            font-size: 0.9375rem;
            transition: all 0.2s;
            background: #f8f9fa;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #3366ff;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(51, 102, 255, 0.1);
        }

        /* --- Custom CSS for OTP Multi-Box (Option 2) --- */
        .otp-container {
            display: flex;
            gap: 8px; /* Layo ng bawat box */
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .otp-box {
            width: 48px; /* Laki ng box */
            height: 60px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            border: 1.5px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.2s;
            background: #f8f9fa;
            /* Ensure it only shows 1 digit */
            -moz-appearance: textfield; 
        }

        .otp-box::-webkit-outer-spin-button,
        .otp-box::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        
        .otp-box:focus {
            outline: none;
            border-color: #3366ff;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(51, 102, 255, 0.1);
        }
        /* --- End OTP CSS --- */
        
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle-icon {
            position: absolute;
            right: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            cursor: pointer;
            font-size: 1.0625rem;
            transition: color 0.2s;
        }
        
        .password-toggle-icon:hover {
            color: #3366ff;
        }
        
        .forgot-link {
            text-align: right;
            margin-bottom: 1.25rem;
        }
        
        .forgot-link a {
            color: #3366ff;
            text-decoration: none;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        
        .forgot-link a:hover {
            text-decoration: underline;
        }
        
        /* Login Button */
        .btn-login {
            width: 100%;
            padding: 0.875rem;
            background: #3366ff;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.9375rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 1.25rem;
        }
        
        .btn-login:hover {
            background: #2952cc;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(51, 102, 255, 0.3);
        }
        
        .btn-login:disabled {
            background: #adb5bd;
            cursor: not-allowed;
            transform: none;
        }

        /* Resend Button */
        .btn-resend {
            background: none;
            border: none;
            color: #3366ff;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            padding: 0; /* Remove padding for better look */
        }

        .resend-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        /* Divider */
        .divider {
            text-align: center;
            margin: 1.25rem 0;
            color: #adb5bd;
            font-size: 0.8125rem;
            position: relative;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 42%;
            height: 1px;
            background: #dee2e6;
        }
        
        .divider::before {
            left: 0;
        }
        
        .divider::after {
            right: 0;
        }
        
        /* Social Buttons */
        .social-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        
        .btn-social {
            padding: 0.75rem;
            border-radius: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-google {
            background: #ffffff;
            color: #3c4043;
            border: 1.5px solid #dadce0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .btn-google:hover {
            background: #f8f9fa;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            border-color: #d2d3d4;
        }
        
        .btn-google svg {
            width: 16px;
            height: 16px;
        }
        
        .btn-facebook {
            background: #1877f2;
            color: #ffffff;
            border: 1.5px solid #1877f2;
            box-shadow: 0 1px 2px rgba(24, 119, 242, 0.2);
        }
        
        .btn-facebook:hover {
            background: #166fe5;
            border-color: #166fe5;
            box-shadow: 0 2px 4px rgba(24, 119, 242, 0.3);
        }
        
        .btn-facebook i {
            font-size: 1.0625rem;
        }
        
        /* Sign Up Link */
        .signup-text {
            text-align: center;
            color: #6c757d;
            font-size: 0.875rem;
        }
        
        .signup-text a {
            color: #3366ff;
            text-decoration: none;
            font-weight: 700;
        }
        
        .signup-text a:hover {
            text-decoration: underline;
        }
        
        /* Right Side - Image */
        .image-side {
            position: relative;
            width: 100%;
            height: 100vh;
            overflow: hidden;
            background: linear-gradient(135deg, #e7f3ff 0%, #f0f7ff 100%);
        }
        
        .image-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('/sweepxpress/assets/sweepxpress_logo.png'); 
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 1;
        }
        
        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                to right, 
                rgba(255, 255, 255, 0.7) 0%,
                rgba(255, 255, 255, 0.4) 15%,
                rgba(255, 255, 255, 0.1) 30%,
                rgba(255, 255, 255, 0.05) 50%,
                transparent 70%
            );
            z-index: 2;
        }
        
        .image-content {
            position: absolute;
            bottom: 3rem;
            left: 3rem;
            right: 3rem;
            z-index: 3;
        }
        
        .image-content h2 {
            font-size: 2rem;
            font-weight: 800;
            color: #212529;
            margin-bottom: 0.75rem;
            letter-spacing: -0.02em;
        }
        
        .image-content p {
            font-size: 1rem;
            font-weight: 500;
            color: #495057;
            line-height: 1.6;
            max-width: 500px;
        }
        
        .feature-pills {
            display: flex;
            gap: 0.625rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #495057;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
        }
        
        .pill i {
            font-size: 0.9375rem;
            color: #3366ff;
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .login-wrapper {
                grid-template-columns: 1fr;
            }
            
            .image-side {
                display: none;
            }
            
            .form-side {
                padding: 2rem 1.5rem;
            }
            
            .back-button {
                top: 1.5rem;
                left: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .social-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <!-- --- FORM SIDE --- -->
        <div class="form-side">
            <a href="<?= $show_otp ? '/sweepxpress/login.php?action=back_to_login' : '/sweepxpress/index.php'; ?>" class="back-button">
                <i class="bi bi-arrow-left"></i> <span><?= $show_otp ? 'Back to Login' : 'Back'; ?></span>
            </a>
            <div class="form-content">
                <h1 class="brand-logo"><span class="sweep">sweep</span><span class="x">X</span><span class="press">press</span></h1>
                <p class="brand-tagline">Your trusted cleaning supplies partner</p>
                <h2 class="welcome-title">Welcome back</h2>
                <p class="welcome-subtitle">
                    <?= $show_otp 
                        ? "Complete the verification below to continue." 
                        : "Please enter your credentials to continue"; 
                    ?>
                </p>

                <?php if ($msg || $login_error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($msg ?: $login_error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if ($show_otp): ?>
                    <form method="POST" id="otpForm">
                        <div class="input-group-custom text-center">
                            <label for="otp_code" class="input-label">OTP Code (6 Digits)</label>
                            <div class="otp-container" id="otp-container">
                                <?php for ($i=0;$i<6;$i++): ?>
                                    <input type="number" class="otp-box" data-index="<?= $i ?>" maxlength="1" inputmode="numeric" autofocus>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="otp_code" id="hidden_otp_code" required>
                        </div>
                        <button type="submit" name="verify_otp" id="verifyOtpButton" class="btn-login" disabled>Verify OTP</button>
                    </form>
                    <div class="resend-container">
                        <span>Didn't receive the code?</span>
                        <form method="POST" style="display:inline;">
                            <button type="submit" name="resend_otp" class="btn-resend">Resend Code</button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- --- LOGIN FORM --- -->
                    <form method="POST" id="loginForm">
                        <div class="input-group-custom">
                            <label for="email" class="input-label">Email Address</label>
                            <input type="email" id="email" name="email" class="input-field" placeholder="you@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="input-group-custom">
                            <label for="password" class="input-label">Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="password" name="password" class="input-field" placeholder="Enter your password" required>
                                <span class="password-toggle-icon" id="togglePassword"><i class="bi bi-eye-slash"></i></span>
                            </div>
                        </div>
                        <div class="forgot-link"><a href="/sweepxpress/forgot_password.php">Forgot Password?</a></div>
                        <button type="submit" id="loginButton" class="btn-login">Log In</button>
                    </form>
                    <div class="divider">or</div>
                    <div class="social-buttons">
                        <a href="google_login.php" class="btn-social btn-google">
                            <svg viewBox="0 0 24 24"><!-- Google SVG --></svg>
                            <span>Google</span>
                        </a>
                        <a href="facebook_login.php" class="btn-social btn-facebook">
                            <i class="bi bi-facebook"></i>
                            <span>Facebook</span>
                        </a>
                    </div>
                    <div class="signup-text">Don't have an account? <a href="/sweepxpress/register.php">Sign Up</a></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- --- IMAGE SIDE --- -->
        <div class="image-side">
            <div class="image-overlay"></div>
            <div class="image-content">
                <h2>Premium Cleaning Supplies</h2>
                <p>Quality products and exceptional service for all your cleaning needs</p>
                <div class="feature-pills">
                    <div class="pill"><i class="bi bi-shield-check"></i><span>Secure Shopping</span></div>
                    <div class="pill"><i class="bi bi-truck"></i><span>Fast Delivery</span></div>
                    <div class="pill"><i class="bi bi-star-fill"></i><span>Top Rated</span></div>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password Toggle
    const togglePassword = document.getElementById('togglePassword');
    const password = document.getElementById('password');
    if(togglePassword && password){
        togglePassword.addEventListener('click', function(){
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.querySelector('i').classList.toggle('bi-eye');
            this.querySelector('i').classList.toggle('bi-eye-slash');
        });
    }

    // OTP Input Logic
    const otpBoxes = document.querySelectorAll('.otp-box');
    const hiddenOtpCode = document.getElementById('hidden_otp_code');
    const verifyButton = document.getElementById('verifyOtpButton');

    function updateHiddenOtp() {
        let fullOtp = '';
        otpBoxes.forEach(box => { fullOtp += box.value.trim().slice(0,1); });
        hiddenOtpCode.value = fullOtp;
        verifyButton.disabled = fullOtp.length !== 6;
    }

    otpBoxes.forEach((box,index)=>{
        box.addEventListener('input',function(){
            if(this.value.length>1) this.value=this.value.slice(0,1);
            updateHiddenOtp();
            if(this.value.length===1 && index<otpBoxes.length-1) otpBoxes[index+1].focus();
        });
        box.addEventListener('keydown',function(e){
            if(e.key==='Backspace' && this.value==='' && index>0){
                otpBoxes[index-1].focus();
                otpBoxes[index-1].value='';
                updateHiddenOtp();
            }
        });
    });
    updateHiddenOtp();
});
</script>
</body>
</html>