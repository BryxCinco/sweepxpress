<?php
require_once __DIR__ . '/../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SweepXpress</title>

    <!-- Font: Plus Jakarta Sans (iisang font ng buong site) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- PANSAMANTALA: ginagamit pa ng 16 file; aalisin sa Phase 9 kapag Font Awesome na lahat -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <!-- Huling CSS para ang design tokens at sx- components ang masunod -->
    <link rel="stylesheet" href="/sweepxpress/assets/style.css">

    <style>
        /* (CSS is omitted for brevity, it remains the same as previously provided) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        /* Walang outline sa pag-click ng mouse; may focus ring kapag keyboard (Tab) */
        *:focus:not(:focus-visible) {
            outline: none;
        }
        body {
            padding-top: 80px;
            overscroll-behavior-y: contain;
        }

        /* ===== NAVBAR BASE ===== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1090;
            background: rgba(255, 255, 255, 0.96) !important;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            padding-block: 0.8rem;
            transition: all 0.25s ease;
            box-shadow: 0 2px 16px rgba(15, 23, 42, 0.04);
        }
        .navbar-scrolled {
            background: rgba(255, 255, 255, 0.99) !important;
            box-shadow: 0 4px 24px rgba(15, 23, 42, 0.12);
        }

        .navbar-brand {
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #0f172a !important;
            display: flex;
            align-items: center;
            gap: 0.15rem;
            white-space: nowrap;
        }
        /* LOGO: gradient wordmark (.sx-wordmark sa style.css) */

        .navbar-light .nav-link {
            color: #334155 !important;
            font-weight: 600;
            font-size: 0.93rem;
            padding: 0.5rem 0.9rem !important;
            border-radius: 999px;
            transition: background 0.18s ease, color 0.18s ease;
        }
        .navbar-light .nav-link:hover {
            color: var(--sx-primary) !important;
            background: color-mix(in srgb, var(--sx-primary) 8%, transparent);
        }

        /* Profile chip inside menu */
        .profile-section {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.35rem 0.8rem;
            background: color-mix(in srgb, var(--sx-primary) 6%, transparent);
            border-radius: 999px;
        }
        .profile-img {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--sx-primary);
        }
        .navbar-text {
            color: #1e293b !important;
            font-weight: 600;
            font-size: 0.9rem;
            margin: 0;
        }

        /* Avatar button on top-right */
        .mobile-profile-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--sx-primary);
            background: color-mix(in srgb, var(--sx-primary) 6%, transparent);
            display: none; /* shown on mobile when logged in */
            align-items: center;
            justify-content: center;
            padding: 0;
            cursor: pointer;
            flex-shrink: 0;
        }
        .mobile-profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Cart */
        .cart-link {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: color-mix(in srgb, var(--sx-primary) 6%, transparent);
            border-radius: 50%;
            transition: background 0.18s ease, transform 0.16s ease;
        }
        .cart-link:hover {
            background: color-mix(in srgb, var(--sx-primary) 14%, transparent);
            transform: translateY(-1px);
        }
        .cart-link i {
            color: var(--sx-primary);
            font-size: 1.2rem;
        }
        #cart-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            min-width: 18px;
            height: 18px;
            padding-inline: 4px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.45);
        }

        /* Logout */
        .btn-logout {
            background: linear-gradient(135deg, var(--sx-primary) 0%, var(--sx-primary-light) 100%);
            color: #ffffff !important;
            border: none;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 0.55rem 1.6rem;
            border-radius: 999px;
            box-shadow: 0 3px 12px color-mix(in srgb, var(--sx-primary) 30%, transparent);
        }

        /* Sidebar burger (left) */
        #sidebarToggle {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border: 2px solid color-mix(in srgb, var(--sx-primary) 22%, transparent);
            background: color-mix(in srgb, var(--sx-primary) 6%, transparent);
            color: var(--sx-primary);
            font-size: 1.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.6rem;
            flex-shrink: 0;
        }

        .navbar-toggler {
            border: none;
            padding: 0.25rem 0.45rem;
        }
        .navbar-toggler-icon {
            background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(30,58,138,0.9)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
            width:1.4em;
            height:1.4em;
        }

        /* ===== PULL TO REFRESH ===== */
        .ptr-indicator {
            position: fixed;
            top: 85px;
            left: 50%;
            transform: translateX(-50%) translateY(-80px);
            width: 40px;
            height: 40px;
            z-index: 1000;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            opacity: 0;
        }
        .ptr-indicator.ptr-visible {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        .ptr-spinner {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 4px 16px color-mix(in srgb, var(--sx-primary) 20%, transparent);
        }
        .ptr-spinner::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 28px;
            height: 28px;
            margin-top: -14px;
            margin-left: -14px;
            border: 3px solid color-mix(in srgb, var(--sx-primary) 12%, transparent);
            border-radius: 50%;
        }
        .ptr-spinner::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 28px;
            height: 28px;
            margin-top: -14px;
            margin-left: -14px;
            border: 3px solid transparent;
            border-top-color: var(--sx-primary);
            border-right-color: var(--sx-primary);
            border-radius: 50%;
            transition: transform 0.12s ease-out;
        }
        .ptr-indicator.ptr-loading .ptr-spinner::after {
            animation: ptr-spin 0.65s linear infinite;
        }
        @keyframes ptr-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (min-width: 1024px) {
            .ptr-indicator {
                display: none;
            }
        }

        /* ===== ADAPTIVE LAYOUT FOR ALL DEVICES ===== */
        @media (max-width: 991.98px) {
            body { padding-top: 70px; }
            .ptr-indicator { top: 75px; }

            .navbar .container-fluid {
                display: flex;
                align-items: center;
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            .navbar-collapse {
                margin-top:0.6rem;
                padding:0.75rem 0.85rem;
                background:#ffffff;
                border-radius:14px;
                box-shadow:0 12px 26px rgba(15,23,42,0.15);
            }
            .navbar-nav {
                align-items:stretch !important;
                gap:0.25rem;
            }
            .profile-section {
                width:100%;
                justify-content:flex-start;
            }
            .btn-logout {
                width:100%;
                margin-top:0.4rem;
            }

            /* Avatar visible on all logged-in pages */
            .mobile-profile-avatar {
                display: inline-flex !important;
            }

            /* Brand truncates before icons are pushed off-screen */
            .navbar-brand {
                flex: 1 1 auto;
                max-width: calc(100% - 150px); /* left burger + right avatar/burger */
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                font-size: 1.35rem; /* Adjusted for smaller tablets/large phones */
            }

            /* Right-side controls never shrink */
            .header-right,
            .navbar-toggler.ms-auto {
                flex: 0 0 auto;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
        }

        @media (max-width: 575.98px) {
            body { padding-top: 65px; }
            .ptr-indicator { top: 68px; }

            /* *** CHANGE FOR SMALLER LOGO ON SMALL MOBILE SCREENS *** */
            .navbar-brand {
                font-size: 1.1rem; /* Made it "littler" for small mobile devices */
                max-width: calc(100% - 130px);
            }
        }
    </style>

    <script>
        function updateCartBadge(newCount) {
            const countDisplay = document.getElementById('cart-badge-count');
            const badge = document.getElementById('cart-badge');
            if (!countDisplay || !badge) return;
            countDisplay.textContent = newCount;
            badge.classList.toggle('d-none', newCount <= 0);
        }

        window.addEventListener('scroll', function () {
            const navbar = document.querySelector('.navbar');
            if (!navbar) return;
            navbar.classList.toggle('navbar-scrolled', window.scrollY > 50);
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="/sweepxpress/assets/script.js" defer></script>
</head>
<body>

<div class="ptr-indicator" id="ptrIndicator">
    <div class="ptr-spinner"></div>
</div>

<?php
// Prepare profile data once for all pages (users + admin)
$displayName = 'User';
$profileImage = '/sweepxpress/assets/profile-icon.jpg'; // Default image

if (is_logged_in() && isset($_SESSION['user'])) {
    if (!empty($_SESSION['user']['username'])) {
        $displayName = $_SESSION['user']['username'];
    } elseif (!empty($_SESSION['user']['name'])) {
        $displayName = $_SESSION['user']['name'];
    }
    
    // CRITICAL FIX: Ensure we check for 'profile_image'
    if (!empty($_SESSION['user']['profile_image'])) {
        // Add a cache-busting timestamp to the profile image URL
        $imagePath = h($_SESSION['user']['profile_image']);
        $profileImage = $imagePath . '?' . time(); 
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container-fluid px-3 px-lg-4">

        <?php if (is_logged_in()): ?>
            <button class="btn" id="sidebarToggle" type="button">☰</button>
        <?php endif; ?>

        <a class="navbar-brand" href="<?php 
            if (is_admin()) {
                echo '/sweepxpress/admin/dashboard.php';
            } elseif (is_supplier()) {
                echo '/sweepxpress/supplier/dashboard.php';
            } else {
                echo '/sweepxpress/index.php';
            }
        ?>">
            <span class="sx-wordmark">Sweep<span class="sx-wordmark-x">X</span>press</span>
        </a>

        <div class="header-right d-flex align-items-center gap-2 ms-auto">
            <?php if (is_logged_in()): ?>
                <button class="mobile-profile-avatar"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#navbarNav"
                        aria-controls="navbarNav"
                        aria-expanded="false"
                        aria-label="Toggle menu">
                    <img src="<?php echo $profileImage; ?>" alt="<?php echo h($displayName); ?>">
                </button>
            <?php else: ?>
                <button class="navbar-toggler ms-auto"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#navbarNav"
                        aria-controls="navbarNav"
                        aria-expanded="false"
                        aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            <?php endif; ?>
        </div>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-2">
                <?php if (is_logged_in()): ?>
                    <li class="nav-item w-100 w-lg-auto">
                        <div class="profile-section">
                            <img src="<?php echo $profileImage; ?>" alt="Profile" class="profile-img">
                            <span class="navbar-text">Hi, <?php echo h($displayName); ?></span>
                        </div>
                    </li>

                    <?php if (!is_admin() && !is_supplier()): ?>
                        <?php 
                            $cartCount = array_sum($_SESSION['cart'] ?? []);
                            $badge_visibility_class = ($cartCount == 0 ? 'd-none' : '');
                        ?>
                        <li class="nav-item d-flex justify-content-lg-center">
                            <a class="cart-link" href="/sweepxpress/cart.php">
                                <i class="bi bi-cart-fill"></i>
                                <span id="cart-badge" class="<?php echo $badge_visibility_class; ?>">
                                    <span id="cart-badge-count"><?php echo $cartCount; ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item d-flex">
                        <a class="btn btn-logout ms-lg-1" href="/sweepxpress/logout.php">Logout</a>
                    </li>

                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="/sweepxpress/index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="/sweepxpress/about.php">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="/sweepxpress/contact.php">Contact</a></li>
                    <li class="nav-item"><a class="nav-link" href="/sweepxpress/privacy.php">Privacy Policy</a></li>
                    <li class="nav-item"><a class="nav-link" href="/sweepxpress/terms.php">Terms &amp; Conditions</a></li>
                    <li class="nav-item d-flex">
                        <a class="btn btn-logout ms-lg-1" href="/sweepxpress/login.php">Login</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<?php if (is_logged_in()) require_once __DIR__ . '/sidebar.php'; ?>

<main class="container-fluid p-0 m-0">

<script>
// ============== PULL TO REFRESH ==============
document.addEventListener('DOMContentLoaded', function() {
    // Only enable for smaller screens (less than 1024px)
    if (window.innerWidth >= 1024) return;

    const ptrIndicator = document.getElementById('ptrIndicator');
    if (!ptrIndicator) return;
    
    const ptrSpinner = ptrIndicator.querySelector('.ptr-spinner');
    let startY = 0;
    let currentY = 0;
    let isPulling = false;
    let isRefreshing = false;
    const threshold = 80; // Distance needed to trigger refresh
    
    function updateIndicator(distance) {
        if (distance > 15) {
            ptrIndicator.classList.add('ptr-visible');
            // Rotate the spinner more slowly until threshold is reached
            const rotation = Math.min((distance / threshold) * 360, 360);
            ptrSpinner.style.transform = `translateY(${distance/2}px) rotate(${rotation}deg)`;
        } else {
            ptrIndicator.classList.remove('ptr-visible');
            ptrSpinner.style.transform = '';
        }
    }
    
    function startRefresh() {
        if (isRefreshing) return;
        isRefreshing = true;
        ptrIndicator.classList.add('ptr-loading');
        // Reset transformation and start infinite spin for loading state
        ptrSpinner.style.transform = 'translateY(40px)'; // Hold position visually
        
        // Wait a moment then reload the page
        setTimeout(() => { window.location.reload(); }, 700);
    }
    
    function resetPTR() {
        isPulling = false;
        // The CSS transition handles the snap-back
        ptrIndicator.classList.remove('ptr-visible', 'ptr-loading');
        ptrSpinner.style.transform = '';
    }

    // Check if the scroll is at the top of the page (important for PTR)
    function isAtTop() {
        return window.pageYOffset === 0;
    }
    
    document.addEventListener('touchstart', function(e) {
        // Only start pulling if at the very top of the page
        if (isAtTop() && !isRefreshing) {
            startY = e.touches[0].clientY;
            isPulling = true;
        }
    }, { passive: true });
    
    document.addEventListener('touchmove', function(e) {
        if (!isPulling || isRefreshing) return;
        currentY = e.touches[0].clientY;
        const distance = Math.max(currentY - startY, 0);

        // Prevent scroll until pull threshold is met or distance is zero
        if (distance > 0 && isAtTop()) {
             // Limit the distance the indicator visually moves 
             const limitedDistance = Math.min(distance, threshold * 1.5); 
             updateIndicator(limitedDistance);
             e.preventDefault(); // Stop scrolling the body while pulling
        } else if (distance > 0) {
            // Stop pulling if the user scrolls down from the top (as a safety)
            isPulling = false;
            resetPTR();
        }
    }, { passive: false }); // Needs to be passive: false for e.preventDefault()
    
    document.addEventListener('touchend', function() {
        if (!isPulling || isRefreshing) return;
        const distance = currentY - startY;

        if (distance >= threshold) {
            startRefresh();
        } else {
            resetPTR();
        }
        
        // Reset coordinates and pulling state
        startY = 0;
        currentY = 0;
        isPulling = false;
    });
});
</script>