<?php
session_start();
// IMPORTANT: Siguraduhin na ang path ay tama
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_utility.php'; 
require_once __DIR__ . '/includes/log_helper.php';

$login_error = '';
$success = 'OTP sent to your email. It will expire in 5 minutes.'; // Default message pagkapunta rito
$uid = $_SESSION['pending_user_id'] ?? null;
$show_otp = ($uid !== null); // Dapat laging true kung valid ang session

// Kung walang pending ID, ibalik sa login
if (!$show_otp) {
    // Para sa mas malinis na URL, hindi dapat mag-expire ang session dito
    unset($_SESSION['otp_last_sent']); 
    header("Location: /sweepxpress/login.php");
    exit;
}

// --- Handle "Back to Login" ---
if (isset($_GET['action']) && $_GET['action'] === 'back_to_login') {
    // Clear OTP in DB
    $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL WHERE id=?")->execute([$uid]);
    unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent']);
    header("Location: /sweepxpress/login.php");
    exit;
}

// ----------------------------------------------------
// 1. Handle OTP Verification (Ang hiningi ninyong logic)
// ----------------------------------------------------
if (isset($_POST['verify_otp'])) {
    $otp_input = trim($_POST['otp_code'] ?? '');

    if (empty($otp_input) || strlen($otp_input) !== 6) {
        $login_error = "Please enter the full 6-digit OTP code.";
    } else {
        $stmt = $pdo->prepare("SELECT id, role, email, otp_code, otp_expires FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$uid]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($u && (string)$otp_input === (string)$u['otp_code']) {
            // Check Expiration
            if (strtotime($u['otp_expires']) > time()) {
                // --- SUCCESSFUL LOGIN ---
                
                log_user_action($pdo, $uid, 'LOGIN_SUCCESS', 'User logged in successfully after 2FA.', null, $_SERVER['REMOTE_ADDR'] ?? null);
                
                // Clear OTP in DB
                $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL, otp_last_sent=NULL WHERE id=?")->execute([$uid]);

                // Setup Session and Redirect
                unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent']);
                $_SESSION['user'] = [
                    'id' => $u['id'],
                    'role' => $u['role'],
                    'email' => $u['email']
                ];

                header("Location: /sweepxpress/index.php"); // >>> SUCCESSFUL REDIRECTION
                exit; 
            } else {
                // OTP expired
                $login_error = "OTP expired. Please go back to Login screen.";
                log_user_action($pdo, $uid, 'OTP_EXPIRED', 'User failed to verify: OTP expired.', null, $_SERVER['REMOTE_ADDR'] ?? null);
                
                $pdo->prepare("UPDATE users SET otp_code=NULL, otp_expires=NULL WHERE id=?")->execute([$uid]);
                unset($_SESSION['pending_user_id'], $_SESSION['otp_last_sent']);
                $show_otp = false;
            }
        } else {
            // MALI ANG OTP - MAGPAPAKITA NG ERROR AT MANANATILI DITO
            $login_error = "Incorrect OTP. Try again or resend."; 
            log_user_action($pdo, $uid, 'OTP_FAILURE', 'User failed to verify: Incorrect OTP.', null, $_SERVER['REMOTE_ADDR'] ?? null);
        }
    }
    if ($login_error) $success = '';
}

