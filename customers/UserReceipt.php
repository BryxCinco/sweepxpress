<?php
// E:\xampp\htdocs\sweepxpress\customers\UserReceipt.php

// 🚨 Start the session (Required for user authentication data).
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. CONFIGURATION, AUTHENTICATION, AND UTILITIES
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

// Define helper to get current user id, if missing.
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return $_SESSION['user']['id'] ?? null;
    }
}

// Safe HTML escape helper
if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// --- USER AUTHORIZATION CHECK ---
$user_id = get_current_user_id();

// Ensure the order_id is provided and numeric
if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    header("Location: /sweepxpress/customers/my_orders.php");
    exit();
}

$order_id = (int)$_GET['order_id'];

// 2. FETCH ORDER DATA (with ownership check)
try {
    $stmtOrder = $pdo->prepare("
        SELECT 
            o.*, 
            u.email,
            d.delivery_date,
            d.status AS delivery_status
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN deliveries d ON o.id = d.order_id
        WHERE o.id = ?
        " . ($user_id ? "AND (o.user_id = ? OR o.user_id IS NULL)" : "AND o.user_id IS NULL") . "
        LIMIT 1
    ");

    if ($user_id) {
        $stmtOrder->execute([$order_id, $user_id]);
    } else {
        $stmtOrder->execute([$order_id]);
    }

    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    // Enforce ownership
    if (!$order || ($order['user_id'] && $order['user_id'] != $user_id)) {
        header("Location: /sweepxpress/customers/my_orders.php");
        exit();
    }

    // Fetch order items
    $stmtItems = $pdo->prepare("
        SELECT 
            oi.quantity, 
            oi.price, 
            (oi.quantity * oi.price) AS subtotal,
            p.name AS product_name 
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("UserReceipt DB Error: " . $e->getMessage());
    die("A database error occurred while fetching your order details.");
}

// Customer display name
$customer_name = h(
    $order['customer_name'] 
        ?: ($order['user_id'] ? "Registered User #{$order['user_id']}" : 'Guest Customer')
);

// Paid stamp logic
$is_paid_status = in_array(strtolower($order['status']), ['completed', 'delivered']);
$show_stamp = $is_paid_status;

// Print script
$print_script = "window.print();";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Official Sales Receipt - Order #<?= h($order['id']) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">

    <style>
/* =====================================================
   USER RECEIPT - GRAYSCALE LIGHT THEME
   Responsive 320px–2560px | Print-optimized | Eye-friendly
   ===================================================== */

:root {
    /* Soft grayscale palette (no pure black) */
    --white:    #ffffff;
    --gray-25:  #fcfcfc;
    --gray-50:  #f7f7f7;
    --gray-100: #f0f0f0;
    --gray-200: #e0e0e0;
    --gray-300: #d0d0d0;
    --gray-400: #a0a0a0;
    --gray-500: #808080;
    --gray-600: #606060;
    --gray-700: #404040;
    --gray-800: #303030;
    --gray-900: #222222;

    /* “Brand” and success tones in gray only */
    --brand-primary:      #3a3a3a;
    --brand-primary-dark: #2b2b2b;

    --success-500: #4a4a4a;
    --success-600: #3a3a3a;

    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-5: 1.25rem;
    --space-6: 1.5rem;
    --space-8: 2rem;
    --space-10: 2.5rem;
    --space-12: 3rem;

    --text-xs: 0.75rem;
    --text-sm: 0.875rem;
    --text-base: 1rem;
    --text-lg: 1.125rem;
    --text-xl: 1.25rem;
    --text-2xl: 1.5rem;
    --text-3xl: 1.875rem;

    --radius-md: 8px;
    --radius-lg: 12px;
    --radius-xl: 16px;

    --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 10px rgba(0,0,0,0.06);

    --container-max: 900px;
}

/* Base */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html {
    font-size: 16px;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: var(--text-base);
    line-height: 1.7;
    color: var(--gray-800);
    background: var(--gray-25);
    padding: var(--space-6) var(--space-3);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Layout */
.ur-page {
    width: 100%;
    display: flex;
    justify-content: center;
}

.ur-container {
    width: 100%;
    max-width: var(--container-max);
}

/* Action bar */
.ur-actions {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
}

.ur-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    height: 44px;
    padding: 0 var(--space-4);
    border-radius: 9999px;
    border: none;
    cursor: pointer;
    font-size: var(--text-sm);
    font-weight: 600;
    font-family: inherit;
    text-decoration: none;
    transition: all 0.18s ease-out;
}

.ur-btn-primary {
    background: var(--brand-primary);
    color: var(--white);
    box-shadow: 0 3px 9px rgba(0,0,0,0.18);
}

.ur-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(0,0,0,0.22);
}

.ur-btn-secondary {
    background: var(--white);
    color: var(--gray-800);
    border: 1px solid var(--gray-300);
}

.ur-btn-secondary:hover {
    background: var(--gray-50);
}

/* Receipt Document */
.ur-document {
    position: relative;
    background: var(--white);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

/* PAID Stamp */
.ur-paid-stamp {
    position: absolute;
    top: 1.75rem;
    right: 1.75rem;
    color: var(--success-600);
    font-size: 2.4rem;
    font-weight: 800;
    border: 3px solid var(--success-600);
    padding: 0.35em 0.8em;
    border-radius: 0.75rem;
    transform: rotate(-15deg);
    opacity: 0.85;
    pointer-events: none;
    z-index: 10;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

/* Header (light gray band, ink-friendly) */
.ur-header {
    padding: var(--space-6) var(--space-8) var(--space-5);
    background: var(--gray-50);
    color: var(--gray-900);
    text-align: center;
    position: relative;
    border-bottom: 1px solid var(--gray-200);
}

.ur-header::before {
    content: '';
    position: absolute;
    inset: 18%;
    border-radius: 999px;
    border: 1px dashed var(--gray-200);
    opacity: 0.5;
}

.ur-header-inner {
    position: relative;
    z-index: 1;
}

.ur-doc-label {
    font-size: var(--text-xs);
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--gray-500);
    margin-bottom: var(--space-2);
    font-weight: 600;
}

.ur-doc-title {
    font-size: var(--text-3xl);
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: var(--space-2);
}

.ur-doc-subtitle {
    font-size: var(--text-sm);
    color: var(--gray-600);
    margin-bottom: var(--space-3);
}

.ur-order-id {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-4);
    border-radius: 9999px;
    background: var(--white);
    font-family: 'JetBrains Mono', monospace;
    font-size: var(--text-sm);
    border: 1px solid var(--gray-200);
}

/* Body */
.ur-body {
    padding: var(--space-6) var(--space-8) var(--space-8);
}

/* Info Grid */
.ur-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: var(--space-4);
    padding-bottom: var(--space-6);
    border-bottom: 1px dashed var(--gray-200);
    margin-bottom: var(--space-6);
}

