<?php
// E:\xampp\htdocs\sweepxpress\register.php

require_once __DIR__ . '/config.php';
// Tiyakin na ang file na ito ay nasa 'E:\xampp\htdocs\sweepxpress/includes/' folder
// Tandaan: Ginagamit ng register.php ang sarili nitong session logic (reg_pending)
require_once __DIR__ . '/includes/send_otp.php'; 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CONSTANT
const OTP_EXPIRY_MINUTES = 5;

// Show error message
$msg = "";
$show_otp_form = false; 
$success_message = ""; // New: Para sa confirmation messages

// --- Function to resend and update OTP in session (NEW) ---
function handleResendOtp(&$msg, &$success_message) {
    global $pdo;
    
    if (!isset($_SESSION['reg_pending'])) {
        $msg = "Session expired. Please start registration again.";
        return false;
    }
    
    $pending_data = $_SESSION['reg_pending'];

    $wait = 60 - (time() - ($pending_data['last_sent'] ?? 0));
    if ($wait > 0) {
        $msg = "Please wait {$wait} seconds before requesting a new code.";
        return false;
    }

    // Generate new OTP
    $new_otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $new_expiry_time = time() + (OTP_EXPIRY_MINUTES * 60);

    // Send new OTP email
    if (!sendOTP($pending_data['email'], $new_otp)) { 
        $msg = "Failed to send new verification email. Please check your email address and try again. (Check send_otp.php settings)";
        return false;
    } else {
        // Update session data with new OTP and expiry
        $_SESSION['reg_pending']['otp'] = $new_otp;
        $_SESSION['reg_pending']['expiry_time'] = $new_expiry_time;
        $_SESSION['reg_pending']['last_sent'] = time();
        
        $success_message = "A new 6-digit verification code has been successfully sent to **" . htmlspecialchars($pending_data['email']) . "**. The code expires in " . OTP_EXPIRY_MINUTES . " minutes.";
        return true;
    }
}


// --- Handle "Back to Registration Form" link from OTP screen ---
if (isset($_GET['action']) && $_GET['action'] === 'back_to_register') {
    // Clear pending registration session and redirect
    unset($_SESSION['reg_pending']);
    header("Location: register.php");
    exit;
}

// --- Handle RESEND OTP action (NEW) ---
if (isset($_GET['action']) && $_GET['action'] === 'resend_otp') {
    if (handleResendOtp($msg, $success_message)) {
        // Redirect to clear GET parameter and show success message
        header("Location: register.php");
        exit;
    }
    // If resend failed, execution continues to show the error message on the form.
}

// Check if we should be showing the OTP form (e.g., after successful initial submission)
if (isset($_SESSION['reg_pending'])) {
    if (time() > $_SESSION['reg_pending']['expiry_time']) {
        $msg = "Verification code expired. Please try registering again.";
        unset($_SESSION['reg_pending']); // Clear expired session data
    } else {
        $show_otp_form = true;
        
        // Only set success_message if it wasn't set by the resend action already (MODIFIED)
        if (empty($success_message)) {
            $success_message = "We sent a 6-digit verification code to **" . htmlspecialchars($_SESSION['reg_pending']['email']) . "**. The code expires in " . OTP_EXPIRY_MINUTES . " minutes.";
        }
    }
}


