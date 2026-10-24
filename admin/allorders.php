<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
    function h($text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

$currentAdmin = $_SESSION['user'] ?? [];

// ORDER-SPECIFIC QUERIES FOR STATS CARDS
$totalOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders")->fetch()['cnt'];
$pendingOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'pending'")->fetch()['cnt'];
$cancellationRequests = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'cancellation_requested'")->fetch()['cnt'];
$ordersComplete = $pdo->query("SELECT COUNT(*) AS cnt FROM orders WHERE status = 'delivered'")->fetch()['cnt'];
$archivedOrders = $pdo->query("SELECT COUNT(*) AS cnt FROM orders")->fetch()['cnt'];

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Poppins:wght@600;700;800;900&display=swap');

:root {
    --primary: #5b71f6;
    --primary-dark: #4556d8;
    --secondary: #7c3aed;
    --success: #0ea5e9;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #06b6d4;
    
    --bg-main: #fafbff;
    --bg-elevated: #ffffff;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-tertiary: #94a3b8;
    
    --radius-2xl: 32px;
    --radius-xl: 24px;
    --radius-lg: 20px;
    --radius-md: 16px;
    
    --shadow-elevation-1: 0 2px 8px rgba(15, 23, 42, 0.04);
    --shadow-elevation-2: 0 8px 24px rgba(15, 23, 42, 0.08);
    --shadow-elevation-3: 0 16px 48px rgba(15, 23, 42, 0.12);
    --shadow-elevation-4: 0 24px 64px rgba(15, 23, 42, 0.16);
    
    --glow-primary: 0 8px 32px rgba(91, 113, 246, 0.25);
    --glow-success: 0 8px 32px rgba(14, 165, 233, 0.25);
    --glow-warning: 0 8px 32px rgba(245, 158, 11, 0.25);
    --glow-danger: 0 8px 32px rgba(239, 68, 68, 0.25);
    --glow-info: 0 8px 32px rgba(6, 182, 212, 0.25);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background: linear-gradient(135deg, #f0f4ff 0%, #fafbff 50%, #f5f8ff 100%);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    position: relative;
    overflow-x: hidden;
}

body::before {
    content: '';
    position: fixed;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle, rgba(91, 113, 246, 0.03) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
}

.orders-ultra-container {
    max-width: 1440px;
    margin: 0 auto;
    padding: 48px 32px 64px;
    position: relative;
    z-index: 1;
}

/* ============================================
   ULTRA-PREMIUM HEADER
   ============================================ */
.header-ultra {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 48px;
    gap: 24px;
    flex-wrap: wrap;
}

.header-left-ultra {
    flex: 1;
}

.header-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, rgba(91, 113, 246, 0.08) 0%, rgba(124, 58, 237, 0.08) 100%);
    border: 1px solid rgba(91, 113, 246, 0.12);
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--primary);
    margin-bottom: 16px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.title-ultra {
    font-family: 'Poppins', sans-serif;
    font-size: 3rem;
    font-weight: 900;
    color: var(--text-primary);
    letter-spacing: -0.04em;
    line-height: 1.1;
    margin-bottom: 12px;
    background: linear-gradient(135deg, #0f172a 0%, #334155 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.subtitle-ultra {
    font-size: 1.05rem;
    color: var(--text-secondary);
    font-weight: 400;
}

.header-actions-ultra {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-ultra {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 24px;
    border-radius: var(--radius-lg);
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
}

.btn-ultra::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 100%);
    opacity: 0;
    transition: opacity 0.3s;
}

.btn-ultra:hover::before {
    opacity: 1;
}

.btn-ultra.primary {
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    color: #ffffff;
    box-shadow: var(--shadow-elevation-2), var(--glow-primary);
}

.btn-ultra.primary:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: var(--shadow-elevation-4), var(--glow-primary);
    color: #ffffff;
}

.btn-ultra.secondary {
    background: var(--bg-elevated);
    color: var(--text-primary);
    border: 2px solid rgba(15, 23, 42, 0.08);
    box-shadow: var(--shadow-elevation-1);
}

.btn-ultra.secondary:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-elevation-3);
    border-color: rgba(15, 23, 42, 0.12);
    color: var(--text-primary);
}

/* ============================================
   FLOATING STATS CARDS
   ============================================ */
.stats-grid-ultra {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 28px;
    margin-bottom: 48px;
}

.stat-card-ultra {
    background: var(--bg-elevated);
    border-radius: var(--radius-2xl);
    padding: 32px 28px;
    border: 1px solid rgba(15, 23, 42, 0.04);
    box-shadow: var(--shadow-elevation-2);
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    display: block;
}

.stat-card-ultra::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at top right, rgba(91, 113, 246, 0.03), transparent);
    opacity: 0;
    transition: opacity 0.4s;
}

.stat-card-ultra:hover {
    transform: translateY(-12px) scale(1.02);
    box-shadow: var(--shadow-elevation-4);
    border-color: rgba(91, 113, 246, 0.12);
}

.stat-card-ultra:hover::before {
    opacity: 1;
}

