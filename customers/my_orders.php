<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];

// Fetch the user's orders, joining with the deliveries table
try {
    $stmt = $pdo->prepare("
        SELECT 
            o.*, 
            d.delivery_date, 
            d.status AS delivery_status 
        FROM orders o
        LEFT JOIN deliveries d ON o.id = d.order_id
        WHERE o.user_id = ? 
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<style>
/* ============================================
   MY ORDERS - CARD GRID VERSION (HUMAN DESIGN)
   Clean, Professional, Real E-Commerce Feel
   Fully Responsive: 320px → 2560px+
   ============================================ */

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
    /* Colors */
    --primary: #2563eb;
    --primary-dark: #1e40af;
    --primary-light: #dbeafe;
    
    --success: #10b981;
    --success-light: #d1fae5;
    --warning: #f59e0b;
    --warning-light: #fef3c7;
    --error: #ef4444;
    --error-light: #fee2e2;
    
    /* Neutrals */
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    
    /* Spacing */
    --s-1: 8px;
    --s-2: 12px;
    --s-3: 16px;
    --s-4: 20px;
    --s-5: 24px;
    --s-6: 32px;
    
    /* Typography */
    --text-xs: 12px;
    --text-sm: 14px;
    --text-base: 16px;
    --text-lg: 18px;
    --text-xl: 20px;
    --text-2xl: 24px;
    
    /* Radius */
    --radius: 6px;
    --radius-lg: 8px;
    --radius-xl: 12px;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
}

/* ============== FULL SCREEN FIX ============== */
html {
    height: 100%;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    background: var(--gray-50);
    color: var(--gray-900);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.orders-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: var(--s-5) var(--s-3);
    width: 100%;
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* Header */
.orders-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--s-5);
    gap: var(--s-3);
}

.orders-title {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: var(--s-2);
}

.orders-icon {
    color: var(--primary);
}

.btn-shop {
    display: inline-flex;
    align-items: center;
    gap: var(--s-1);
    padding: 10px var(--s-4);
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
}

.btn-shop:hover {
    background: var(--primary-dark);
    color: white;
}

/* Stats */
.stats-card {
    display: flex;
    align-items: center;
    gap: var(--s-3);
    padding: var(--s-4);
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    margin-bottom: var(--s-5);
}

.stats-icon {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--primary-light);
    border-radius: var(--radius-lg);
    color: var(--primary);
    font-size: var(--text-xl);
}

.stats-content h2 {
    font-size: var(--text-xs);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-500);
    margin: 0 0 4px 0;
}

.stats-value {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
}

/* Orders Grid */
.orders-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--s-4);
}

/* Order Card */
.order-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    transition: all 0.15s;
    position: relative;
}

.order-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.order-card.has-notification {
    border-left: 3px solid var(--warning);
    background: #fffbf5;
}

.order-card.has-notification::before {
    content: 'New Update';
    position: absolute;
    top: var(--s-2);
    right: var(--s-2);
    background: var(--warning);
    color: white;
    padding: 2px var(--s-2);
    border-radius: 999px;
    font-size: var(--text-xs);
    font-weight: 600;
    z-index: 2;
}

/* Card Header */
.order-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--s-3);
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.order-id {
    font-size: var(--text-base);
    font-weight: 700;
    color: var(--gray-900);
}

.order-id span {
    color: var(--primary);
}

.order-date {
    font-size: var(--text-sm);
    color: var(--gray-500);
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Card Body */
.order-card-body {
    padding: var(--s-4);
}

.order-info {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--s-3);
    margin-bottom: var(--s-4);
}

.order-info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.order-info-item.full {
    grid-column: 1 / -1;
}

.order-label {
    font-size: var(--text-xs);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-500);
}

.order-value {
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--gray-900);
}

.order-total {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--success);
}

.order-value.muted {
    color: var(--gray-400);
}

/* Status Badge */
.order-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px var(--s-2);
    border-radius: 999px;
    font-size: var(--text-sm);
    font-weight: 600;
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.order-status.completed,
.order-status.delivered {
    background: var(--success-light);
    color: #065f46;
}

.order-status.preparing {
    background: var(--primary-light);
    color: #1e40af;
}

.order-status.pending {
    background: var(--warning-light);
    color: #92400e;
}

.order-status.cancelled {
    background: var(--error-light);
    color: #991b1b;
}

/* Card Footer */
.order-card-footer {
    padding: var(--s-3);
    background: var(--gray-50);
    border-top: 1px solid var(--gray-100);
    display: flex;
    gap: var(--s-2);
}

.btn-details,
.btn-invoice {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px var(--s-2);
    border-radius: var(--radius-lg);
    font-size: var(--text-sm);
    font-weight: 600;
    text-decoration: none;
    transition: all 0.15s;
}

.btn-details {
    background: var(--primary);
    color: white;
}

.btn-details:hover {
    background: var(--primary-dark);
    color: white;
}

.btn-invoice {
    background: white;
    color: var(--success);
    border: 1px solid var(--success);
}

