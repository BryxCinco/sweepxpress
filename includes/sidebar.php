<?php
// Slide-out sidebar, included only when logged in.
?>
<div id="sidebar" class="sidebar">
    <!-- Close Button -->
    <button class="sidebar-close" id="closeSidebar" aria-label="Close sidebar">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
            <path d="M15 5L5 15M5 5L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
    </button>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <?php if (is_admin()): ?>
            <!-- Admin Navigation -->
            <div class="nav-section">
                <a href="/sweepxpress/admin/dashboard.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-speedometer2"></i></div>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="/sweepxpress/admin/products.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-box-seam"></i></div>
                    <span class="nav-label">Products</span>
                </a>
                <a href="/sweepxpress/admin/allorders.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-bag-check"></i></div>
                    <span class="nav-label">Orders</span>
                </a>
                <a href="/sweepxpress/admin/deliveries.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-truck"></i></div>
                    <span class="nav-label">Deliveries</span>
                </a>
                <a href="/sweepxpress/admin/inventory.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-archive"></i></div>
                    <span class="nav-label">Inventory</span>
                </a>
                <a href="/sweepxpress/admin/customer_management.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-people"></i></div>
                    <span class="nav-label">Customers</span>
                </a>
            </div>

            <div class="section-divider"></div>

            <div class="nav-section">
                <a href="/sweepxpress/admin/reports.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-bar-chart-line"></i></div>
                    <span class="nav-label">Reports & Analytics</span>
                </a>
                <a href="/sweepxpress/admin/admin_contact.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-envelope-paper"></i></div>
                    <span class="nav-label">Messages</span>
                </a>
            </div>

            <div class="section-divider"></div>

            <div class="nav-section">
                <a href="/sweepxpress/admin/settings_admin.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-gear"></i></div>
                    <span class="nav-label">Settings</span>
                </a>
                <a href="/sweepxpress/about.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-info-circle"></i></div>
                    <span class="nav-label">About Us</span>
                </a>
            </div>

        <?php else: ?>
            <!-- Customer Navigation -->
            <div class="nav-section">
                <a href="/sweepxpress/index.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-house-door"></i></div>
                    <span class="nav-label">Home</span>
                </a>
                <a href="/sweepxpress/customers/my_orders.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-receipt-cutoff"></i></div>
                    <span class="nav-label">My Orders</span>
                </a>
            </div>

            <div class="section-divider"></div>

            <div class="nav-section">
                <a href="/sweepxpress/customers/settings_customer.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-person-gear"></i></div>
                    <span class="nav-label">Settings</span>
                </a>
                <a href="/sweepxpress/about.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-info-circle"></i></div>
                    <span class="nav-label">About Us</span>
                </a>
                <a href="/sweepxpress/contact.php" class="nav-card">
                    <div class="nav-icon"><i class="bi bi-envelope"></i></div>
                    <span class="nav-label">Contact</span>
                </a>
            </div>
        <?php endif; ?>
    </nav>
</div>

<!-- Overlay for mobile -->
<div id="sidebarOverlay" class="sidebar-overlay"></div>

<style>
/* SIDEBAR SHELL */
.sidebar {
    position: fixed;
    top: 0;
    left: -320px;
    width: 320px;
    height: 100vh;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
    transition: left 0.35s cubic-bezier(0.22, 1, 0.36, 1);
    z-index: 1050;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 8px 0 40px rgba(0, 0, 0, 0.08);
}
.sidebar.active {
    left: 0;
}

/* Scrollbar */
.sidebar::-webkit-scrollbar {
    width: 5px;
}
.sidebar::-webkit-scrollbar-track {
    background: transparent;
}
.sidebar::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, 0.3);
    border-radius: 10px;
}
.sidebar::-webkit-scrollbar-thumb:hover {
    background: rgba(148, 163, 184, 0.5);
}

