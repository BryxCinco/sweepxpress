<?php
// Start the session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = $user;
            header("Location: /sweepxpress/admin/dashboard.php");
            exit;
        } else {
            $msg = "Invalid admin credentials.";
        }
    } else {
        $msg = "Please fill in all fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | SweepXpress</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Roboto',sans-serif; }

        .admin-login-wrapper {
            display:grid;
            grid-template-columns:45% 55%;
            height:100vh;
            width:100vw;
        }

        /* LEFT SIDE – DARK MINIMAL HERO */
        .admin-brand-side {
            background:#050816;
            position:relative;
            padding:3.5rem;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            color:#e5e7eb;
        }

        .grid-pattern {
            position:absolute;
            inset:0;
            background-image:
                linear-gradient(rgba(148,163,184,0.06) 1px,transparent 1px),
                linear-gradient(90deg,rgba(148,163,184,0.06) 1px,transparent 1px);
            background-size:46px 46px;
            opacity:.5;
            animation:gridMove 28s linear infinite;
            pointer-events:none;
        }
        @keyframes gridMove {
            0%{transform:translate(0,0);}
            100%{transform:translate(46px,46px);}
        }

        .gradient-orb {
            position:absolute;
            width:460px;
            height:460px;
            border-radius:50%;
            background:radial-gradient(circle,rgba(56,189,248,0.25)0%,transparent70%);
            right:-160px;
            top:-160px;
            filter:blur(40px);
            opacity:.8;
            pointer-events:none;
        }

        .brand-header {
            position:relative;
            z-index:1;
        }
        .brand-logo-mark {
            width:60px;height:60px;
            border-radius:16px;
            background:linear-gradient(135deg,#0ea5e9 0%,#6366f1 100%);
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 18px 45px rgba(59,130,246,.45);
            margin-bottom:2.5rem;
        }
        .brand-logo-mark i { color:#fff;font-size:1.7rem; }

        .brand-heading {
            font-size:3.2rem;
            font-weight:900;
            line-height:1.08;
            letter-spacing:-.03em;
            color:#f9fafb;
            margin-bottom:1.2rem;
        }
        .brand-heading span {
            background:linear-gradient(135deg,#0ea5e9 0%,#22c55e 50%,#6366f1 100%);
            -webkit-background-clip:text;
            background-clip:text;
            -webkit-text-fill-color:transparent;
        }
        .brand-description {
            max-width:360px;
            font-size:.97rem;
            color:#94a3b8;
        }

        /* SINGLE METRIC CARD INSTEAD OF 3 ROWS */
        .brand-metric-card {
            position:relative;
            z-index:1;
            margin-top:2.8rem;
            max-width:420px;
            background:radial-gradient(circle at 0 0,rgba(56,189,248,.2),transparent 55%),
                       radial-gradient(circle at 100% 100%,rgba(139,92,246,.2),transparent 55%),
                       #020617;
            border-radius:20px;
            padding:1.6rem 1.8rem;
            border:1px solid rgba(148,163,184,.25);
            box-shadow:0 16px 45px rgba(15,23,42,.85);
            display:flex;
            align-items:center;
            justify-content:space-between;
        }
        .metric-main {
            display:flex;
            align-items:center;
            gap:1rem;
        }
        .metric-icon {
            width:46px;height:46px;border-radius:14px;
            background:rgba(15,23,42,.9);
            display:flex;align-items:center;justify-content:center;
            color:#38bdf8;font-size:1.4rem;
        }
        .metric-text-title {
            font-size:.83rem;
            text-transform:uppercase;
            letter-spacing:.12em;
            color:#6b7280;
        }
        .metric-text-value {
            font-size:1.2rem;
            font-weight:700;
            color:#e5e7eb;
        }
        .metric-pill {
            padding:.35rem .9rem;
            border-radius:999px;
            background:rgba(22,163,74,.15);
            color:#4ade80;
            font-size:.8rem;
            font-weight:600;
        }

        /* RIGHT SIDE – LOGIN FORM */
        .admin-form-side {
            background:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:2rem;
        }
        .login-form-container { width:100%;max-width:440px; }

        .form-header { margin-bottom:2.4rem; }
        .form-title {
            font-size:2rem;font-weight:800;color:#0f172a;
            margin-bottom:.4rem;letter-spacing:-.01em;
        }
        .form-subtitle { color:#64748b;font-size:1rem; }

        .alert-modern {
            background:#fef2f2;border:1px solid #fecaca;border-radius:12px;
            color:#dc2626;padding:1rem;margin-bottom:1.4rem;
            display:flex;align-items:center;gap:.75rem;font-size:.94rem;font-weight:500;
        }

        .form-group { margin-bottom:1.5rem; }
        .form-label { display:block;font-size:.87rem;font-weight:600;color:#0f172a;margin-bottom:.5rem; }
        .input-wrapper { position:relative; }
        .input-icon {
            position:absolute;left:1rem;top:50%;transform:translateY(-50%);
            color:#94a3b8;font-size:1.1rem;
        }
        .form-input {
            width:100%;padding:1rem 1rem 1rem 3rem;
            background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;
            color:#0f172a;font-size:1rem;transition:.2s;
        }
        .form-input:focus {
            outline:none;border-color:#6366f1;background:#fff;
            box-shadow:0 0 0 4px rgba(99,102,241,.12);
        }
        .password-toggle {
            position:absolute;right:1rem;top:50%;transform:translateY(-50%);
            background:none;border:none;color:#94a3b8;cursor:pointer;font-size:1.1rem;padding:.25rem;
        }
        .password-toggle:hover { color:#64748b; }

        .btn-submit {
            width:100%;padding:1.1rem;
            background:linear-gradient(135deg,#6366f1 0%,#22c55e 100%);
            border:none;border-radius:12px;color:#fff;font-size:1rem;font-weight:700;
            cursor:pointer;transition:.2s;box-shadow:0 4px 16px rgba(79,70,229,.35);margin-top:.4rem;
        }
        .btn-submit:hover:not(:disabled) {
            transform:translateY(-2px);box-shadow:0 8px 24px rgba(79,70,229,.45);
        }
        .btn-submit:disabled { opacity:.7;cursor:not-allowed; }
        .btn-content { display:flex;align-items:center;justify-content:center;gap:.5rem; }

        .security-notice {
            margin-top:1.5rem;padding:1rem;
            background:#f1f5f9;border:1px solid #e2e8f0;border-radius:12px;
            display:flex;align-items:flex-start;gap:.75rem;
        }
        .security-notice i { color:#0f172a;font-size:1.05rem;margin-top:.1rem; }
        .security-notice-text { font-size:.82rem;color:#64748b;line-height:1.5; }

        .spinner {
            width:1rem;height:1rem;border:2px solid rgba(255,255,255,.35);
            border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite;
        }
        @keyframes spin { to{transform:rotate(360deg);} }

        @media (max-width:992px){
            .admin-login-wrapper{grid-template-columns:1fr;}
            .admin-brand-side{display:none;}
            .admin-form-side{padding:1.5rem;}
        }
        @media (max-width:480px){
            .form-title{font-size:1.8rem;}
            .login-form-container{max-width:100%;}
        }
    </style>
</head>
<body>

<div class="admin-login-wrapper">
    <!-- LEFT SIDE -->
    <div class="admin-brand-side">
        <div class="grid-pattern"></div>
        <div class="gradient-orb"></div>

        <div class="brand-header">
            <div class="brand-logo-mark">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h1 class="brand-heading">
                SweepXpress<br><span>Admin Control</span>
            </h1>
            <p class="brand-description">
                Monitor orders, inventory, and customers in real-time with a dashboard
                designed for speed, clarity, and control.
            </p>

            <!-- Single metric hero card -->
            <div class="brand-metric-card">
                <div class="metric-main">
                    <div class="metric-icon">
                        <i class="bi bi-activity"></i>
                    </div>
                    <div>
                        <div class="metric-text-title">Live System Status</div>
                        <div class="metric-text-value">All services operational</div>
                    </div>
                </div>
                <div class="metric-pill">
                    99.9% uptime
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="admin-form-side">
        <div class="login-form-container">
            <div class="form-header">
                <h2 class="form-title">Welcome Back</h2>
                <p class="form-subtitle">Enter your credentials to access the admin panel</p>
            </div>

            <?php if (!empty($msg)): ?>
                <div class="alert-modern">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span><?= htmlspecialchars($msg) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="adminLoginForm">
                <div class="form-group">
                    <label class="form-label" for="adminEmail">Email Address</label>
                    <div class="input-wrapper">
                        <i class="bi bi-envelope-fill input-icon"></i>
                        <input type="email"
                               name="email"
                               class="form-input"
                               id="adminEmail"
                               placeholder="admin@sweepxpress.com"
                               required
                               autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="adminPassword">Password</label>
                    <div class="input-wrapper">
                        <i class="bi bi-lock-fill input-icon"></i>
                        <input type="password"
                               name="password"
                               class="form-input"
                               id="adminPassword"
                               placeholder="Enter your password"
                               required
                               autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword">
                            <i class="bi bi-eye-fill"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="adminLoginButton">
                    <div class="btn-content">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Access Dashboard</span>
                    </div>
                </button>

                <div class="security-notice">
                    <i class="bi bi-info-circle-fill"></i>
                    <p class="security-notice-text">
                        All admin sign-ins are logged and audited. Use your own credentials only.
                    </p>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const input = document.getElementById('adminPassword');
    const icon = this.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye-fill');
        icon.classList.add('bi-eye-slash-fill');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash-fill');
        icon.classList.add('bi-eye-fill');
    }
});

document.getElementById('adminLoginForm').addEventListener('submit', function () {
    const button = document.getElementById('adminLoginButton');
    button.disabled = true;
    button.innerHTML = `
        <div class="btn-content">
            <div class="spinner"></div>
            <span>Authenticating...</span>
        </div>
    `;
});
</script>

</body>
</html>
