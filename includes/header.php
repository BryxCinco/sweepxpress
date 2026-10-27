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

        /* ===== NAVBAR (Phase 9a) — gumagamit ng tokens, handa sa dark mode ===== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1090;
            background: color-mix(in srgb, var(--sx-surface) 88%, transparent) !important;
            backdrop-filter: blur(18px) saturate(1.4);
            -webkit-backdrop-filter: blur(18px) saturate(1.4);
            border-bottom: 1px solid var(--sx-border-light);
            padding-block: 0.75rem;
            transition: padding 0.25s ease, box-shadow 0.25s ease, background 0.25s ease;
        }
        /* Manipis na brand gradient sa ilalim — lumalabas kapag nag-scroll */
        .navbar::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: -1px;
            height: 2px;
            background: var(--sx-gradient-brand);
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .navbar-scrolled {
            padding-block: 0.5rem;
            background: color-mix(in srgb, var(--sx-surface) 96%, transparent) !important;
            box-shadow: var(--sx-shadow-md);
        }
        .navbar-scrolled::after { opacity: 1; }
        /* Neutralize ang lumang "nav a" rule sa style.css (margin-left + opacity sa hover) */
        .navbar a { margin-left: 0; }
        .navbar a:hover { opacity: 1; }

        /* Brand: logo mark + gradient wordmark (.sx-logo-mark / .sx-wordmark sa style.css) */
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-right: 1rem;
            font-size: 1.4rem;
            white-space: nowrap;
        }

        /* Mga link (guest) */
        .navbar-light .nav-link {
            color: var(--sx-muted) !important;
            font-weight: 600;
            font-size: 0.93rem;
            padding: 0.5rem 1rem !important;
            border-radius: var(--sx-radius-pill);
            transition: background var(--sx-transition), color var(--sx-transition);
        }
        .navbar-light .nav-link:hover {
            color: var(--sx-dark) !important;
            background: var(--sx-surface-alt);
        }
        .navbar-light .nav-link.active {
            color: var(--sx-primary) !important;
            background: var(--sx-primary-soft);
        }

        /* Profile chip (link papunta sa settings) */
        .profile-section {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.3rem 1rem 0.3rem 0.3rem;
            background: var(--sx-surface-alt);
            border: 1px solid var(--sx-border-light);
            border-radius: var(--sx-radius-pill);
            text-decoration: none;
            transition: background var(--sx-transition), border-color var(--sx-transition);
        }
        .profile-section:hover {
            background: var(--sx-surface);
            border-color: var(--sx-border);
        }
        .profile-img {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--sx-surface);
            box-shadow: 0 0 0 2px var(--sx-accent);
        }
        .navbar-text {
            margin: 0;
            padding: 0;
            color: var(--sx-dark) !important;
            font-weight: 700;
            font-size: 0.88rem;
            line-height: 1.15;
        }
        .navbar-text small {
            display: block;
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--sx-muted);
        }

        /* Avatar button on top-right (mobile) */
        .mobile-profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--sx-surface);
            box-shadow: 0 0 0 2px var(--sx-accent);
            background: var(--sx-surface-alt);
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

        /* Icon buttons: cart, sidebar burger, guest menu */
        .cart-link,
        #sidebarToggle,
        .navbar-toggler {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            padding: 0;
            flex-shrink: 0;
            font-size: 1.05rem;
            color: var(--sx-primary);
            background: var(--sx-surface-alt);
            border: 1px solid var(--sx-border-light);
            border-radius: var(--sx-radius-md);
            text-decoration: none;
            box-shadow: none;
            transition: background var(--sx-transition), border-color var(--sx-transition), transform var(--sx-transition);
        }
        .cart-link:hover,
        #sidebarToggle:hover,
        .navbar-toggler:hover {
            color: var(--sx-primary);
            background: var(--sx-primary-soft);
            border-color: color-mix(in srgb, var(--sx-primary) 20%, transparent);
            transform: translateY(-1px);
        }
        #sidebarToggle { margin-right: 0.75rem; }
        /* script.js: ang click sa icon ay hindi dapat ituring na "click sa labas" ng sidebar */
        #sidebarToggle i,
        .navbar-toggler i { pointer-events: none; }

        #cart-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            padding-inline: 5px;
            background: var(--sx-accent);
            border: 2px solid var(--sx-surface);
            border-radius: var(--sx-radius-pill);
            font-size: 0.68rem;
            font-weight: 800;
            color: var(--sx-on-accent);
            display: flex;
            align-items: center;
            justify-content: center;
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
                margin-top: 0.75rem;
                padding: 0.75rem;
                background: var(--sx-surface);
                border: 1px solid var(--sx-border-light);
                border-radius: var(--sx-radius-lg);
                box-shadow: var(--sx-shadow-lg);
            }
            .navbar-nav {
                align-items: stretch !important;
                gap: 0.35rem;
            }
            .profile-section {
                width: 100%;
                justify-content: flex-start;
            }
            .navbar-nav .sx-btn {
                width: 100%;
                margin-top: 0.25rem;
            }

            /* Avatar visible on all logged-in pages */
            .mobile-profile-avatar {
                display: inline-flex !important;
            }

            /* Brand truncates before icons are pushed off-screen */
            .navbar-brand {
                flex: 1 1 auto;
                min-width: 0;
                max-width: calc(100% - 150px); /* left burger + right avatar/burger */
                overflow: hidden;
                white-space: nowrap;
                font-size: 1.3rem; /* Adjusted for smaller tablets/large phones */
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
                gap: 0.45rem;
                font-size: 1.1rem; /* Made it "littler" for small mobile devices */
                max-width: calc(100% - 130px);
            }
            .navbar-brand .sx-logo-mark {
                width: 30px;
                height: 30px;
                font-size: 0.95rem;
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

// Label ng role sa profile chip, at kung saan ito papunta.
// "$sx" prefix: pareho ang scope ng header at ng page, kaya iwas-banggaan sa mga variable ng page.
$sxRoleLabels = ['admin' => 'Administrator', 'business' => 'Business account', 'supplier' => 'Supplier', 'customer' => 'Customer'];
$sxRoleLabel = $sxRoleLabels[$_SESSION['user']['role'] ?? ''] ?? 'Customer';
$sxSettingsUrl = is_admin() ? '/sweepxpress/admin/settings_admin.php' : '/sweepxpress/customers/settings_customer.php';

// Para sa "active" na link sa guest menu
$sxCurrentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>

<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container-fluid px-3 px-lg-4">

        <?php if (is_logged_in()): ?>
            <button id="sidebarToggle" type="button" aria-label="Open menu"><i class="fa-solid fa-bars-staggered"></i></button>
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
            <!-- Placeholder ng logo; papalitan ng <img> kapag nasa assets/brand/ na ang transparent PNG -->
            <span class="sx-logo-mark" aria-hidden="true">X</span>
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
                    <i class="fa-solid fa-bars"></i>
                </button>
            <?php endif; ?>
        </div>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-2">
                <?php if (is_logged_in()): ?>
                    <li class="nav-item w-100 w-lg-auto">
                        <a class="profile-section" href="<?php echo $sxSettingsUrl; ?>" title="Account settings">
                            <img src="<?php echo $profileImage; ?>" alt="" class="profile-img">
                            <span class="navbar-text">Hi, <?php echo h($displayName); ?><small><?php echo $sxRoleLabel; ?></small></span>
                        </a>
                    </li>

                    <?php if (!is_admin() && !is_supplier()): ?>
                        <?php 
                            $cartCount = array_sum($_SESSION['cart'] ?? []);
                            $badge_visibility_class = ($cartCount == 0 ? 'd-none' : '');
                        ?>
                        <li class="nav-item d-flex justify-content-lg-center">
                            <a class="cart-link" href="/sweepxpress/cart.php" aria-label="Cart">
                                <i class="fa-solid fa-cart-shopping"></i>
                                <span id="cart-badge" class="<?php echo $badge_visibility_class; ?>">
                                    <span id="cart-badge-count"><?php echo $cartCount; ?></span>
                                </span>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="nav-item d-flex">
                        <a class="sx-btn sx-btn-outline sx-btn-sm ms-lg-1" href="/sweepxpress/logout.php">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
                        </a>
                    </li>

                <?php else: ?>
                    <li class="nav-item"><a class="nav-link <?php echo $sxCurrentPage === 'index.php' ? 'active' : ''; ?>" href="/sweepxpress/index.php">Shop</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $sxCurrentPage === 'about.php' ? 'active' : ''; ?>" href="/sweepxpress/about.php">About Us</a></li>
                    <li class="nav-item"><a class="nav-link <?php echo $sxCurrentPage === 'contact.php' ? 'active' : ''; ?>" href="/sweepxpress/contact.php">Contact</a></li>
                    <!-- Privacy at Terms: nasa footer na -->
                    <li class="nav-item d-flex gap-2 ms-lg-2">
                        <a class="sx-btn sx-btn-ghost" href="/sweepxpress/login.php">Log in</a>
                        <a class="sx-btn sx-btn-dark sx-btn-arrow" href="/sweepxpress/register.php">Sign up <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span></a>
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