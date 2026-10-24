<?php
// forgot_password.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email_config.php';

$message = '';
$form_was_submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_was_submitted = true;
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert alert-danger text-center">Please enter a valid email address.</div>';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
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
        $message = '<div class="alert alert-success text-center">
            If your email is registered, a password reset link has been sent. Please check your inbox or spam folder.
        </div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | SweepXpress</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
</style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card p-4 p-md-5 bg-white">
                <div class="text-center mb-4">
                    <img src="https://cdn-icons-png.flaticon.com/512/3064/3064197.png" width="70" alt="Lock Icon">
                    <h3 class="mt-3">Forgot Password?</h3>
                    <p class="text-muted mb-3">Enter your email to receive a reset link.</p>
                </div>

                <?= $message; ?>

                <?php if (!$form_was_submitted || strpos($message, 'danger') !== false): ?>
                <form method="post">
                    <div class="mb-3">
                        <label for="email_input" class="form-label fw-semibold">Email Address</label>
                        <input 
                            type="email" 
                            class="form-control" 
                            id="email_input" 
                            name="email" 
                            placeholder="your-email@example.com" 
                            required
                        >
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary w-100 py-2">Send Reset Link</button>
                        <a href="login.php" class="btn btn-link text-decoration-none">Back to Login</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>