.btn-invoice:hover {
    background: var(--success-light);
    color: #065f46;
}

/* ============== EMPTY STATE - FULL SCREEN FIX ============== */
.empty-state {
    text-align: center;
    padding: var(--s-6) var(--s-3);
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 400px;
    flex: 1;
}

.empty-icon {
    font-size: 64px;
    margin-bottom: var(--s-4);
    opacity: 0.5;
}

.empty-title {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--s-2);
}

.empty-text {
    font-size: var(--text-base);
    color: var(--gray-500);
    margin-bottom: var(--s-4);
}

.empty-text a {
    color: var(--primary);
    font-weight: 600;
    text-decoration: none;
}

.empty-text a:hover {
    text-decoration: underline;
}

/* ============== RESPONSIVE ============== */

/* Tablet */
@media (min-width: 768px) {
    .orders-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .empty-state {
        min-height: 500px;
    }
    
    .empty-icon {
        font-size: 80px;
    }
}

/* Desktop */
@media (min-width: 1024px) {
    .orders-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Mobile */
@media (max-width: 640px) {
    .orders-container {
        padding: var(--s-4) var(--s-2);
    }
    
    .orders-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .btn-shop {
        width: 100%;
        justify-content: center;
    }
    
    .orders-title {
        font-size: var(--text-xl);
    }
    
    .order-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--s-1);
    }
    
    .order-info {
        grid-template-columns: 1fr;
    }
    
    .order-card-body {
        padding: var(--s-3);
    }
    
    .order-total {
        font-size: var(--text-lg);
    }
    
    .empty-state {
        min-height: 300px;
    }
    
    .empty-icon {
        font-size: 48px;
    }
}
</style>

<div class="orders-container">
    <div class="orders-header">
        <h1 class="orders-title">
            <i class="bi bi-box-seam orders-icon"></i>
            My Orders
        </h1>
        <a href="/sweepxpress/index.php" class="btn-shop">
            <i class="bi bi-cart-plus"></i>
            Continue Shopping
        </a>
    </div>

    <div class="stats-card">
        <div class="stats-icon">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="stats-content">
            <h2>Total Orders Placed</h2>
            <span class="stats-value"><?= number_format(count($orders)) ?></span>
        </div>
    </div>

    <?php if ($orders): ?>
        <div class="orders-grid">
            <?php foreach ($orders as $order): ?>
                <?php 
                    $has_notification = !empty(trim($order['admin_note'] ?? ''));
                    $status_to_display = $order['status'] ?? 'pending'; 
                    
                    $statusBadgeClass = match($status_to_display) {
                        'pending' => 'pending',
                        'preparing' => 'preparing',
                        'delivered' => 'delivered',
                        'completed' => 'completed',
                        'cancellation_requested', 'cancelled' => 'cancelled',
                        default => 'pending'
                    };
                ?>
                
                <article class="order-card <?= $has_notification ? 'has-notification' : '' ?>">
                    <!-- Card Header -->
                    <div class="order-card-header">
                        <div class="order-id">
                            Order <span>#<?= h($order['id']) ?></span>
                        </div>
                        <div class="order-date">
                            <i class="bi bi-calendar3"></i>
                            <?= date('M j, Y', strtotime($order['created_at'])) ?>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="order-card-body">
                        <div class="order-info">
                            <div class="order-info-item">
                                <span class="order-label">Total Amount</span>
                                <span class="order-value order-total">
                                    ₱<?= number_format($order['total'], 2) ?>
                                </span>
                            </div>

                            <div class="order-info-item">
                                <span class="order-label">Status</span>
                                <span class="order-status <?= $statusBadgeClass ?>">
                                    <span class="status-dot"></span>
                                    <?= h($status_to_display === 'shipped' ? 'Preparing' : ucfirst(str_replace('_', ' ', $status_to_display))) ?>
                                </span>
                            </div>

                            <div class="order-info-item full">
                                <span class="order-label">Delivery Date</span>
                                <span class="order-value <?= empty($order['delivery_date']) ? 'muted' : '' ?>">
                                    <?php if (!empty($order['delivery_date'])): ?>
                                        <i class="bi bi-calendar-check" style="color: var(--success); margin-right: 4px;"></i>
                                        <?= date('F j, Y', strtotime($order['delivery_date'])) ?>
                                    <?php else: ?>
                                        <i class="bi bi-calendar-x" style="margin-right: 4px;"></i>
                                        Not yet scheduled
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="order-card-footer">
                        <a href="/sweepxpress/customers/order_details.php?id=<?= h($order['id']) ?>" class="btn-details">
                            <i class="bi bi-eye"></i>
                            Details
                        </a>
                        <a href="/sweepxpress/Invoice.php?order_id=<?= h($order['id']) ?>" class="btn-invoice">
                            <i class="bi bi-receipt"></i>
                            Invoice
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">📦</div>
            <h3 class="empty-title">No Orders Yet</h3>
            <p class="empty-text">You haven't placed any orders yet. <a href="/sweepxpress/index.php">Start shopping now!</a></p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
