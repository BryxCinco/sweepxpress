<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';

// ====================================================================
// 1. Logic (NONE REQUIRED - NO UPDATES ALLOWED FOR COMPLETED ORDERS)
// ====================================================================

// No POST handling is included here as completed orders should not be editable.

// ====================================================================
// 2. FETCH DATA (Completed Orders Only)
// ====================================================================

$sql = "
    SELECT 
        o.id AS order_id,
        u.name AS customer_name,
        o.total,
        o.created_at,
        o.status AS order_status, 
        d.id AS delivery_id,
        d.status AS delivery_status,
        d.delivery_date
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN deliveries d ON o.id = d.order_id
    WHERE o.status IN ('delivered', 'completed')
    ORDER BY o.created_at DESC
";
try {
    $orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching completed orders: " . $e->getMessage());
    $orders = [];
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

* {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

body {
    background: #f8f9fb;
}

.completed-orders-container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.completed-orders-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-completed {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-completed i {
    color: #06b6d4;
}

.btn-back-completed {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.2s;
}

.btn-back-completed:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* STATS CARD */
.stats-card-completed {
    background: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
    border-radius: 16px;
    padding: 20px 28px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 10px 24px rgba(6, 182, 212, 0.3);
    margin-bottom: 24px;
}

.stats-icon-completed {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.5rem;
}

.stats-content-completed h2 {
    font-size: 0.85rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px 0;
}

.stats-content-completed .stats-value-completed {
    font-size: 2rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1;
}

/* TABLE CARD (Desktop Container) */
.table-card-completed {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow: hidden;
}

.table-completed {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-completed thead {
    background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
}

.table-completed thead th {
    padding: 18px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #164e63;
    border-bottom: 2px solid #06b6d4;
    white-space: nowrap;
}

.table-completed thead th.text-center {
    text-align: center;
}

.table-completed tbody td {
    padding: 18px 20px;
    font-size: 0.95rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.table-completed tbody tr:hover {
    background: #ecfeff;
}

.table-completed tbody tr:last-child td {
    border-bottom: none;
}

/* ORDER ID BADGE */
.order-id-badge-completed {
    display: inline-flex;
    padding: 6px 12px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-weight: 700;
    color: #0f172a;
}

/* CUSTOMER NAME */
.customer-name-completed {
    font-weight: 600;
    color: #0f172a;
}

/* PRICE */
.price-display-completed {
    font-weight: 700;
    color: #10b981;
}

/* STATUS BADGE */
.status-badge-completed {
    display: inline-flex;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
    background: #cffafe;
    color: #164e63;
}

/* DELIVERY DATE */
.delivery-date-completed {
    font-weight: 600;
    color: #0891b2;
}

/* VIEW DETAILS BUTTON */
.btn-view-details-completed {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #ffffff;
    color: #06b6d4;
    border: 1.5px solid #06b6d4;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-view-details-completed:hover {
    background: #06b6d4;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
}

/* EMPTY STATE */
.empty-state-completed {
    text-align: center;
    padding: 80px 32px;
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
}

.empty-state-completed i {
    font-size: 4rem;
    color: #94a3b8;
    margin-bottom: 20px;
}

.empty-state-completed h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
}

.empty-state-completed p {
    font-size: 0.95rem;
    color: #94a3b8;
}

/* MOBILE CARD STYLE (separate from table) */
.mobile-cards-container {
    display: none; /* hidden on desktop */
}

.order-card-mobile {
    background-color: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
    margin-bottom: 20px;
    overflow: hidden;
}

.order-card-mobile .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background-color: #f7feff;
    border-bottom: 1px solid #e5e7eb;
    gap: 8px;
    flex-wrap: wrap;
}

.order-card-mobile .card-header .card-label {
    font-size: 0.8rem;
    font-weight: 700;
    color: #06b6d4;
    text-transform: uppercase;
}

.order-card-mobile .card-body {
    padding: 0;
}

.order-card-mobile .data-item {
    display: flex;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid #f3f4f6;
    gap: 12px;
}

.order-card-mobile .data-item:last-of-type {
    border-bottom: none;
}

.order-card-mobile .item-label {
    font-size: 0.9rem;
    font-weight: 600;
    color: #64748b;
}

.order-card-mobile .item-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: #0f172a;
    text-align: right;
}

.order-card-mobile .card-footer {
    padding: 16px;
    text-align: center;
    border-top: 1px solid #e5e7eb;
}

.order-card-mobile .card-footer .btn-view-details-completed {
    width: 100%;
    justify-content: center;
}

/* ==================================================================== */
/* MOBILE RESPONSIVENESS */
/* ==================================================================== */
@media (max-width: 768px) {
    .completed-orders-container {
        padding: 24px 16px 40px;
    }

    .completed-orders-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }

    .btn-back-completed {
        width: 100%;
        justify-content: center;
    }

    .stats-card-completed {
        width: 100%;
        margin-bottom: 16px;
    }

    /* hide desktop table on mobile */
    .table-card-completed {
        display: none;
    }

    /* show mobile cards */
    .mobile-cards-container {
        display: block;
    }
}
</style>

<div class="completed-orders-container">
    <div class="completed-orders-header">
        <h1 class="page-title-completed">
            <i class="fas fa-check-circle"></i>
            Completed Orders
        </h1>
        <a href="allorders.php" class="btn-back-completed">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

    <div class="stats-card-completed">
        <div class="stats-icon-completed">
            <i class="fas fa-clipboard-check"></i>
        </div>
        <div class="stats-content-completed">
            <h2>Successfully Delivered</h2>
            <span class="stats-value-completed"><?= number_format(count($orders)) ?></span>
        </div>
    </div>

    <?php if ($orders): ?>
        <!-- DESKTOP TABLE -->
        <div class="table-card-completed">
            <div class="table-responsive">
                <table class="table-completed">
                    <thead>
                        <tr>
                            <th class="text-center">Order ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Order Date</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Delivery Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $currentStatus = $o['order_status'];
                            $deliveryDateDisplay = $o['delivery_date'] ? date('M j, Y', strtotime($o['delivery_date'])) : 'N/A';
                        ?>
                            <tr>
                                <td class="text-center">
                                    <span class="order-id-badge-completed">#<?= (int)$o['order_id'] ?></span>
                                </td>
                                <td class="customer-name-completed">
                                    <?= h($o['customer_name'] ?? 'Guest') ?>
                                </td>
                                <td>
                                    <span class="price-display-completed">₱<?= number_format($o['total'], 2) ?></span>
                                </td>
                                <td style="color: #64748b;">
                                    <?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge-completed">
                                        <?= ucfirst(str_replace('_', ' ', $currentStatus)) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="delivery-date-completed"><?= h($deliveryDateDisplay) ?></span>
                                </td>
                                <td class="text-center">
                                    <a class="btn-view-details-completed" href="order_details_complete.php?id=<?= (int)$o['order_id'] ?>">
                                        <i class="fas fa-eye"></i>
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MOBILE CARDS -->
        <div class="mobile-cards-container">
            <?php foreach ($orders as $o):
                $currentStatus = $o['order_status'];
                $deliveryDateDisplay = $o['delivery_date'] ? date('M j, Y', strtotime($o['delivery_date'])) : 'N/A';
            ?>
                <div class="order-card-mobile">
                    <div class="card-header">
                        <span class="card-label">Order</span>
                        <span class="order-id-badge-completed">#<?= (int)$o['order_id'] ?></span>
                        <span class="status-badge-completed">
                            <?= ucfirst(str_replace('_', ' ', $currentStatus)) ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="data-item">
                            <span class="item-label">Customer</span>
                            <span class="item-value customer-name-completed">
                                <?= h($o['customer_name'] ?? 'Guest') ?>
                            </span>
                        </div>
                        <div class="data-item">
                            <span class="item-label">Total</span>
                            <span class="item-value price-display-completed">
                                ₱<?= number_format($o['total'], 2) ?>
                            </span>
                        </div>
                        <div class="data-item">
                            <span class="item-label">Order Date</span>
                            <span class="item-value" style="color:#64748b;">
                                <?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?>
                            </span>
                        </div>
                        <div class="data-item">
                            <span class="item-label">Delivery Date</span>
                            <span class="item-value delivery-date-completed">
                                <?= h($deliveryDateDisplay) ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <a class="btn-view-details-completed" href="order_details_complete.php?id=<?= (int)$o['order_id'] ?>">
                            <i class="fas fa-eye"></i>
                            View Details
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else: ?>
        <div class="empty-state-completed">
            <i class="fas fa-inbox"></i>
            <h3>No Completed Orders Yet</h3>
            <p>Completed orders will appear here once delivered</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
