<?php
// E:\xampp\htdocs\sweepxpress\customers\order_details.php

// ====================================================================
// 1. CONFIGURATION, AUTHENTICATION, AT VARIABLE SETUP
// ====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

// Check user login status and redirect if not logged in
if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

// Validate order ID from GET parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: /sweepxpress/customers/my_orders.php");
    exit();
}

$order_id = (int)$_GET['id'];
$user_id  = $_SESSION['user']['id'];

// CLEANUP HANDLER: Clears admin_note after user sees a decision popup
if (isset($_GET['cleanup']) && $_GET['cleanup'] == 1) {
    try {
        $pdo->prepare("
            UPDATE orders 
            SET admin_note = NULL
            WHERE id = ? AND user_id = ? AND status IN ('cancelled', 'pending')
        ")->execute([$order_id, $user_id]);
    } catch (PDOException $e) {
        error_log("DB cleanup error: " . $e->getMessage());
    }
    header("Location: /sweepxpress/customers/order_details.php?id={$order_id}");
    exit();
}

// ====================================================================
// 2. DATABASE FETCHING
// ====================================================================
try {
    // Fetch order details
    $stmt = $pdo->prepare("
        SELECT 
            o.*, 
            d.delivery_date, 
            d.status AS delivery_status
        FROM orders o
        LEFT JOIN deliveries d ON o.id = d.order_id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: /sweepxpress/customers/my_orders.php?status=not_found");
        exit();
    }

    // Fetch order items (including product image path)
    // ⚠️ FIXED: Added the calculated subtotal to the SELECT statement
    $stmt_items = $pdo->prepare("
        SELECT 
            oi.*, 
            p.name, 
            p.image_path,
            (oi.quantity * oi.price) AS subtotal  /* <--- ITO ANG NAG-AYOS SA 'subtotal' WARNING */
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt_items->execute([$order_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error in order_details.php: " . $e->getMessage());
    die("An unexpected error occurred. Please try again later.");
}

$display_status = $order['status']; 
$admin_note = trim($order['admin_note'] ?? '');

$swal_title = '';
$swal_text = '';
$swal_icon = '';
$is_admin_decision_swal = false;

// ====================================================================
// 3. STATUS MESSAGES (FOR SWEETALERT POP-UPS)
// ====================================================================
if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'request_submitted':
            $swal_title = 'Request Submitted!';
            $swal_text = 'Your cancellation request has been submitted for review.';
            $swal_icon = 'success';
            break;
        case 'reason_error':
            $swal_title = 'Submission Failed';
            $swal_text = 'Please provide a detailed reason (at least 10 characters).';
            $swal_icon = 'warning';
            break;
        case 'request_denied':
            $swal_title = 'Action Not Allowed';
            $swal_text = 'Cannot submit cancellation request. Order is no longer pending.';
            $swal_icon = 'error';
            break;
        case 'error':
            $swal_title = 'System Error';
            $swal_text = 'An unexpected error occurred.';
            $swal_icon = 'error';
            break;
        case 'received_success':
            $swal_title = 'Order Completed! 🎉';
            $swal_text = 'Thank you for confirming that you received your order.';
            $swal_icon = 'success';
            break;
        case 'received_error':
            $swal_title = 'Order Status Updated';
            $swal_text = 'You indicated that you did not receive the order. The status has been set back to Preparing.';
            $swal_icon = 'info';
            break;
        case 'invalid_confirm':
            $swal_title = 'Invalid Action';
            $swal_text = 'This order cannot be confirmed as received.';
            $swal_icon = 'warning';
            break;
    }
}

// ADMIN DECISION POP-UPS (for pending or cancelled orders with notes)
if (!empty($admin_note)) {
    if ($display_status === 'cancelled') {
        $swal_title = 'Cancellation Approved! ✅';
        $swal_text = "Your request to cancel Order #{$order_id} has been APPROVED.\n\nAdmin Note: " . $admin_note;
        $swal_icon = 'success';
        $is_admin_decision_swal = true;
    } elseif ($display_status === 'pending' && $order['cancellation_reason'] === NULL) {
        $swal_title = 'Cancellation Rejected ❌';
        $swal_text = "Your request to cancel Order #{$order_id} has been REJECTED.\n\nAdmin Note: " . $admin_note;
        $swal_icon = 'error';
        $is_admin_decision_swal = true;
    }
}

// ====================================================================
// 4. HTML HEADER INCLUDE
// ====================================================================
require_once __DIR__ . '/../includes/header.php'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Order #<?= h($order['id']) ?> - SweepXpress</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<style>
/* CSS Styles */
:root {
    --brand-primary: #3366ff;
    --brand-primary-hover: #2952cc;
    --brand-primary-light: #e7f0ff;
    --white: #ffffff;
    --gray-25: #fcfcfd;
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
    --success-50: #f0fdf4;
    --success-100: #dcfce7;
    --success-500: #22c55e;
    --success-600: #16a34a;
    --success-700: #15803d;
    --warning-50: #fffbeb;
    --warning-100: #fef3c7;
    --warning-500: #f59e0b;
    --warning-600: #d97706;
    --error-50: #fef2f2;
    --error-100: #fee2e2;
    --error-500: #ef4444;
    --error-600: #dc2626;
    --error-700: #b91c1c;
    --info-50: #eff6ff;
    --info-100: #dbeafe;
    --info-500: #3b82f6;
    --info-600: #2563eb;
    --space-1: 0.25rem;
    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-5: 1.25rem;
    --space-6: 1.5rem;
    --space-8: 2rem;
    --space-10: 2.5rem;
    --space-12: 3rem;
    --space-16: 4rem;
    --text-xs: 0.75rem;
    --text-sm: 0.875rem;
    --text-base: 1rem;
    --text-lg: 1.125rem;
    --text-xl: 1.25rem;
    --text-2xl: 1.5rem;
    --text-3xl: 1.875rem;
    --radius-sm: 6px;
    --radius-md: 8px;
    --radius-lg: 12px;
    --radius-xl: 16px;
    --radius-2xl: 20px;
    --radius-full: 9999px;
    --shadow-xs: 0 1px 2px rgba(0,0,0,0.05);
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
    --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-base: 200ms cubic-bezier(0.4, 0, 0.2, 1);
    --container-max: 960px;
}

*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; font-size: var(--text-base); line-height: 1.6; color: var(--gray-800); background: linear-gradient(180deg, var(--gray-50) 0%, var(--white) 50%); -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }

.od-page { width: 100%; min-height: 100vh; padding: var(--space-8) var(--space-4); }
.od-container { width: 100%; max-width: var(--container-max); margin: 0 auto; }
.od-breadcrumb { display: flex; align-items: center; gap: var(--space-2); font-size: var(--text-sm); color: var(--gray-500); margin-bottom: var(--space-6); }
.od-breadcrumb a { color: var(--brand-primary); text-decoration: none; font-weight: 500; transition: color var(--transition-fast); }
.od-breadcrumb a:hover { color: var(--brand-primary-hover); text-decoration: underline; }
.od-breadcrumb svg { width: 16px; height: 16px; color: var(--gray-400); }
.od-header { background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-xl); padding: var(--space-6); margin-bottom: var(--space-6); box-shadow: var(--shadow-sm); }
.od-header-top { display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-4); margin-bottom: var(--space-5); }
.od-header-title { flex: 1; min-width: 0; }
.od-header-title h1 { font-size: var(--text-2xl); font-weight: 800; color: var(--gray-900); margin-bottom: var(--space-1); letter-spacing: -0.02em; }
.od-header-title .od-order-id { color: var(--brand-primary); }
.od-header-date { font-size: var(--text-sm); color: var(--gray-500); }
.od-status-badge { display: inline-flex; align-items: center; gap: var(--space-2); padding: var(--space-2) var(--space-4); border-radius: var(--radius-full); font-size: var(--text-sm); font-weight: 600; white-space: nowrap; }
.od-status-badge.completed, .od-status-badge.delivered { background: var(--success-100); color: var(--success-700); }
.od-status-badge.preparing { background: var(--info-100); color: var(--info-600); }
.od-status-badge.pending { background: var(--warning-100); color: var(--warning-600); }
.od-status-badge.cancellation_requested, .od-status-badge.cancelled { background: var(--error-100); color: var(--error-700); }
.od-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--space-4); }
.od-info-item { display: flex; flex-direction: column; gap: var(--space-1); }
.od-info-label { font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-500); }
.od-info-value { font-size: var(--text-base); font-weight: 600; color: var(--gray-900); }
.od-info-value.total { font-size: var(--text-xl); color: var(--brand-primary); }
.od-action-card { background: var(--white); border: 1.5px solid; border-radius: var(--radius-lg); padding: var(--space-5); margin-bottom: var(--space-6); box-shadow: var(--shadow-sm); }
.od-action-card.danger { border-color: var(--error-200); background: linear-gradient(135deg, var(--error-50), var(--white)); }
.od-action-card.success { border-color: var(--success-200); background: linear-gradient(135deg, var(--success-50), var(--white)); }
.od-action-card.warning { border-color: var(--warning-200); background: linear-gradient(135deg, var(--warning-50), var(--white)); }
.od-action-card.info { border-color: var(--info-200); background: linear-gradient(135deg, var(--info-50), var(--white)); }
.od-action-header { display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-4); }
.od-action-icon { width: 40px; height: 40px; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: var(--text-lg); flex-shrink: 0; }
.od-action-card.danger .od-action-icon { background: var(--error-500); color: var(--white); }
.od-action-card.success .od-action-icon { background: var(--success-500); color: var(--white); }
.od-action-card.warning .od-action-icon { background: var(--warning-500); color: var(--white); }
.od-action-card.info .od-action-icon { background: var(--info-500); color: var(--white); }
.od-action-title { font-size: var(--text-lg); font-weight: 700; margin: 0; }
.od-action-card.danger .od-action-title { color: var(--error-700); }
.od-action-card.success .od-action-title { color: var(--success-700); }
.od-action-card.warning .od-action-title { color: var(--warning-700); }
.od-action-card.info .od-action-title { color: var(--info-700); }
.od-action-text { font-size: var(--text-sm); color: var(--gray-600); margin-bottom: var(--space-4); line-height: 1.6; }
.od-action-note { background: var(--white); padding: var(--space-3); border-radius: var(--radius-md); font-size: var(--text-sm); font-style: italic; color: var(--gray-700); margin-top: var(--space-3); }
.od-form-group { margin-bottom: var(--space-4); }
.od-label { display: block; font-size: var(--text-sm); font-weight: 600; color: var(--gray-700); margin-bottom: var(--space-2); }
.od-textarea { width: 100%; min-height: 100px; padding: var(--space-3) var(--space-4); font-size: var(--text-base); font-family: inherit; color: var(--gray-900); background: var(--white); border: 1.5px solid var(--gray-300); border-radius: var(--radius-md); outline: none; transition: all var(--transition-fast); resize: vertical; }
.od-textarea:focus { border-color: var(--brand-primary); box-shadow: 0 0 0 3px var(--brand-primary-light); }
.od-textarea::placeholder { color: var(--gray-400); }
.od-btn { display: inline-flex; align-items: center; justify-content: center; gap: var(--space-2); height: 48px; padding: 0 var(--space-5); font-size: var(--text-base); font-weight: 600; font-family: inherit; text-decoration: none; border-radius: var(--radius-md); border: none; cursor: pointer; transition: all var(--transition-fast); outline: none; }
.od-btn-primary { background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-hover)); color: var(--white); box-shadow: 0 4px 12px rgba(51, 102, 255, 0.3); }
.od-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(51, 102, 255, 0.4); }
.od-btn-success { background: linear-gradient(135deg, var(--success-500), var(--success-600)); color: var(--white); box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3); }
.od-btn-success:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(34, 197, 94, 0.4); }
.od-btn-danger { background: linear-gradient(135deg, var(--error-500), var(--error-600)); color: var(--white); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
.od-btn-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4); }
.od-btn-secondary { background: var(--white); color: var(--gray-700); border: 1.5px solid var(--gray-300); }
.od-btn-secondary:hover { background: var(--gray-50); border-color: var(--gray-400); }
.od-btn:active { transform: translateY(0); }
.od-items-card { background: var(--white); border: 1px solid var(--gray-200); border-radius: var(--radius-xl); padding: var(--space-6); margin-bottom: var(--space-6); box-shadow: var(--shadow-sm); }
.od-items-title { font-size: var(--text-xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--space-5); display: flex; align-items: center; gap: var(--space-2); }
.od-table-wrap { border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; }
.od-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.od-table thead { background: var(--gray-50); }
.od-table th { padding: var(--space-4); font-size: var(--text-xs); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-600); text-align: left; border-bottom: 1px solid var(--gray-200); }
.od-table th.text-center { text-align: center; }
.od-table th.text-right { text-align: right; }
.od-table td { padding: var(--space-4); color: var(--gray-700); border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
.od-table tbody tr:last-child td { border-bottom: none; }
.od-table tbody tr:hover { background: var(--gray-25); }
.od-table .text-center { text-align: center; }
.od-table .text-right { text-align: right; }
.od-product-img { width: 64px; height: 64px; border-radius: var(--radius-md); object-fit: cover; border: 1px solid var(--gray-200); }
.od-product-name { font-weight: 600; color: var(--gray-900); }
.od-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: var(--space-12) var(--space-6); text-align: center; }
.od-empty-icon { width: 64px; height: 64px; background: var(--warning-100); border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; color: var(--warning-600); font-size: var(--text-3xl); margin-bottom: var(--space-4); }
.od-empty-text { font-size: var(--text-base); color: var(--gray-600); }
.od-receipt-actions { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-4); }
@media (max-width: 768px) {
    .od-page { padding: var(--space-6) var(--space-4); }
    .od-header { padding: var(--space-5); }
    .od-header-top { flex-direction: column; align-items: flex-start; }
    .od-status-badge { align-self: flex-start; }
    .od-info-grid { grid-template-columns: 1fr; }
    .od-action-card { padding: var(--space-4); }
    .od-items-card { padding: var(--space-5); }
    .od-table th, .od-table td { padding: var(--space-3); font-size: var(--text-xs); }
    .od-product-img { width: 56px; height: 56px; }
    .od-receipt-actions { flex-direction: column-reverse; align-items: stretch; }
    .od-btn { width: 100%; }
}
@media (max-width: 480px) {
    .od-page { padding: var(--space-5) var(--space-3); }
    .od-header { padding: var(--space-4); }
    .od-header-title h1 { font-size: var(--text-xl); }
    .od-action-card { padding: var(--space-4); }
    .od-action-header { gap: var(--space-2); }
    .od-action-icon { width: 36px; height: 36px; font-size: var(--text-base); }
    .od-action-title { font-size: var(--text-base); }
    .od-items-card { padding: var(--space-4); }
    .od-items-title { font-size: var(--text-lg); }
    .od-table-wrap { margin: 0 calc(var(--space-4) * -1); border-radius: 0; border-left: none; border-right: none; }
    .od-table th:first-child, .od-table td:first-child { padding-left: var(--space-4); }
    .od-product-img { width: 48px; height: 48px; }
    .od-btn { height: 46px; font-size: var(--text-sm); }
}
@media (max-width: 360px) {
    .od-breadcrumb { font-size: var(--text-xs); margin-bottom: var(--space-4); }
    .od-header-title h1 { font-size: var(--text-lg); }
    .od-status-badge { font-size: var(--text-xs); padding: var(--space-1) var(--space-3); }
}
@media (min-width: 1440px) {
    :root { --container-max: 1024px; }
    .od-page { padding: var(--space-10) var(--space-6); }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
}
.od-btn:focus-visible, .od-textarea:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: 2px; }
.swal2-pre-wrap { white-space: pre-wrap !important; }