.stat-card-ultra::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--card-gradient-start), var(--card-gradient-end));
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.stat-card-ultra:hover::after {
    transform: scaleX(1);
}

/* Card Variants */
.stat-card-ultra.variant-blue {
    --card-gradient-start: #5b71f6;
    --card-gradient-end: #6b84ff;
    --card-icon-bg: linear-gradient(135deg, #5b71f6 0%, #6b84ff 100%);
}

.stat-card-ultra.variant-yellow {
    --card-gradient-start: #f59e0b;
    --card-gradient-end: #fbbf24;
    --card-icon-bg: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
}

.stat-card-ultra.variant-red {
    --card-gradient-start: #ef4444;
    --card-gradient-end: #f87171;
    --card-icon-bg: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
}

.stat-card-ultra.variant-green {
    --card-gradient-start: #0ea5e9;
    --card-gradient-end: #38bdf8;
    --card-icon-bg: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 100%);
}

.stat-card-ultra.variant-cyan {
    --card-gradient-start: #06b6d4;
    --card-gradient-end: #22d3ee;
    --card-icon-bg: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
}

.stat-card-header-ultra {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
}

.stat-icon-ultra {
    width: 64px;
    height: 64px;
    border-radius: var(--radius-lg);
    background: var(--card-icon-bg);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.8rem;
    box-shadow: 0 12px 32px rgba(91, 113, 246, 0.3);
    position: relative;
}

.stat-icon-ultra::before {
    content: '';
    position: absolute;
    inset: -4px;
    background: inherit;
    border-radius: inherit;
    opacity: 0.3;
    filter: blur(12px);
}

.stat-info-ultra {
    flex: 1;
    text-align: right;
}

.stat-label-ultra {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 8px;
}

.stat-value-ultra {
    font-family: 'Poppins', sans-serif;
    font-size: 3rem;
    font-weight: 900;
    line-height: 1;
    color: var(--text-primary);
    letter-spacing: -0.03em;
}

.stat-footer-ultra {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 20px;
    border-top: 1px solid rgba(15, 23, 42, 0.06);
}

.stat-badge-ultra {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    background: linear-gradient(135deg, rgba(91, 113, 246, 0.08), rgba(124, 58, 237, 0.08));
    color: var(--primary);
    border: 1px solid rgba(91, 113, 246, 0.12);
}

