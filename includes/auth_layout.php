<?php
// includes/auth_layout.php — iisang layout para sa login, register, forgot at reset password.
// Hindi dumadaan sa header.php (walang navbar); styles: .sx-auth sa assets/style.css.

if (!function_exists('sx_auth_start')) {
    function sx_auth_start(string $title, string $backHref = '/sweepxpress/index.php', string $backLabel = 'Back to shop'): void
    {
        $esc = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esc($title) ?> · SweepXpress</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/sweepxpress/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/sweepxpress/assets/brand/favicon-32.png">
    <link rel="apple-touch-icon" href="/sweepxpress/assets/brand/apple-touch-icon.png">
</head>
<body class="sx-auth-body">
<div class="sx-auth">
    <aside class="sx-auth-panel">
        <a href="/sweepxpress/index.php" class="sx-auth-brand">
            <span class="sx-logo-mark" aria-hidden="true"><img src="/sweepxpress/assets/brand/logo-256.png" alt=""></span>
            <span class="sx-wordmark sx-wordmark-light">Sweep<span class="sx-wordmark-x">X</span>press</span>
        </a>
        <div>
            <h2>Cleaning supplies for homes and businesses.</h2>
            <p>Order item by item or in bulk, follow every order, and pay on delivery.</p>
            <ul class="sx-auth-points">
                <li><i class="fa-solid fa-boxes-stacked"></i> Real-time stock on every product</li>
                <li><i class="fa-solid fa-file-invoice"></i> Purchase orders for business accounts</li>
                <li><i class="fa-solid fa-shield-halved"></i> Email code on every sign-in</li>
            </ul>
        </div>
        <p class="sx-auth-panel-foot">&copy; <?= date('Y') ?> SweepXpress</p>
    </aside>

    <main class="sx-auth-main">
        <div class="sx-auth-top">
            <a href="<?= $esc($backHref) ?>" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-solid fa-arrow-left"></i> <?= $esc($backLabel) ?></a>
            <a href="/sweepxpress/index.php" class="sx-auth-mobile-brand" aria-label="SweepXpress home">
                <span class="sx-logo-mark" aria-hidden="true"><img src="/sweepxpress/assets/brand/logo-256.png" alt=""></span>
                <span class="sx-wordmark">Sweep<span class="sx-wordmark-x">X</span>press</span>
            </a>
        </div>
        <div class="sx-auth-card">
        <?php
    }

    function sx_auth_end(): void
    {
        ?>
        </div>
    </main>
</div>
<script src="/sweepxpress/assets/script.js?v=<?= filemtime(__DIR__ . '/../assets/script.js') ?>"></script>
</body>
</html>
        <?php
    }

    // Google "G" logo para sa "Continue with Google"
    function sx_google_icon(): string
    {
        return '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true">'
             . '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
             . '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
             . '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
             . '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
             . '</svg>';
    }
}