// If Form Submitted (Main Registration Form OR OTP Form)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // =========================================================
    // A. OTP VERIFICATION SUBMISSION
    // =========================================================
    if (isset($_POST['otp_code'])) {
        
        if (!isset($_SESSION['reg_pending'])) {
            $msg = "Session expired. Please start registration again.";
            $show_otp_form = false;
        } else {
            $otp_input = trim($_POST['otp_code']);
            $pending_data = $_SESSION['reg_pending'];
            $show_otp_form = true; // Keep showing OTP form unless successful or critical error

            // 1. Check for expired OTP
            if (time() > $pending_data['expiry_time']) {
                $msg = "Verification code expired. Please try registering again.";
                unset($_SESSION['reg_pending']);
                $show_otp_form = false; // Back to main form
            } 
            // 2. Validate OTP
            else if (strlen($otp_input) !== 6 || $otp_input !== $pending_data['otp']) {
                $msg = "Incorrect verification code or incomplete. Please try again.";
            } 
            // 3. SUCCESSFUL OTP VALIDATION - FINAL REGISTRATION
            else {
                
                // Kuhanin ang data mula sa session
                $name  = $pending_data['name'];
                $email = $pending_data['email'];
                $hash  = $pending_data['password_hash'];
                $role  = $pending_data['role'];

                try {
                    $username = generate_username($pdo, $pending_data['first_name'] ?? $name);

                    // Insert into database - ITO NA ANG FINAL STEP
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, username, email, password_hash, role, is_verified)
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$name, $username, $email, $hash, $role]);
                    $new_user_id = $pdo->lastInsertId();

                    // Clear pending session
                    unset($_SESSION['reg_pending']);

                    // AUTO LOGIN
                    $_SESSION['user'] = [
                        'id'       => $new_user_id,
                        'name'     => $name,
                        'username' => $username,
                        'email'    => $email,
                        'role'     => $role
                    ];

                    // Redirect to homepage
                    header("Location: /sweepxpress/index.php");
                    exit;

                } catch (PDOException $e) {
                    // Sa halip na i-display ang error message sa user, mas safe na mag-display lang ng generic message
                    // at i-log ang detalye ng error
                    $msg = "A database error occurred during final registration. Please try again.";
                    error_log("Registration DB Error: " . $e->getMessage()); 
                    $show_otp_form = false;
                    unset($_SESSION['reg_pending']);
                }
            }
        }
    } 
    
    // =========================================================
    // B. MAIN REGISTRATION FORM SUBMISSION
    // =========================================================
    else if (!$show_otp_form) {

        $first_name = trim($_POST['first_name'] ?? '');
        $last_name 	= trim($_POST['last_name'] ?? '');
        $email 	    = trim($_POST['email'] ?? '');
        $pass 	    = $_POST['password'] ?? '';
        $role 	    = trim($_POST['role'] ?? 'customer');
        $agreeTerms = isset($_POST['agreeTerms']);

        // Combine names
        $name = trim($first_name . " " . $last_name);

        // Validate role
        $allowed_roles = ['customer', 'business'];
        if (!in_array($role, $allowed_roles)) {
            $role = 'customer';
        }

        // Required fields and terms check
        if (!$first_name || !$last_name || !$email || !$pass) {
            $msg = "All fields are required.";
        } else if (!$agreeTerms) {
            $msg = "You must agree to the Terms & Conditions and Privacy Policy.";
        } 
        
        // ADDED PASSWORD VALIDATION LOGIC HERE
        else {
            
            // Password Policy: Minimum 10 characters, at least one letter, one number, and one special character.
            // The regex enforces:
            // (?=.*[A-Za-z]) - at least one letter (upper or lower)
            // (?=.*\d)      - at least one digit
            // (?=.*[\W_])   - at least one special character (non-word character or underscore)
            // .{10,}        - minimum 10 characters length
            $password_regex = '/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{10,}$/';

            if (!preg_match($password_regex, $pass)) {
                $msg = "Password must be at least 10 characters long and include letters, numbers, and special characters.";
            } 
            
            // Continue with existing logic if all validations pass
            else {
            
                // Check if email exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $msg = "Email already registered.";
                } else {

                    // Hash password
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    
                    // Generate OTP (6-digit, ensure it's exactly 6 digits)
                    $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                    $expiry_time = time() + (OTP_EXPIRY_MINUTES * 60);

                    // Send OTP email
                    if (!sendOTP($email, $otp)) { 
                        $msg = "Failed to send verification email. Please check your email address and try again. (Check send_otp.php settings)";
                    } else {
                        
                        // Store all necessary registration data in session
                        $_SESSION['reg_pending'] = [
                            'name'          => $name,
                            'email'         => $email,
                            'password_hash' => $hash,
                            'role'          => $role,
                            'otp'           => $otp,
                            'expiry_time'   => $expiry_time,
                            'last_sent'     => time(),
                            // Store other form values to repopulate if the user goes back
                            'first_name'    => $first_name,
                            'last_name'     => $last_name,
                        ];
                        
                        // Set flag to show OTP form
                        $show_otp_form = true;
                        
                        // Redirect to self to clear POST data and show the OTP form
                        header("Location: register.php");
                        exit;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - SweepXpress</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        /* ... (Your existing CSS styles) ... */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .signup-box {
            background: #ffffff;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            padding: 3rem 2.5rem;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        /* Back Button */
        .back-button {
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
            margin-bottom: 1.5rem;
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

        /* Brand Logo */
        .brand-logo {
            font-size: 1.875rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
            text-align: center;
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

        h2 {
            text-align: center;
            font-weight: 800;
            color: #212529;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        .text-center.text-muted {
            text-align: center;
            color: #6c757d;
            margin-bottom: 2rem;
            font-size: 1rem;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .alert-success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .form-control {
            padding: 0.875rem 1rem;
            border: 1.5px solid #dee2e6;
            border-radius: 10px;
            font-size: 0.9375rem;
            transition: all 0.2s;
            background: #f8f9fa;
        }

        .form-control:focus {
            outline: none;
            border-color: #3366ff;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(51, 102, 255, 0.1);
        }

        /* New: Password Input Group Styling (NEW) */
        .password-input-group {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            font-size: 1.1rem;
            transition: color 0.2s;
            z-index: 10;
        }
        
        .password-toggle:hover {
            color: #3366ff;
        }


        .role-section-title {
            font-size: 0.875rem;
            font-weight: 700;
            color: #495057;
            margin-top: 1.5rem;
            margin-bottom: 1rem;
        }

        /* Role Cards - Fixed Layout */
        .role-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .role-box {
            border: 2px solid #dee2e6;
            padding: 1.5rem 1rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            background: #ffffff;
            text-align: center;
        }

        .role-box:hover {
            background: #f8f9ff;
            border-color: #3366ff;
        }

        .role-box.active {
            border-color: #3366ff;
            background: #eff3ff;
            box-shadow: 0 0 0 3px rgba(51, 102, 255, 0.1);
        }

        /* Simple CSS Icons */
        .role-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 0.75rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.2s;
        }

        .role-box:hover .role-icon {
            transform: scale(1.1);
        }

        .icon-customer {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1976d2;
        }

        .icon-business {
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
            color: #7b1fa2;
        }

        .role-box strong {
            font-size: 1rem;
            font-weight: 700;
            color: #212529;
            display: block;
            margin-bottom: 0.25rem;
        }

        .role-box p {
            font-size: 0.875rem;
            color: #6c757d;
            margin: 0;
        }

        .form-check {
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 1.5px solid #dee2e6;
            cursor: pointer;
            margin-top: 0.125rem;
        }

        .form-check-input:checked {
            background-color: #3366ff;
            border-color: #3366ff;
        }

        .form-check-label {
            font-size: 0.875rem;
            color: #6c757d;
            line-height: 1.5;
        }

        .form-check-label a {
            color: #3366ff;
            text-decoration: none;
            font-weight: 600;
        }

        .form-check-label a:hover {
            text-decoration: underline;
        }

        /* Create Account Button */
        .btn-create {
            width: 100%;
            padding: 1rem;
            background: #3366ff;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 1.5rem;
        }

        .btn-create:hover {
            background: #2952cc;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(51, 102, 255, 0.3);
        }

        /* Divider */
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            color: #adb5bd;
            font-size: 0.875rem;
            position: relative;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: #dee2e6;
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        /* Social Buttons - Fixed Spacing & Size */
        .social-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .btn-social {
            padding: 0.875rem 1rem;
            border-radius: 10px;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            font-weight: 600;
            font-size: 0.9375rem;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
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
        }

        .btn-facebook {
            background: #1877f2;
            color: #ffffff;
            border: 1.5px solid #1877f2;
        }

        .btn-facebook:hover {
            background: #166fe5;
        }

        .btn-social i {
            font-size: 1.125rem;
        }

        /* Login Link */
        .login-text {
            text-align: center;
            color: #6c757d;
            font-size: 0.9375rem;
        }

        .login-text a {
            color: #3366ff;
            text-decoration: none;
            font-weight: 700;
        }

        .login-text a:hover {
            text-decoration: underline;
        }

        /* OTP Specific Styling */
        .otp-input-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 2rem;
        }
        .otp-input-group input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            border-radius: 8px;
            border: 2px solid #dee2e6;
            transition: border-color 0.2s;
        }
        .otp-input-group input:focus {
            border-color: #3366ff;
            box-shadow: 0 0 0 3px rgba(51, 102, 255, 0.1);
        }


        @media (max-width: 576px) {
            .signup-box {
                padding: 2rem 1.5rem;
            }

            .role-options {
                grid-template-columns: 1fr;
            }

            .social-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        function selectRole(role) {
            document.getElementById("customerBox").classList.remove("active");
            document.getElementById("businessBox").classList.remove("active");

            document.getElementById(role + "Box").classList.add("active");
            document.getElementById(role).checked = true;
        }

        window.onload = () => {
            // Only select role if the main form is visible
            if (document.getElementById('mainRegistrationForm')) {
                // Gamitin ang PHP echo para makuha ang role
                selectRole('<?php echo $_SESSION['reg_pending']['role'] ?? 'customer'; ?>');
            }
        }
        
        // New: Password Toggle Function (NEW)
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password_field');
            const toggleIcon = document.getElementById('password_toggle_icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('bi-eye-slash-fill');
                toggleIcon.classList.add('bi-eye-fill');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('bi-eye-fill');
                toggleIcon.classList.add('bi-eye-slash-fill');
            }
        }

    </script>
    
    <?php if ($show_otp_form): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const otpInputs = document.querySelectorAll('.otp-input');
            const hiddenOtpCode = document.getElementById('hidden_otp_code');
            const verifyButton = document.querySelector('.btn-create');

            // Function to update the hidden field and button state
            function updateHiddenField() {
                let otpCode = '';
                otpInputs.forEach(input => {
                    // Filter non-numeric input and limit to 1 character
                    const value = input.value.replace(/[^0-9]/g, '').slice(0, 1);
                    input.value = value; // Update the box itself
                    otpCode += value;
                });
                
                hiddenOtpCode.value = otpCode;
                verifyButton.disabled = otpCode.length !== 6;
            }
            
            // Focus sa unang box sa simula
            otpInputs[0].focus();


            otpInputs.forEach((input, index) => {
                // Event listener para sa pag-input (typing)
                input.addEventListener('input', function() {
                    updateHiddenField();
                    // Auto-focus sa susunod na box
                    if (this.value.length === 1 && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }
                });

                // Event listener para sa Backspace
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace') {
                        // Kapag Backspace at walang laman ang box, pumunta sa nakaraang box
                        if (this.value === '' && index > 0) {
                            e.preventDefault(); // Iwasan ang default backspace action
                            otpInputs[index - 1].focus();
                            otpInputs[index - 1].value = ''; // I-clear ang laman ng nakaraang box
                            updateHiddenField(); // I-update agad ang hidden field at button state
                        }
                    }
                    
                    // I-block ang arrow up at arrow down keys (Para hindi gumana ang spinner)
                    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
                        e.preventDefault();
                    }
                });
            });

            // Prevent form submission if OTP is incomplete (although button is disabled)
            document.getElementById('otpForm').addEventListener('submit', function(e) {
                updateHiddenField();
                if (hiddenOtpCode.value.length !== 6) {
                    e.preventDefault();
                    alert('Please enter the full 6-digit verification code.');
                    otpInputs[0].focus();
                }
                
                // Set loading state on successful submission
                if (!verifyButton.disabled) {
                    verifyButton.disabled = true;
                    verifyButton.innerHTML = `
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        Verifying...
                    `;
                }
            });
            
            // Initial check on load
            updateHiddenField();
        });
    </script>
    <?php endif; ?>

