<?php
// Slide-out sidebar, included only when logged in (galing sa header.php,
// kaya available dito ang $profileImage, $displayName at $sxRoleLabel).
// Pareho ang scope nito sa page (hal. cart.php), kaya lahat ng variable dito
// ay may "$sx" prefix para hindi ma-overwrite ang $items, $icon, $label ng page.

// Path ng kasalukuyang page na relative sa /sweepxpress/, hal. "admin/order_details.php"
$sxPath = preg_replace('#^.*?/sweepxpress/#', '', $_SERVER['SCRIPT_NAME'] ?? '');

// "active" kapag tugma ang page sa alinman sa mga pattern ng isang menu item
$sxNavActive = function (array $patterns) use ($sxPath) {
    foreach ($patterns as $p) {
        if (fnmatch($p, $sxPath)) return ' active';
    }
    return '';
};

if (is_admin()) {
    $sxNav = [
        'Operations' => [
            ['admin/dashboard.php', 'fa-gauge-high', 'Dashboard', ['admin/dashboard.php']],
            ['admin/products.php', 'fa-box', 'Products', ['admin/products.php', 'admin/add_product.php', 'admin/edit_product.php', 'admin/import_csv.php']],
            ['admin/allorders.php', 'fa-bag-shopping', 'Orders', ['admin/allorders.php', 'admin/order*', 'admin/orders*', 'admin/adminreceipt.php']],
            ['admin/deliveries.php', 'fa-truck-fast', 'Deliveries', ['admin/deliveries*']],
            ['admin/inventory.php', 'fa-warehouse', 'Inventory', ['admin/inventory*', 'admin/stock_*', 'admin/product_history.php']],
            ['admin/customer_management.php', 'fa-users', 'Customers', ['admin/customer_management.php', 'admin/user_actions.php', 'admin/edit_role.php', 'admin/view_logs.php']],
        ],
        'Supply chain' => [
            ['admin/reorder.php', 'fa-cart-flatbed', 'Reorder', ['admin/reorder.php']],
            ['admin/purchase_orders.php', 'fa-file-invoice', 'Purchase orders', ['admin/purchase_order*']],
            ['admin/suppliers.php', 'fa-truck-field', 'Suppliers', ['admin/suppliers.php', 'admin/supplier.php']],
        ],
        'Insights' => [
            ['admin/reports.php', 'fa-chart-line', 'Reports & Analytics', ['admin/reports.php']],
            ['admin/admin_contact.php', 'fa-envelope-open-text', 'Messages', ['admin/admin_contact.php']],
        ],
        'Account' => [
            ['admin/settings_admin.php', 'fa-gear', 'Settings', ['admin/settings_admin.php']],
            ['about.php', 'fa-circle-info', 'About Us', ['about.php']],
        ],
    ];
} else {
    $sxNav = [
        'Shop' => [
            ['index.php', 'fa-store', 'Shop', ['index.php', 'product.php']],
            ['cart.php', 'fa-cart-shopping', 'Cart', ['cart.php', 'checkout.php', 'buy_now_checkout.php', 'business/purchaseorder.php']],
            ['customers/my_orders.php', 'fa-receipt', 'My Orders', ['customers/my_orders.php', 'customers/order_details.php', 'customers/UserReceipt.php']],
        ],
        'Account' => [
            ['customers/settings_customer.php', 'fa-user-gear', 'Settings', ['customers/settings_customer.php']],
            ['about.php', 'fa-circle-info', 'About Us', ['about.php']],
            ['contact.php', 'fa-envelope', 'Contact', ['contact.php']],
        ],
    ];
}
?>
<aside id="sidebar" class="sidebar" aria-label="Main menu">
    <!-- Brand + close -->
    <div class="sidebar-head">
        <a href="<?= is_admin() ? '/sweepxpress/admin/dashboard.php' : '/sweepxpress/index.php' ?>" class="sidebar-brand">
            <span class="sx-logo-mark" aria-hidden="true"><img src="/sweepxpress/assets/brand/logo-256.png" alt=""></span>
            <span class="sx-wordmark">Sweep<span class="sx-wordmark-x">X</span>press</span>
        </a>
        <button class="sidebar-close" id="closeSidebar" type="button" aria-label="Close sidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <!-- User -->
    <div class="sidebar-user">
        <img src="<?= $profileImage ?>" alt="">
        <div>
            <strong><?= h($displayName) ?></strong>
            <span><?= $sxRoleLabel ?></span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">
        <?php foreach ($sxNav as $sxSection => $sxItems): ?>
            <div class="nav-section">
                <p class="nav-section-title"><?= $sxSection ?></p>
                <?php foreach ($sxItems as [$sxHref, $sxIcon, $sxLabel, $sxPatterns]): ?>
                    <?php $sxActive = $sxNavActive($sxPatterns); ?>
                    <a href="/sweepxpress/<?= $sxHref ?>" class="nav-card<?= $sxActive ?>"<?= $sxActive ? ' aria-current="page"' : '' ?>>
                        <span class="nav-icon"><i class="fa-solid <?= $sxIcon ?>"></i></span>
                        <span class="nav-label"><?= h($sxLabel) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php unset($sxSection, $sxItems, $sxHref, $sxIcon, $sxLabel, $sxPatterns, $sxActive); ?>
    </nav>

    <!-- Footer -->
    <div class="sidebar-foot">
        <a href="/sweepxpress/logout.php" class="sx-btn sx-btn-outline sx-btn-block">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
        </a>
    </div>