</style>

<div class="od-page">
    <div class="od-container">

        <nav class="od-breadcrumb">
            <a href="/sweepxpress/index.php">Home</a>
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
            <a href="/sweepxpress/customers/my_orders.php">My Orders</a>
            <svg viewBox="0 0 16 16" fill="currentColor"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
            <span>Order #<?= h($order['id']) ?></span>
        </nav>

        <div class="od-header">
            <div class="od-header-top">
                <div class="od-header-title">
                    <h1>Order <span class="od-order-id">#<?= h($order['id']) ?></span></h1>
                    <div class="od-header-date">
                        <i class="bi bi-calendar3"></i>
                        Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?>
                    </div>
                </div>
                <span class="od-status-badge <?= h($display_status) ?>">
                    <i class="bi bi-<?= match($display_status) {
                        'completed', 'delivered' => 'check-circle-fill',
                        'preparing' => 'hourglass-split',
                        'pending' => 'clock-fill',
                        'cancellation_requested' => 'exclamation-triangle-fill',
                        'cancelled' => 'x-circle-fill',
                        default => 'circle-fill'
                    } ?>"></i>
                    <?= h(ucfirst(str_replace('_', ' ', $display_status))) ?>
                </span>
            </div>
            
            <div class="od-info-grid">
                <div class="od-info-item">
                    <div class="od-info-label">Delivery Date</div>
                    <div class="od-info-value">
                        <?= !empty($order['delivery_date']) 
                            ? date('M j, Y', strtotime($order['delivery_date'])) 
                            : '<span style="color:var(--gray-400)">Not scheduled</span>' ?>
                    </div>
                </div>
                <div class="od-info-item">
                    <div class="od-info-label">Order Total</div>
                    <div class="od-info-value total">₱<?= number_format($order['total'], 2) ?></div>
                </div>
                <div class="od-info-item">
                    <div class="od-info-label">Items</div>
                    <div class="od-info-value"><?= count($items) ?> item<?= count($items) > 1 ? 's' : '' ?></div>
                </div>
            </div>
        </div>

        <?php if ($order['status'] === 'pending'): ?>
            <div class="od-action-card danger">
                <div class="od-action-header">
                    <div class="od-action-icon">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                    <h2 class="od-action-title">Request Cancellation</h2>
                </div>
                <p class="od-action-text">
                    If you need to cancel this order, please provide a detailed reason below. Your request will be reviewed by our team.
                </p>
                <form id="cancellationForm" action="/sweepxpress/customers/cancel_order.php" method="POST">
                    <input type="hidden" name="order_id" value="<?= h($order_id) ?>">
                    <div class="od-form-group">
                        <label for="cancellationReason" class="od-label">Cancellation Reason</label>
                        <textarea class="od-textarea" 
                                     id="cancellationReason" 
                                     name="reason" 
                                     placeholder="Please explain why you need to cancel this order (minimum 10 characters)"
                                     required 
                                     minlength="10"></textarea>
                    </div>
                    <button type="submit" class="od-btn od-btn-danger">
                        <i class="bi bi-x-circle"></i>
                        Submit Cancellation Request
                    </button>
                </form>
            </div>
        
        <?php elseif ($order['status'] === 'cancellation_requested'): ?>
            <div class="od-action-card warning">
                <div class="od-action-header">
                    <div class="od-action-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <h2 class="od-action-title">Cancellation Under Review</h2>
                </div>
                <p class="od-action-text">
                    Your cancellation request is currently being reviewed by our team. We'll update you shortly.
                </p>
                <?php if ($order['cancellation_reason']): ?>
                    <div class="od-action-note">
                        <strong>Your reason:</strong> <?= h($order['cancellation_reason']) ?>
                    </div>
                <?php endif; ?>
            </div>
        
        <?php elseif ($order['status'] === 'cancelled'): ?>
            <div class="od-action-card info">
                <div class="od-action-header">
                    <div class="od-action-icon">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <h2 class="od-action-title">Order Cancelled</h2>
                </div>
                <p class="od-action-text">
                    This order has been cancelled and will not be processed.
                </p>
                <?php if ($order['admin_note']): ?>
                    <div class="od-action-note">
                        <strong>Admin Note:</strong> <?= h($order['admin_note']) ?>
                    </div>
                <?php endif; ?>
            </div>
        
        <?php elseif ($order['status'] === 'delivered'): ?>
            <div class="od-action-card success">
                <div class="od-action-header">
                    <div class="od-action-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <h2 class="od-action-title">Confirm Delivery</h2>
                </div>
                <p class="od-action-text">
                    Your order has been delivered. Please confirm whether you received it in good condition.
                </p>
                <form id="confirmReceivedForm" action="/sweepxpress/customers/confirm_received.php" method="POST">
                    <input type="hidden" name="order_id" value="<?= h($order_id) ?>">
                    <button type="button" id="confirmReceivedBtn" class="od-btn od-btn-success">
                        <i class="bi bi-check2-circle"></i>
                        Confirm I Received My Order
                    </button>
                </form>
            </div>
        
        <?php elseif ($order['status'] === 'completed'): ?>
            <div class="od-action-card success">
                <div class="od-receipt-actions">
                    <div>
                        <div class="od-action-header" style="margin-bottom: var(--space-2);">
                            <div class="od-action-icon">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <h2 class="od-action-title">Order Completed</h2>
                        </div>
                        <p class="od-action-text" style="margin-bottom: 0;">
                            Thank you for confirming delivery. Your order is now complete!
                        </p>
                    </div>
                    <a href="/sweepxpress/customers/UserReceipt.php?order_id=<?= h($order['id']) ?>" 
                        target="_blank" 
                        class="od-btn od-btn-primary">
                        <i class="bi bi-receipt"></i>
                        View / Print Receipt
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <div class="od-items-card">
            <h3 class="od-items-title">
                <i class="bi bi-cart-check-fill"></i>
                Order Items
            </h3>
            
            <?php if ($items): ?>
                <div class="od-table-wrap">
                    <table class="od-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="text-center">QTY</th>
                                <th class="text-right">Price</th>
                                <th class="text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: var(--space-3);">
                                            <img src="<?= h($item['image_path']) ?>" 
                                                 alt="<?= h($item['name']) ?>" 
                                                 class="od-product-img">
                                            <span class="od-product-name"><?= h($item['name']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center"><?= h($item['quantity']) ?></td>
                                    <td class="text-right">₱<?= number_format($item['price'], 2) ?></td>
                                    <td class="text-right">₱<?= number_format($item['subtotal'], 2) ?></td> </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="od-empty">
                    <div class="od-empty-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="od-empty-text">No items found for this order.</div>
                </div>
            <?php endif; ?>
        </div>
        
        <a href="/sweepxpress/customers/my_orders.php" class="od-btn od-btn-secondary" style="height: 42px; width: 100%; max-width: 200px; padding: 0 var(--space-4);">
            <i class="bi bi-arrow-left"></i>
            Back to My Orders
        </a>

    </div></div><script>
// ====================================================================
// SWEETALERT POP-UPS & JS LOGIC
// ====================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Variable setup for SweetAlert
    const swalTitle = "<?= addslashes($swal_title) ?>";
    const swalText = "<?= addslashes($swal_text) ?>";
    const swalIcon = "<?= addslashes($swal_icon) ?>";
    const orderId = "<?= h($order_id) ?>";
    const isAdminDecisionSwal = <?= $is_admin_decision_swal ? 'true' : 'false' ?>;
    
    // Show general status pop-ups
    if (swalTitle && swalIcon) {
        Swal.fire({
            title: swalTitle,
            text: swalText,
            icon: swalIcon,
            confirmButtonText: 'OK',
            customClass: {
                popup: 'swal2-responsive',
                htmlContainer: 'swal2-pre-wrap'
            }
        }).then((result) => {
            if (isAdminDecisionSwal && result.isConfirmed) {
                // Redirects to clean the admin_note URL parameter
                window.location.href = `/sweepxpress/customers/order_details.php?id=${orderId}&cleanup=1`;
            }
        });
    }
    
    // Confirmation for 'Received Order' button
    const confirmBtn = document.getElementById('confirmReceivedBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            Swal.fire({
                title: 'Confirm Order Received?',
                text: "By confirming, you are verifying that you have received all items in Order #<?= h($order_id) ?> in good condition. This will complete your order.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--success-500)',
                cancelButtonColor: 'var(--error-500)',
                confirmButtonText: 'Yes, Received it!',
                cancelButtonText: 'No, there is an issue'
            }).then((result) => {
                const form = document.getElementById('confirmReceivedForm');
                if (result.isConfirmed) {
                    // Submits form with status=received
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'status';
                    input.value = 'received';
                    form.appendChild(input);
                    form.submit();
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    // Submits form with status=issue
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'status';
                    input.value = 'issue';
                    form.appendChild(input);
                    form.submit();
                }
            })
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>