.ur-info-card {
    background: var(--gray-50);
    border-radius: var(--radius-lg);
    padding: var(--space-4);
    border: 1px solid var(--gray-200);
}

.ur-info-label {
    font-size: var(--text-xs);
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--gray-500);
    margin-bottom: var(--space-1);
}

.ur-info-value {
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--gray-900);
}

/* Items Table */
.ur-section-title {
    font-size: var(--text-lg);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--space-3);
}

.ur-table-wrap {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.ur-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--text-sm);
}

.ur-table thead {
    background: var(--gray-50);
}

.ur-table th,
.ur-table td {
    padding: var(--space-3) var(--space-4);
    border-bottom: 1px solid var(--gray-100);
}

.ur-table th {
    font-size: var(--text-xs);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--gray-500);
    font-weight: 700;
}

.ur-table tbody tr:last-child td {
    border-bottom: none;
}

.ur-text-right {
    text-align: right;
}

.ur-product-name {
    font-weight: 600;
    color: var(--gray-900);
}

/* Summary */
.ur-summary {
    margin-top: var(--space-5);
    display: flex;
    justify-content: flex-end;
}

.ur-summary-table {
    width: 280px;
    font-size: var(--text-sm);
}

.ur-summary-table td {
    padding: 4px 0;
}

.ur-summary-label {
    color: var(--gray-600);
}

.ur-summary-value {
    text-align: right;
    color: var(--gray-800);
}

.ur-summary-total-row td {
    padding-top: var(--space-3);
    border-top: 2px solid var(--gray-300);
    font-size: var(--text-lg);
    font-weight: 700;
    color: var(--brand-primary-dark);
}

/* Footer */
.ur-footer {
    margin-top: var(--space-6);
    font-size: var(--text-xs);
    text-align: center;
    color: var(--gray-500);
}

/* Responsive */
@media (max-width: 768px) {
    body {
        padding: var(--space-4) var(--space-3);
    }

    .ur-header {
        padding: var(--space-5) var(--space-5);
    }

    .ur-body {
        padding: var(--space-5) var(--space-4) var(--space-6);
    }

    .ur-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .ur-btn {
        width: 100%;
        justify-content: center;
    }

    .ur-paid-stamp {
        font-size: 2rem;
        top: 1.25rem;
        right: 1.25rem;
    }
}

@media (max-width: 480px) {
    .ur-header {
        padding: var(--space-5) var(--space-4);
    }

    .ur-doc-title {
        font-size: var(--text-2xl);
    }

    .ur-order-id {
        font-size: var(--text-xs);
    }

    .ur-table-wrap {
        margin: 0 calc(var(--space-4) * -1);
        border-radius: 0;
        border-left: none;
        border-right: none;
    }

    .ur-body {
        padding: var(--space-4);
    }
}