</aside>

<!-- Overlay -->
<div id="sidebarOverlay" class="sidebar-overlay"></div>

<style>
/* ===== SIDEBAR (Phase 9a) — tokens, handa sa dark mode ===== */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 1100;                  /* nasa ibabaw ng navbar (1090) para kita ang brand at close */
    width: min(300px, 85vw);
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
    background: var(--sx-surface);
    border-right: 1px solid var(--sx-border-light);
    box-shadow: var(--sx-shadow-lg);
    color: var(--sx-text);
    overflow: hidden;
    transform: translateX(-105%);
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.sidebar.active {
    transform: translateX(0);
    box-shadow: var(--sx-shadow-lg);
}

/* Brand + close */
.sidebar-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--sx-space-3);
    padding: var(--sx-space-5) var(--sx-space-5) var(--sx-space-4);
}
.sidebar-brand {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 1.25rem;
    text-decoration: none;
}
.sidebar-close {
    display: inline-grid;
    place-items: center;
    width: 36px;
    height: 36px;
    flex-shrink: 0;
    border: 1px solid var(--sx-border-light);
    border-radius: var(--sx-radius-sm);
    background: var(--sx-surface-alt);
    color: var(--sx-muted);
    cursor: pointer;
    transition: transform var(--sx-transition), color var(--sx-transition), background var(--sx-transition);
}
.sidebar-close:hover {
    color: var(--sx-dark);
    background: var(--sx-surface);
    transform: rotate(90deg);
}

/* User card */
.sidebar-user {
    display: flex;
    align-items: center;
    gap: var(--sx-space-3);
    margin: 0 var(--sx-space-5) var(--sx-space-4);
    padding: var(--sx-space-3);
    border-radius: var(--sx-radius-lg);
    background: var(--sx-surface-alt);
    border: 1px solid var(--sx-border-light);
}
.sidebar-user img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid var(--sx-surface);
    box-shadow: 0 0 0 2px var(--sx-accent);
}
.sidebar-user strong {
    display: block;
    font-size: var(--sx-text-sm);
    color: var(--sx-dark);
    line-height: 1.2;
}
.sidebar-user span {
    font-size: var(--sx-text-xs);
    color: var(--sx-muted);
}

/* Navigation */
.sidebar-nav {
    flex: 1;
    overflow-y: auto;
    padding: 0 var(--sx-space-4) var(--sx-space-4);
}
.sidebar-nav::-webkit-scrollbar { width: 5px; }
.sidebar-nav::-webkit-scrollbar-thumb {
    background: var(--sx-border);
    border-radius: 10px;
}
.nav-section + .nav-section { margin-top: var(--sx-space-4); }
.nav-section-title {
    margin: 0 0 var(--sx-space-2);
    padding: 0 var(--sx-space-3);
    font-size: 0.7rem;
    font-weight: var(--sx-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sx-muted);
}
.nav-card {
    position: relative;
    display: flex;
    align-items: center;
    gap: var(--sx-space-3);
    margin: 0 0 2px;
    padding: 10px var(--sx-space-3);
    border-radius: var(--sx-radius-md);
    color: var(--sx-text);
    font-size: var(--sx-text-sm);
    font-weight: var(--sx-weight-medium);
    text-decoration: none;
    transition: background var(--sx-transition), color var(--sx-transition);
}
.nav-card:hover {
    background: var(--sx-surface-alt);
    color: var(--sx-dark);
}
.nav-icon {
    display: inline-grid;
    place-items: center;
    width: 36px;
    height: 36px;
    flex-shrink: 0;
    border-radius: var(--sx-radius-sm);
    background: var(--sx-surface-alt);
    color: var(--sx-muted);
    font-size: 0.95rem;
    transition: background var(--sx-transition), color var(--sx-transition);
}
.nav-card:hover .nav-icon { color: var(--sx-primary); }
.nav-label { flex: 1; white-space: nowrap; }

/* Active: navy na pill + emerald na bar sa kaliwa */
.nav-card.active {
    background: var(--sx-primary-soft);
    color: var(--sx-primary);
    font-weight: var(--sx-weight-semibold);
}
.nav-card.active::before {
    content: "";
    position: absolute;
    left: -16px;
    top: 8px;
    bottom: 8px;
    width: 4px;
    border-radius: 0 4px 4px 0;
    background: var(--sx-accent);
}
.nav-card.active .nav-icon {
    background: var(--sx-primary);
    color: var(--sx-on-primary);
}

/* Footer */
.sidebar-foot {
    padding: var(--sx-space-4) var(--sx-space-5) var(--sx-space-5);
    border-top: 1px solid var(--sx-border-light);
}

/* Overlay */
.sidebar-overlay {
    position: fixed;
    inset: 0;
    z-index: 1095;
    background: rgba(11, 23, 53, 0.45);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease, visibility 0.3s ease;
}
.sidebar-overlay.active {
    opacity: 1;
    visibility: visible;
}

@media (prefers-reduced-motion: reduce) {
    .sidebar,
    .sidebar-close,
    .sidebar-overlay,
    .nav-card,
    .nav-icon { transition: none; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const closeBtn = document.getElementById('closeSidebar');
    const burger = document.getElementById('sidebarToggle');

    // Ang "active" na menu item ay itinatakda na sa PHP sa itaas

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
