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
$min_length = 8; // Inirerekomenda para sa validation

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
    } elseif (strlen($new_password) < $min_length) {
        $error = "Password must be at least {$min_length} characters long.";
    } else {
        try {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("
                UPDATE users 
                SET password_hash = :password, password_reset_token = NULL, reset_token_expiry = NULL 
                WHERE id = :id
            ");
            $update->execute([':password' => $hashed_password, ':id' => $user['id']]);

            $success = "Password reset successful. You can now <a href='login.php' class='alert-link fw-bold'>login</a>.";
            $user = null; // Prevent form from showing
        } catch (PDOException $e) {
            error_log("Password Update Error: " . $e->getMessage());
            $error = "Database update error. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password | SweepXpress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
        }
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .btn-primary {
            background: linear-gradient(90deg, #007bff, #6610f2);
            border: none;
            border-radius: 10px;
            transition: 0.3s;
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }
        .form-control {
            border-radius: 10px;
            padding: 10px 12px;
        }
        /* Style for the icon button inside the input group */
        .input-group-text {
            cursor: pointer;
            background: #fff;
            border-left: none;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card p-4 p-md-5 bg-white">
                <div class="text-center mb-4">
                    <img src="https://cdn-icons-png.flaticon.com/512/3064/3064197.png" width="70" alt="Lock Icon">
                    <h2 class="mt-3">Reset Password</h2>
                    <p class="text-muted">Enter your new password below (min. <?= $min_length ?> characters).</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success text-center"><?= $success ?></div>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-outline-secondary">Go to Login Page</a>
                    </div>
                <?php elseif (empty($error) && $user): ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" minlength="<?= $min_length ?>" required>
                                <span class="input-group-text" id="togglePassword1">
                                    <i class="fa fa-eye" aria-hidden="true"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label fw-semibold">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="<?= $min_length ?>" required>
                                <span class="input-group-text" id="togglePassword2">
                                    <i class="fa fa-eye" aria-hidden="true"></i>
                                </span>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2">Reset Password</button>
                    </form>
                <?php else: ?>
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn btn-outline-secondary">Go to Login Page</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Function to handle toggling for a specific field
        function setupPasswordToggle(toggleId, inputId) {
            const toggle = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            
            toggle.addEventListener('click', function() {
                // Toggle the type attribute
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                
                // Toggle the eye icon (fa-eye <-> fa-eye-slash)
                this.querySelector('i').classList.toggle('fa-eye-slash');
                this.querySelector('i').classList.toggle('fa-eye');
            });
        }

        // Setup for New Password field
        setupPasswordToggle('togglePassword1', 'new_password');

        // Setup for Confirm Password field
        setupPasswordToggle('togglePassword2', 'confirm_password');
    });
</script>

</body>
</html>