/* Print */
@media print {
    body {
        background: #ffffff !important;
        padding: 0 !important;
        color: #222222 !important;
    }

    .ur-actions {
        display: none !important;
    }

    .ur-page {
        justify-content: flex-start;
    }

    .ur-container {
        max-width: 100% !important;
    }

    .ur-document {
        box-shadow: none !important;
        border-radius: 0 !important;
        border: 1px solid #cccccc;
    }

    .ur-header {
        background: #ffffff !important;
        color: #222222 !important;
        border-bottom: 1px solid #cccccc !important;
    }

    .ur-header::before {
        display: none !important;
    }

    .ur-paid-stamp {
        border-color: #3a3a3a !important;
        color: #3a3a3a !important;
    }
}

/* Accessibility */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}

.ur-btn:focus-visible {
    outline: 3px solid var(--gray-600);
    outline-offset: 2px;
}
    </style>
</head>
<body>

<div class="ur-page">
    <div class="ur-container">

        <!-- User controls (hidden on print) -->
        <div class="ur-actions">
            <button type="button" class="ur-btn ur-btn-secondary" onclick="window.history.back()">
                <span>&laquo; Back to Order Details</span>
            </button>
            <button type="button" class="ur-btn ur-btn-primary" onclick="<?= $print_script ?>">
                <span>Print Receipt</span>
            </button>
        </div>

        <!-- Receipt Document -->
        <div class="ur-document">
            <?php if ($show_stamp): ?>
                <div class="ur-paid-stamp">Paid</div>
            <?php endif; ?>

            <!-- Header -->
            <header class="ur-header">
                <div class="ur-header-inner">
                    <div class="ur-doc-label">Official Sales Receipt</div>
                    <h1 class="ur-doc-title">SweepXpress</h1>
                    <p class="ur-doc-subtitle">
                        Order confirmation and payment record for your purchase.
                    </p>
                    <div class="ur-order-id">
                        <span>Order #<?= h($order['id']) ?></span>
                    </div>
                </div>
            </header>

            <!-- Body -->
            <main class="ur-body">
                <!-- Info Grid -->
                <section class="ur-info-grid">
                    <div class="ur-info-card">
                        <div class="ur-info-label">Customer Name</div>
                        <div class="ur-info-value"><?= $customer_name ?></div>
                    </div>
                    <div class="ur-info-card">
                        <div class="ur-info-label">Contact Email</div>
                        <div class="ur-info-value"><?= h($order['email'] ?? 'N/A') ?></div>
                    </div>
                    <div class="ur-info-card">
                        <div class="ur-info-label">Delivery Address</div>
                        <div class="ur-info-value"><?= h($order['address'] ?? 'N/A') ?></div>
                    </div>
                    <div class="ur-info-card">
                        <div class="ur-info-label">Order Date</div>
                        <div class="ur-info-value">
                            <?= date("M d, Y", strtotime($order['created_at'])) ?>
                        </div>
                    </div>
                    <div class="ur-info-card">
                        <div class="ur-info-label">Delivery Date</div>
                        <div class="ur-info-value">
                            <?= $order['delivery_date']
                                ? date("M d, Y", strtotime($order['delivery_date']))
                                : 'Not scheduled' ?>
                        </div>
                    </div>
                    <div class="ur-info-card">
                        <div class="ur-info-label">Payment Method</div>
                        <div class="ur-info-value">
                            <?= h($order['payment_method'] ?? 'N/A') ?>
                        </div>
                    </div>
                </section>

                <!-- Items -->
                <section>
                    <h2 class="ur-section-title">Items</h2>
                    <div class="ur-table-wrap">
                        <table class="ur-table">
                            <thead>
                                <tr>
                                    <th>Item / Service</th>
                                    <th class="ur-text-right" style="width:110px;">Price</th>
                                    <th class="ur-text-right" style="width:80px;">Qty</th>
                                    <th class="ur-text-right" style="width:120px;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><span class="ur-product-name"><?= h($item['product_name'] ?? 'N/A') ?></span></td>
                                        <td class="ur-text-right"><?= number_format($item['price'], 2) ?></td>
                                        <td class="ur-text-right"><?= h($item['quantity']) ?></td>
                                        <td class="ur-text-right"><?= number_format($item['subtotal'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Summary -->
                <section class="ur-summary">
                    <table class="ur-summary-table">
                        <tr>
                            <td class="ur-summary-label">Subtotal:</td>
                            <td class="ur-summary-value">
                                <?= number_format($order['total'] - ($order['shipping_fee'] ?? 0), 2) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="ur-summary-label">Shipping Fee:</td>
                            <td class="ur-summary-value">
                                <?= number_format($order['shipping_fee'] ?? 0, 2) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="ur-summary-label">Payment Method:</td>
                            <td class="ur-summary-value">
                                <?= h($order['payment_method'] ?? 'N/A') ?>
                            </td>
                        </tr>
                        <tr class="ur-summary-total-row">
                            <td class="ur-summary-label">Grand Total:</td>
                            <td class="ur-summary-value">
                                ₱<?= number_format($order['total'], 2) ?>
                            </td>
                        </tr>
                    </table>
                </section>

                <!-- Footer -->
                <footer class="ur-footer">
                    Thank you for your purchase with SweepXpress. Please keep this receipt for your records.
                </footer>
            </main>
        </div>
    </div>
</div>

</body>
</html>
