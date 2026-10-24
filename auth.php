<?php
require_once __DIR__ . '/config.php';


// If already logged in, redirect to homepage
if (is_logged_in()) {
    header("Location: /sweepxpress/index.php");
    exit;
}

$msg = '';
$activeTab = $_GET['tab'] ?? 'login';

// Handle Register
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    // Simple validation (you can add more complex validation later)
    if ($first_name && $last_name && $contact_number && $address && filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($pass) >= 6) {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, contact_number, address, email, password_hash, role) VALUES (?,?,?,?,?,?, 'customer')");
            $stmt->execute([
                $first_name,
                $last_name,
                $contact_number,
                $address,
                $email,
                password_hash($pass, PASSWORD_DEFAULT)
            ]);
            // redirect to login tab after successful registration
            header("Location: auth.php?tab=login&registered=1");
            exit;
        } catch (Exception $e) {
            $msg = "Email already registered.";
            $activeTab = 'register';
        }
    } else {
        $msg = "Please fill out all required fields with valid information. Password must be at least 6 characters.";
        $activeTab = 'register';
    }
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u && password_verify($pass, $u['password_hash'])) {
        $_SESSION['user'] = $u;
        header("Location: /sweepxpress/index.php");
        exit;
    } else {
        $msg = "Invalid credentials.";
        $activeTab = 'login';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login / Register - SweepXpress</title>
  <link rel="stylesheet" href="assets/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-card-container">
            <div class="auth-card-left">
                <div class="auth-logo">
                    <img src="assets/SweepxPress.png" alt="Sweep X Press Logo">
                </div>
                <div class="auth-form-content">
                    <?php if ($activeTab === 'login'): ?>
                        <div class="auth-form login-form">
                            <a href="index.php" class="back-link">&larr; BACK</a>
                            <h1>LOG IN</h1>
                            <?php if (isset($_GET['registered'])): ?>
                                <p class="alert success">Account created! Please log in.</p>
                            <?php endif; ?>
                            <?php if ($msg && $activeTab === 'login'): ?>
                                <p class="alert error"><?= htmlspecialchars($msg) ?></p>
                            <?php endif; ?>
                            <form method="POST" action="auth.php">
                                <input type="hidden" name="action" value="login">
                                <label for="email">Email</label>
                                <input type="email" name="email" placeholder="Email" required>
                                <label for="password">Password</label>
                                <input type="password" name="password" placeholder="Password" required>
                                <div class="remember-me">
                                    <input type="checkbox" id="remember">
                                    <label for="remember">Remember me</label>
                                </div>
                                <button type="submit" class="btn">SIGN IN</button>
                                <div class="or-divider">or Log in with</div>
                                <a href="#" class="social-login google-btn">
                                    <img src="https://www.google.com/favicon.ico" alt="Google"> Continue with Google
                                </a>
                                <a href="#" class="social-login facebook-btn">
                                    <img src="https://www.facebook.com/favicon.ico" alt="Facebook"> Continue with Facebook
                                </a>
                            </form>
                            <div class="auth-links">
                                <a href="?tab=register">Sign up?</a>
                                <a href="#">Forgot Password?</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="auth-form register-form">
                            <a href="index.php" class="back-link">&larr; BACK</a>
                            <h1>SIGN-UP</h1>
                            <?php if ($msg && $activeTab === 'register'): ?>
                                <p class="alert error"><?= htmlspecialchars($msg) ?></p>
                            <?php endif; ?>
                            <form method="POST" action="auth.php">
                                <input type="hidden" name="action" value="register">
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="first_name">First name</label>
                                        <input type="text" name="first_name" placeholder="First name" required>
                                    </div>
                                    <div class="form-field">
                                        <label for="last_name">Last name</label>
                                        <input type="text" name="last_name" placeholder="Last name" required>
                                    </div>
                                    <div class="form-field">
                                        <label for="middle_name">Middle name (Optional)</label>
                                        <input type="text" name="middle_name" placeholder="Middle name">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-field">
                                        <label for="contact_number">Contact Number</label>
                                        <input type="tel" name="contact_number" placeholder="+96 000-000-000" required>
                                    </div>
                                    <div class="form-field">
                                        <label for="birthday">Birthday</label>
                                        <div class="birthday-inputs">
                                            <input type="text" name="bday_month" placeholder="MM" pattern="\d{2}" maxlength="2" required>
                                            <input type="text" name="bday_day" placeholder="DD" pattern="\d{2}" maxlength="2" required>
                                            <input type="text" name="bday_year" placeholder="YYYY" pattern="\d{4}" maxlength="4" required>
                                        </div>
                                    </div>
                                </div>
                                <label for="address">Address</label>
                                <input type="text" name="address" placeholder="Address" required>
                                <label for="email">Email</label>
                                <input type="email" name="email" placeholder="Email" required>
                                <label for="password">Password</label>
                                <input type="password" name="password" placeholder="Password (min 6 chars)" required>
                                <button type="submit" class="btn">SIGN-UP</button>
                            </form>
                            <div class="auth-links">
                                <a href="?tab=login">Already have an account? Log in</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="auth-card-right">
                <img src="assets/sweepxpress_logo.png" alt="Product Image">
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>