/* CLOSE BUTTON */
.sidebar-close {
    position: absolute;
    top: 24px;
    right: 24px;
    width: 36px;
    height: 36px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #64748b;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    z-index: 10;
}
.sidebar-close:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #334155;
    transform: rotate(90deg);
}
.sidebar-close:active {
    transform: rotate(90deg) scale(0.95);
}

/* NAV CONTENT */
.sidebar-nav {
    padding: 80px 20px 24px;
    flex: 1;
}
.nav-section {
    margin-bottom: 20px;
}
.nav-card {
    display: flex;
    align-items: center;
    padding: 16px 18px;
    margin-bottom: 8px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    text-decoration: none;
    color: #334155;
    font-size: 15px;
    font-weight: 500;
    transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    position: relative;
    overflow: hidden;
}
.nav-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
    transform: scaleY(0);
    transition: transform 0.25s ease;
}
.nav-card:hover {
    background: #ffffff;
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
}
.nav-card:hover::before {
    transform: scaleY(1);
}
.nav-card:hover .nav-icon {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
    transform: scale(1.05);
}
.nav-card:hover .nav-label {
    color: #1e40af;
}

/* Active state */
.nav-card.active {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-color: #3b82f6;
    box-shadow: 0 4px 16px rgba(59, 130, 246, 0.12);
}
.nav-card.active::before {
    transform: scaleY(1);
}
.nav-card.active .nav-icon {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: #ffffff;
}
.nav-card.active .nav-label {
    color: #1e40af;
    font-weight: 600;
}

.nav-icon {
    width: 42px;
    height: 42px;
    min-width: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    border-radius: 11px;
    margin-right: 14px;
    color: #64748b;
    font-size: 20px;
    transition: all 0.25s ease;
}
.nav-icon i { display:flex; }

.nav-label {
    flex: 1;
    font-size: 15px;
    letter-spacing: -0.01em;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.section-divider {
    height: 1px;
    background: linear-gradient(90deg, transparent 0%, #e2e8f0 50%, transparent 100%);
    margin: 20px 0;
}

/* OVERLAY */
.sidebar-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100vh;
    background: rgba(0, 0, 0, 0.4);
    backdrop-filter: blur(2px);
    opacity: 0;
    visibility: hidden;
    transition: all 0.35s ease;
    z-index: 1040;
}
.sidebar-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* RESPONSIVE: show only partial width on mobile */
@media (max-width: 768px) {
    .sidebar {
        width: 60vw;
        max-width: 360px;
        left: -100%;
    }
}
@media (max-width: 480px) {
    .sidebar {
        width: 65vw;
        max-width: 100%;
    }
    .sidebar-nav {
        padding: 70px 16px 20px;
    }
    .nav-card {
        padding: 14px 16px;
    }
    .nav-icon {
        width: 38px;
        height: 38px;
        min-width: 38px;
        margin-right: 12px;
    }
}

/* Accessibility/reduced motion */
.nav-card:focus {
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
}
.nav-card:focus:not(:focus-visible) {
    outline: none;
}
@media (prefers-reduced-motion: reduce) {
    .sidebar,
    .nav-card,
    .sidebar-close,
    .sidebar-overlay {
        transition: none;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const closeBtn = document.getElementById('closeSidebar');
    const burger = document.getElementById('sidebarToggle');
    const navCards = document.querySelectorAll('.nav-card');
    const currentPath = window.location.pathname;

    // Keep your existing active link logic
    navCards.forEach(card => {
        const linkPath = card.getAttribute('href');
        if (linkPath === currentPath || 
            (currentPath.includes(linkPath) && linkPath !== 'sweepxpressindex.php')) {
            card.classList.add('active');
        }
    });

    function openSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    // FIXED: Burger toggle - checks if sidebar is active, then closes it
    if (burger) {
        burger.addEventListener('click', function(e) {
            e.preventDefault();
            if (sidebar.classList.contains('active')) {
                closeSidebar();  // THIS FIXES the hide problem
            } else {
                openSidebar();
            }
        });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && sidebar.classList.contains('active')) {
            closeSidebar();
        }
    });
});

</script>