// ----------------------------------------------------
// 2. Handle OTP Resend
// ----------------------------------------------------
if (isset($_POST['resend_otp'])) {
    $login_error = '';
    
    // 60-second cooldown check (Retention)
    $cooldown_passed = (time() - ($_SESSION['otp_last_sent'] ?? 0) >= 60);

    if (!$cooldown_passed) {
        $remain = 60 - (time() - ($_SESSION['otp_last_sent'] ?? 0));
        $login_error = "Wait $remain seconds before resending OTP.";
    } else {
        $e_data = $pdo->query("SELECT email FROM users WHERE id=$uid")->fetch(PDO::FETCH_ASSOC);
        $email_to_send = $e_data['email'] ?? null;
        $otp = rand(100000, 999999);

        $pdo->prepare("UPDATE users SET otp_code=?, otp_expires=DATE_ADD(NOW(), INTERVAL 5 MINUTE), otp_last_sent=NOW() WHERE id=?")
            ->execute([$otp, $uid]);

        if ($email_to_send && send_otp_email($email_to_send, $otp) === true) {
            $_SESSION['otp_last_sent'] = time();
            $success = "A new OTP has been sent. Check inbox/spam. It will expire in 5 minutes.";
            log_user_action($pdo, $uid, 'OTP_RESENT', 'Successfully resent OTP for login.', null, $_SERVER['REMOTE_ADDR'] ?? null);
        } else {
            $login_error = "Failed to resend OTP. Contact support.";
            log_user_action($pdo, $uid, 'OTP_RESEND_FAILED', 'Failed to resend OTP email.', null, $_SERVER['REMOTE_ADDR'] ?? null);
        }
    }
    if ($login_error) $success = '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - SweepXpress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style> /* ... (Ang kumpletong CSS mo dito) ... */ </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="form-side">
            <a href="/sweepxpress/otp_verification.php?action=back_to_login" class="back-button">
                <i class="bi bi-arrow-left"></i>
                <span>Back to Login</span>
            </a>
            
            <div class="form-content">
                <h2 class="welcome-title">Verify Your Login</h2>
                <p class="welcome-subtitle">
                    Complete the verification below to continue.
                </p>
                
                <?php if ($login_error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= htmlspecialchars($login_error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                
                <form action="" method="POST" id="otpForm">
                    <div class="input-group-custom text-center">
                        <label class="input-label" for="otp_code">OTP Code (6 Digits)</label>
                        
                        <div class="otp-container" id="otp-container">
                            <input type="number" class="otp-box" data-index="0" maxlength="1" inputmode="numeric" autofocus>
                            <input type="number" class="otp-box" data-index="1" maxlength="1" inputmode="numeric">
                            <input type="number" class="otp-box" data-index="2" maxlength="1" inputmode="numeric">
                            <input type="number" class="otp-box" data-index="3" maxlength="1" inputmode="numeric">
                            <input type="number" class="otp-box" data-index="4" maxlength="1" inputmode="numeric">
                            <input type="number" class="otp-box" data-index="5" maxlength="1" inputmode="numeric">
                        </div>
                        
                        <input type="hidden" name="otp_code" id="hidden_otp_code" required>
                    </div>

                    <button type="submit" name="verify_otp" id="verifyOtpButton" class="btn-login">
                        Verify OTP
                    </button>
                </form>

                <div class="resend-container">
                    <span>Didn't receive the code?</span>
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="resend_otp" class="btn-resend">
                            Resend Code
                        </button>
                    </form>
                </div>

            </div>
        </div>
        
        </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to handle loading state on form submission (Retention)
            function setButtonLoading(formId, buttonId, loadingText) {
                const form = document.getElementById(formId);
                const button = document.getElementById(buttonId);
                
                if (form && button) {
                    form.addEventListener('submit', function(event) {
                        if (formId === 'otpForm') {
                            const hiddenOtpCode = document.getElementById('hidden_otp_code');
                            if (!hiddenOtpCode || hiddenOtpCode.value.length !== 6) {
                                return; 
                            }
                        }
                        
                        button.disabled = true;
                        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> ${loadingText}`;
                    });
                }
            }
            setButtonLoading('otpForm', 'verifyOtpButton', 'Verifying...');

            // --- OTP Multi-Box Handler (CRITICAL for user experience) ---
            const otpContainer = document.getElementById('otp-container');
            const hiddenOtpCode = document.getElementById('hidden_otp_code');
            const otpBoxes = document.querySelectorAll('.otp-box');
            const otpForm = document.getElementById('otpForm');

            if (otpContainer && hiddenOtpCode) {
                function updateAndSubmit() {
                    let fullOtp = '';
                    otpBoxes.forEach(box => {
                        let cleanedValue = box.value.replace(/\D/g, '').substring(0, 1);
                        box.value = cleanedValue;
                        fullOtp += cleanedValue;
                    });
                    hiddenOtpCode.value = fullOtp;
                    if (fullOtp.length === 6) {
                        document.getElementById('verifyOtpButton').focus();
                    }
                }
                
                otpContainer.addEventListener('input', (e) => {
                    const target = e.target;
                    const index = parseInt(target.dataset.index);
                    if (target.value.length === 1 && index < otpBoxes.length - 1) {
                        otpBoxes[index + 1].focus();
                    }
                    updateAndSubmit();
                });

                otpContainer.addEventListener('keydown', (e) => {
                    const target = e.target;
                    const index = parseInt(target.dataset.index);
                    if (e.key === 'Backspace' && target.value === '' && index > 0) {
                        otpBoxes[index - 1].focus();
                    }
                });

                otpContainer.addEventListener('paste', (e) => {
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                    const cleanData = pastedData.replace(/\D/g, '').substring(0, 6);
                    
                    cleanData.split('').forEach((digit, i) => {
                        if (otpBoxes[i]) {
                            otpBoxes[i].value = digit;
                        }
                    });

                    const focusIndex = Math.min(cleanData.length, 5);
                    otpBoxes[focusIndex].focus();
                    
                    e.preventDefault();
                    updateAndSubmit();
                });
                
                otpForm.addEventListener('submit', function(e) {
                    updateAndSubmit(); 
                    if (hiddenOtpCode.value.length !== 6) {
                        e.preventDefault();
                        const startFocus = hiddenOtpCode.value.length;
                        otpBoxes[Math.min(startFocus, 5)].focus(); 
                        alert('Please enter the full 6-digit OTP.');
                    }
                });
            }
        });
    </script>
</body>
</html>