.stat-arrow-ultra {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(91, 113, 246, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    transition: all 0.3s;
}

.stat-card-ultra:hover .stat-arrow-ultra {
    background: var(--card-icon-bg);
    color: #ffffff;
    transform: translateX(4px);
}

/* ============================================
   PREMIUM INFO BANNER
   ============================================ */
.info-banner-ultra {
    background: linear-gradient(135deg, #ffffff 0%, #f8faff 100%);
    border: 1px solid rgba(91, 113, 246, 0.12);
    border-radius: var(--radius-2xl);
    padding: 28px 32px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: var(--shadow-elevation-2);
}

.info-icon-ultra {
    width: 56px;
    height: 56px;
    border-radius: var(--radius-lg);
    background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.5rem;
    flex-shrink: 0;
    box-shadow: var(--glow-primary);
}

.info-content-ultra {
    flex: 1;
}

.info-title-ultra {
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.info-text-ultra {
    font-size: 0.95rem;
    color: var(--text-secondary);
    line-height: 1.6;
    margin: 0;
}

/* ============================================
   RESPONSIVE
   ============================================ */
@media (max-width: 1024px) {
    .title-ultra {
        font-size: 2.5rem;
    }
    
    .stats-grid-ultra {
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 20px;
    }
}

@media (max-width: 768px) {
    .orders-ultra-container {
        padding: 32px 20px 48px;
    }
    
    .header-ultra {
        flex-direction: column;
        align-items: flex-start;
        margin-bottom: 32px;
    }
    
    .title-ultra {
        font-size: 2rem;
    }
    
    .header-actions-ultra {
        width: 100%;
        flex-direction: column;
    }
    
    .btn-ultra {
        width: 100%;
        justify-content: center;
    }
    
    .stats-grid-ultra {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .stat-value-ultra {
        font-size: 2.5rem;
    }
    
    .info-banner-ultra {
        flex-direction: column;
        text-align: center;
    }
}

/* ============================================
   ANIMATIONS
   ============================================ */
@keyframes float {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

@keyframes fadeInScale {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.stat-card-ultra {
    animation: fadeInScale 0.5s cubic-bezier(0.4, 0, 0.2, 1) backwards;
}

.stat-card-ultra:nth-child(1) { animation-delay: 0.1s; }
.stat-card-ultra:nth-child(2) { animation-delay: 0.2s; }
.stat-card-ultra:nth-child(3) { animation-delay: 0.3s; }
.stat-card-ultra:nth-child(4) { animation-delay: 0.4s; }
.stat-card-ultra:nth-child(5) { animation-delay: 0.5s; }

.stat-icon-ultra {
    animation: float 3s ease-in-out infinite;
}

.stat-card-ultra:nth-child(1) .stat-icon-ultra { animation-delay: 0s; }
.stat-card-ultra:nth-child(2) .stat-icon-ultra { animation-delay: 0.5s; }
.stat-card-ultra:nth-child(3) .stat-icon-ultra { animation-delay: 1s; }
.stat-card-ultra:nth-child(4) .stat-icon-ultra { animation-delay: 1.5s; }
.stat-card-ultra:nth-child(5) .stat-icon-ultra { animation-delay: 2s; }
</style>

<div class="orders-ultra-container">
    <!-- ULTRA-PREMIUM HEADER -->
    <div class="header-ultra">
        <div class="header-left-ultra">
            <div class="header-badge">
                <i class="fas fa-chart-line"></i>
                Order Management System
            </div>
            <h1 class="title-ultra">Orders Dashboard</h1>
            <p class="subtitle-ultra">Monitor and manage all your orders in real-time</p>
        </div>
        <div class="header-actions-ultra">
            <a href="generate_pdf.php?status=all" target="_blank" class="btn-ultra primary">
                <i class="fas fa-file-pdf"></i>
                Generate Report
            </a>
            <a href="dashboard.php" class="btn-ultra secondary">
                <i class="fas fa-arrow-left"></i>
                Main Dashboard
            </a>
        </div>
    </div>

    <!-- FLOATING STATS CARDS -->
    <div class="stats-grid-ultra">
        <!-- Regular Orders -->
        <a href="order_all.php" class="stat-card-ultra variant-blue">
            <div class="stat-card-header-ultra">
                <div class="stat-icon-ultra">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div class="stat-info-ultra">
                    <div class="stat-label-ultra">Regular Orders</div>
                    <div class="stat-value-ultra"><?= h($totalOrders); ?></div>
                </div>
            </div>
            <div class="stat-footer-ultra">
                <span class="stat-badge-ultra">
                    <i class="fas fa-users"></i>
                    Customer Orders
                </span>
                <div class="stat-arrow-ultra">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </div>
        </a>

        <!-- Purchase Orders -->
        <a href="orders_pending.php" class="stat-card-ultra variant-yellow">
            <div class="stat-card-header-ultra">
                <div class="stat-icon-ultra">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-info-ultra">
                    <div class="stat-label-ultra">Purchase Orders</div>
                    <div class="stat-value-ultra"><?= h($pendingOrders); ?></div>
                </div>
            </div>
            <div class="stat-footer-ultra">
                <span class="stat-badge-ultra">
                    <i class="fas fa-building"></i>
                    Business Orders
                </span>
                <div class="stat-arrow-ultra">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </div>
        </a>

        <!-- Cancellation Requests -->
        <a href="orders_cancellation.php" class="stat-card-ultra variant-red">
            <div class="stat-card-header-ultra">
                <div class="stat-icon-ultra">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info-ultra">
                    <div class="stat-label-ultra">Cancellation Requests</div>
                    <div class="stat-value-ultra"><?= h($cancellationRequests); ?></div>
                </div>
            </div>
            <div class="stat-footer-ultra">
                <span class="stat-badge-ultra">
                    <i class="fas fa-clipboard-check"></i>
                    Review Requests
                </span>
                <div class="stat-arrow-ultra">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </div>
        </a>

        <!-- Orders Complete -->
        <a href="orders_completed.php" class="stat-card-ultra variant-green">
            <div class="stat-card-header-ultra">
                <div class="stat-icon-ultra">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info-ultra">
                    <div class="stat-label-ultra">Orders Complete</div>
                    <div class="stat-value-ultra"><?= h($ordersComplete); ?></div>
                </div>
            </div>
            <div class="stat-footer-ultra">
                <span class="stat-badge-ultra">
                    <i class="fas fa-history"></i>
                    View History
                </span>
                <div class="stat-arrow-ultra">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </div>
        </a>

        <!-- All Orders Archive -->
        <a href="orders_archive_all.php" class="stat-card-ultra variant-cyan">
            <div class="stat-card-header-ultra">
                <div class="stat-icon-ultra">
                    <i class="fas fa-archive"></i>
                </div>
                <div class="stat-info-ultra">
                    <div class="stat-label-ultra">All Orders Archive</div>
                    <div class="stat-value-ultra"><?= h($archivedOrders); ?></div>
                </div>
            </div>
            <div class="stat-footer-ultra">
                <span class="stat-badge-ultra">
                    <i class="fas fa-database"></i>
                    All Statuses
                </span>
                <div class="stat-arrow-ultra">
                    <i class="fas fa-arrow-right"></i>
                </div>
            </div>
        </a>
    </div>

    <!-- PREMIUM INFO BANNER -->
    <div class="info-banner-ultra">
        <div class="info-icon-ultra">
            <i class="fas fa-lightbulb"></i>
        </div>
        <div class="info-content-ultra">
            <div class="info-title-ultra">Quick Navigation</div>
            <p class="info-text-ultra">
                Click on any card above to access detailed order management. Each section provides advanced filtering, bulk actions, and comprehensive reporting capabilities.
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
