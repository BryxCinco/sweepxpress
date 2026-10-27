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

    if (rate_limited($pdo, 'register_otp', 5, 3600)) {
        $msg = "Too many verification requests. Please try again later.";
        return false;
    }
    rate_limit_record($pdo, 'register_otp');

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
        
        $success_message = "A new 6-digit verification code has been sent to <strong>" . htmlspecialchars($pending_data['email']) . "</strong>. The code expires in " . OTP_EXPIRY_MINUTES . " minutes.";
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
            $success_message = "We sent a 6-digit verification code to <strong>" . htmlspecialchars($_SESSION['reg_pending']['email']) . "</strong>. The code expires in " . OTP_EXPIRY_MINUTES . " minutes.";
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
        // Spam guard muna: honeypot / walang record / masyadong mabilis
        if (spam_guard_failed('register')) {
            $msg = "Something went wrong. Please try again.";
        } else if (!$first_name || !$last_name || !$email || !$pass) {
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
                } elseif (rate_limited($pdo, 'register_otp', 5, 3600)) {
                    // Max 5 OTP email kada IP kada oras (unang send + resend)
                    $msg = "Too many verification requests. Please try again later.";
                } else {
                    rate_limit_record($pdo, 'register_otp');

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
<?php
// Pang-repopulate ng form: mula sa session (kapag bumalik mula sa OTP) o sa POST (kapag may error, hal. mahinang password)
$prev_data = $_SESSION['reg_pending'] ?? [
    'first_name' => $_POST['first_name'] ?? '',
    'last_name'  => $_POST['last_name'] ?? '',
    'email'      => $_POST['email'] ?? '',
    'role'       => $_POST['role'] ?? 'customer',
];
$current_role = in_array($prev_data['role'] ?? '', ['customer', 'business'], true) ? $prev_data['role'] : 'customer';

require_once __DIR__ . '/includes/auth_layout.php';
sx_auth_start(
    $show_otp_form ? 'Verify your email' : 'Create account',
    $show_otp_form ? 'register.php?action=back_to_register' : '/sweepxpress/login.php',
    $show_otp_form ? 'Change details' : 'Back to login'
);
?>
            <?php if ($show_otp_form): ?>
                <h1>Verify your email</h1>
                <p class="sx-auth-sub">One last step — enter the code to create your account.</p>
            <?php else: ?>
                <h1>Create your account</h1>
                <p class="sx-auth-sub">Shop for yourself, or order in bulk for your business.</p>
            <?php endif; ?>

            <?php if ($msg): ?>
                <div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= htmlspecialchars($msg) ?></div></div>
            <?php endif; ?>

            <?php if ($show_otp_form): ?>
                <?php if ($success_message): ?>
                    <div class="sx-alert sx-alert-success" role="status"><i class="fa-solid fa-envelope-circle-check"></i><div><?= $success_message ?></div></div>
                <?php endif; ?>

                <form action="" method="POST" id="otpForm">
                    <label class="sx-label" for="otp-0" style="display:block; margin-bottom: var(--sx-space-2)">Verification code</label>
                    <div class="sx-otp" id="otpBoxes">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" id="otp-<?= $i ?>" inputmode="numeric" autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>" maxlength="6" aria-label="Digit <?= $i + 1 ?>">
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="otp_code" id="hidden_otp_code" required>
                    <button type="submit" id="verifyOtpButton" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block" disabled>
                        <i class="fa-solid fa-user-check"></i> Verify &amp; create account
                    </button>
                </form>
                <div class="sx-otp-resend">
                    <span>Didn't get the code?</span>
                    <a href="register.php?action=resend_otp" onclick="this.textContent='Sending...'">Resend code</a>
                    <span>·</span>
                    <a href="register.php?action=back_to_register">Change details</a>
                </div>

            <?php else: ?>
                <form action="" method="POST" id="mainRegistrationForm">
                    <?= spam_guard_field('register') ?>
                    <div class="sx-choices" role="radiogroup" aria-label="Account type" style="margin-bottom: var(--sx-space-4)">
                        <label class="sx-choice">
                            <input type="radio" name="role" value="customer" <?= $current_role === 'customer' ? 'checked' : '' ?>>
                            <i class="fa-solid fa-user"></i>
                            <strong>Customer</strong>
                            <span>Shop for products</span>
                        </label>
                        <label class="sx-choice">
                            <input type="radio" name="role" value="business" <?= $current_role === 'business' ? 'checked' : '' ?>>
                            <i class="fa-solid fa-building"></i>
                            <strong>Business</strong>
                            <span>Bulk orders &amp; POs</span>
                        </label>
                    </div>

                    <div class="co-row">
                        <div class="sx-field">
                            <label class="sx-label" for="first_name">First name</label>
                            <input type="text" id="first_name" name="first_name" class="sx-input" autocomplete="given-name" required
                                   value="<?= htmlspecialchars($prev_data['first_name'] ?? '') ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="last_name">Last name</label>
                            <input type="text" id="last_name" name="last_name" class="sx-input" autocomplete="family-name" required
                                   value="<?= htmlspecialchars($prev_data['last_name'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="sx-field">
                        <label class="sx-label" for="email">Email address</label>
                        <input type="email" id="email" name="email" class="sx-input" placeholder="you@example.com" autocomplete="email" required
                               value="<?= htmlspecialchars($prev_data['email'] ?? '') ?>">
                    </div>

                    <div class="sx-field">
                        <label class="sx-label" for="password_field">Password</label>
                        <div class="sx-pass">
                            <input type="password" id="password_field" name="password" class="sx-input" autocomplete="new-password" required
                                   minlength="10" aria-describedby="passRules">
                            <button type="button" data-sx-toggle="password_field" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <ul class="sx-rules" id="passRules">
                            <li data-rule="length"><i class="fa-regular fa-circle"></i> 10+ characters</li>
                            <li data-rule="letter"><i class="fa-regular fa-circle"></i> A letter</li>
                            <li data-rule="number"><i class="fa-regular fa-circle"></i> A number</li>
                            <li data-rule="special"><i class="fa-regular fa-circle"></i> A special character</li>
                        </ul>
                    </div>

                    <label class="sx-check" style="align-items: flex-start; margin-bottom: var(--sx-space-5)">
                        <input type="checkbox" name="agreeTerms" id="agreeTerms" required style="margin-top: 3px">
                        <span>I agree to the <a href="/sweepxpress/terms.php" target="_blank">Terms &amp; Conditions</a> and <a href="/sweepxpress/privacy.php" target="_blank">Privacy Policy</a>.</span>
                    </label>

                    <button type="submit" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block sx-btn-arrow">
                        Create account <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                    </button>
                </form>

                <div class="sx-divider">or</div>
                <a href="/sweepxpress/google_login.php" class="sx-btn sx-btn-outline sx-btn-lg sx-btn-block"><?= sx_google_icon() ?> Sign up with Google</a>

                <p class="sx-auth-foot">Already have an account? <a href="/sweepxpress/login.php">Log in</a></p>
            <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    sxPasswordToggles();
    sxPasswordRules(document.getElementById('password_field'), document.getElementById('passRules'));
    sxOtpBoxes(document.getElementById('otpBoxes'), document.getElementById('hidden_otp_code'), document.getElementById('verifyOtpButton'));

    ['mainRegistrationForm', 'otpForm'].forEach(id => {
        const form = document.getElementById(id);
        if (!form) return;
        form.addEventListener('submit', () => {
            const btn = form.querySelector('button[type="submit"]');
            setTimeout(() => { btn.disabled = true; }, 0);
        });
    });
});
</script>
<?php sx_auth_end(); ?>