</head>
<body>

<div class="signup-box">
    <?php if ($show_otp_form): ?>
        <a href="register.php?action=back_to_register" class="back-button">
            <i class="bi bi-arrow-left"></i>
            <span>Change Details</span>
        </a>
    <?php else: ?>
        <a href="/sweepxpress/login.php" class="back-button">
            <i class="bi bi-arrow-left"></i>
            <span>Back to Login</span>
        </a>
    <?php endif; ?>

    <h1 class="brand-logo">
        <span class="sweep">sweep</span><span class="x">X</span><span class="press">press</span>
    </h1>

    <?php if ($msg): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    
    <?php if ($show_otp_form): ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= $success_message ?></div>
        <?php endif; ?>
        
        <h2>Verify Your Email</h2>
        <p class="text-center text-muted">
            We sent a 6-digit verification code to **<?= htmlspecialchars($_SESSION['reg_pending']['email'] ?? 'your email') ?>**.
            The code expires in <?= OTP_EXPIRY_MINUTES ?> minutes.
        </p>

        <form action="" method="POST" id="otpForm">
            <div class="otp-input-group">
                <?php for ($i=0; $i<6; $i++): ?>
                <input type="text" class="otp-input form-control" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                <?php endfor; ?>
                <input type="hidden" name="otp_code" id="hidden_otp_code" required>
            </div>
            
            <button type="submit" class="btn-create" disabled>
                Verify and Create Account
            </button>
            
            <p class="login-text">
                Didn't receive the code?
                <a href="register.php?action=resend_otp" onclick="this.innerHTML='Sending...'">Resend Code</a> or 
                <a href="register.php?action=back_to_register">Go back to registration</a>
                </p>
        </form>

    <?php else: ?>
        <h2>Create Account</h2>
        <p class="text-center text-muted">Join us and start shopping today</p>

        <form action="" method="POST" id="mainRegistrationForm">
            <?php $prev_data = $_SESSION['reg_pending'] ?? []; // For repopulating data if they go back ?>
            <div class="row">
                <div class="col-6 mb-3">
                    <input type="text" name="first_name" class="form-control" placeholder="First Name" value="<?= htmlspecialchars($prev_data['first_name'] ?? '') ?>" required>
                </div>
                <div class="col-6 mb-3">
                    <input type="text" name="last_name" class="form-control" placeholder="Last Name" value="<?= htmlspecialchars($prev_data['last_name'] ?? '') ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <input type="email" name="email" class="form-control" placeholder="Email" value="<?= htmlspecialchars($prev_data['email'] ?? '') ?>" required>
            </div>

            <div class="mb-3 password-input-group">
                <input 
                    type="password" 
                    name="password" 
                    id="password_field" 
                    class="form-control" 
                    placeholder="Password (Min 10 chars, incl. letter, number, special char)" 
                    title="Password must be at least 10 characters long and include letters, numbers, and special characters." 
                    required
                >
                <i class="bi bi-eye-slash-fill password-toggle" id="password_toggle_icon" onclick="togglePasswordVisibility()"></i>
            </div>

            <p class="role-section-title">I am signing up as...</p>

            <div class="role-options">
                <?php $current_role = $prev_data['role'] ?? 'customer'; ?>
                <label onclick="selectRole('customer')" id="customerBox" class="role-box <?= $current_role === 'customer' ? 'active' : ''; ?>">
                    <input type="radio" id="customer" name="role" value="customer" class="d-none" <?= $current_role === 'customer' ? 'checked' : ''; ?>>
                    <div class="role-icon icon-customer">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <strong>Customer</strong>
                    <p>Shop for products</p>
                </label>

                <label onclick="selectRole('business')" id="businessBox" class="role-box <?= $current_role === 'business' ? 'active' : ''; ?>">
                    <input type="radio" id="business" name="role" value="business" class="d-none" <?= $current_role === 'business' ? 'checked' : ''; ?>>
                    <div class="role-icon icon-business">
                        <i class="bi bi-briefcase-fill"></i>
                    </div>
                    <strong>Business</strong>
                    <p>Bulk orders</p>
                </label>
            </div>

            <div class="form-check d-flex align-items-start mt-3">
                <input class="form-check-input mt-1" type="checkbox" name="agreeTerms" required id="agreeTerms">

                <label class="form-check-label ms-2" for="agreeTerms">
                    By creating an account, you agree to our
                    <a href="/sweepxpress/terms.php" target="_blank">Terms & Conditions</a>
                    and
                    <a href="/sweepxpress/privacy.php" target="_blank">Privacy Policy</a>.
                </label>
            </div>

            <button type="submit" class="btn-create">
                Create Account
            </button>

            <div class="divider">or sign up with</div>

            <div class="social-buttons">
                <button type="button" class="btn-social btn-google">
                    <i class="bi bi-google"></i>
                    <span>Google</span>
                </button>
                <button type="button" class="btn-social btn-facebook">
                    <i class="bi bi-facebook"></i>
                    <span>Facebook</span>
                </button>
            </div>

            <p class="login-text">
                Already have an account?
                <a href="/sweepxpress/login.php">Login</a>
            </p>

        </form>
    <?php endif; ?>
</div>

</body>
</html>