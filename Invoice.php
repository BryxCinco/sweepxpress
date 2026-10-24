<?php
// receipt.php - ENTERPRISE RECEIPT/ORDER SUMMARY SYSTEM
// Responsive: 320px-2560px+ | Print-optimized | Human-crafted

// ====================================================================
// 1. CORE CONFIG & SECURITY (Must be first)
// ====================================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Initialize variables BEFORE any output
$user   = $_SESSION['user'] ?? null;
$userId = $user['id'] ?? null;

// Redirect if not logged in
if (!$user) {
    header("Location: /sweepxpress/login.php");
    exit();
}

// ====================================================================
// 2. HELPER FUNCTIONS
// ====================================================================
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

function extract_phone_from_notes($notes) {
    if (empty($notes)) return 'N/A';
    if (preg_match('/Buyer Phone: (\d+)/', $notes, $matches)) {
        return $matches[1];
    }
    if (preg_match('/Buyer Phone: N\/A/', $notes)) {
        return 'N/A';
    }
    return 'N/A';
}

// ====================================================================
// 3. DATA FETCH & VALIDATION
// ====================================================================
$order         = null;
$items         = [];
$error_message = '';
$orderId       = $_GET['order_id'] ?? null;
$displayEmail  = 'N/A';
$back_url      = "/sweepxpress/customers/my_orders.php";

if (!$orderId || !is_numeric($orderId)) {
    $error_message = "Invalid or missing Order ID";
} else {
    try {
        // Fetch Order (restrict by user_id for security)
        $stmt = $pdo->prepare("
            SELECT o.*, u.email AS user_email 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            WHERE o.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $error_message = "Order not found or access denied (ID: " . h($orderId) . ")";
        } else {
            $displayEmail = $order['user_email'] ?? $order['email'] ?? 'N/A';

            // Fetch Order Items
            $stmt = $pdo->prepare("
                SELECT oi.quantity, oi.price, p.name, p.image
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $error_message = "Database error occurred";
        error_log("Receipt Error: " . $e->getMessage());
    }
}

// ====================================================================
// 4. DOCUMENT CONFIGURATION
// ====================================================================
$status         = strtolower($order['status'] ?? '');
$isReceiptValid = in_array($status, ['completed', 'delivered']);
$document_title = $isReceiptValid ? 'OFFICIAL RECEIPT' : 'ORDER SUMMARY';

$payment_display = 'N/A';
$phone_display   = 'N/A';

if ($order) {
    // Payment Method Display
    if (!empty($order['payment_terms'])) {
        $payment_display = h($order['payment_terms']);
    } elseif (!empty($order['payment_method'])) {
        $payment_display = h(ucfirst($order['payment_method']));
    }

    // Phone Number Display
    if (!empty($order['phone_number'])) {
        $phone_display = h($order['phone_number']);
    } elseif (!empty($order['phone'])) {
        $phone_display = h($order['phone']);
    } else {
        $phone_display = extract_phone_from_notes($order['notes'] ?? '');
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($document_title) ?> #<?= h($orderId) ?> - SweepXpress</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
/* =====================================================
   ENTERPRISE RECEIPT SYSTEM - GRAYSCALE LIGHT MODE
   Print-optimized | Ink-saving | Eye-friendly
   ===================================================== */

:root {
    /* Grayscale palette (no pure black, only soft dark grays) */
    --white:      #ffffff;
    --gray-25:    #fcfcfc;
    --gray-50:    #f7f7f7;
    --gray-100:   #f0f0f0;
    --gray-200:   #e0e0e0;
    --gray-300:   #d0d0d0;
    --gray-400:   #a0a0a0;
    --gray-500:   #808080;
    --gray-600:   #606060;
    --gray-700:   #404040;
    --gray-800:   #303030;
    --gray-900:   #222222;

    /* “Brand” tones in gray only */
    --brand-primary:      #333333;
    --brand-primary-dark: #262626;

    /* Status tints in gray (no color ink) */
    --success-50:  #f5f5f5;
    --success-500: #3f3f3f;
    --success-600: #353535;
    --success-700: #2b2b2b;

    --info-50:   #f5f5f5;
    --info-500:  #3a3a3a;

    /* Spacing (8px base) */
    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-5: 1.25rem;
    --space-6: 1.5rem;
    --space-8: 2rem;
    --space-10: 2.5rem;
    --space-12: 3rem;

    /* Typography */
    --text-xs:  0.75rem;
    --text-sm:  0.875rem;
    --text-base:1rem;
    --text-lg:  1.125rem;
    --text-xl:  1.25rem;
    --text-2xl: 1.5rem;
    --text-3xl: 1.875rem;
    --text-4xl: 2.25rem;

    /* Radius */
    --radius-md: 8px;
    --radius-lg: 12px;
    --radius-xl: 16px;

    /* Shadows (light to reduce ink when printed; mostly for screen) */
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 8px rgba(0,0,0,0.06);

    /* Layout */
    --container-max: 880px;
}

/* Reset & Base */
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
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* =====================================================
   PAGE LAYOUT
   ===================================================== */
.rc-page {
    width: 100%;
    min-height: 100vh;
    padding: var(--space-8) var(--space-4);
}

.rc-container {
    width: 100%;
    max-width: var(--container-max);
    margin: 0 auto;
}

/* =====================================================
   ERROR STATE (GRAY ONLY)
   ===================================================== */
.rc-error-card {
    background: linear-gradient(135deg, var(--gray-50), var(--white));
    border: 1px solid var(--gray-300);
    border-radius: var(--radius-xl);
    padding: var(--space-8);
    text-align: center;
    box-shadow: var(--shadow-md);
}

.rc-error-icon {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, var(--gray-700), var(--gray-800));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto var(--space-5);
    color: var(--white);
    font-size: var(--text-3xl);
}

.rc-error-title {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--gray-800);
    margin-bottom: var(--space-3);
}

.rc-error-text {
    font-size: var(--text-base);
    color: var(--gray-600);
    margin-bottom: var(--space-6);
}

/* =====================================================
   RECEIPT DOCUMENT
   ===================================================== */
.rc-document {
    background: var(--white);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-md);
    overflow: hidden;
    margin-bottom: var(--space-6);
}

/* Document Header (no heavy fill, just subtle band) */
.rc-doc-header {
    background: var(--gray-50);
    color: var(--gray-900);
    padding: var(--space-8) var(--space-8);
    text-align: center;
    position: relative;
    border-bottom: 1px solid var(--gray-200);
}

.rc-doc-header::before {
    content: '';
    position: absolute;
    top: 0;
    right: 12%;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 1px dashed var(--gray-200);
    opacity: 0.5;
}

.rc-doc-type {
    font-size: var(--text-xs);
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gray-500);
    margin-bottom: var(--space-2);
}

.rc-doc-title {
    font-size: var(--text-3xl);
    font-weight: 800;
    letter-spacing: -0.02em;
    margin-bottom: var(--space-3);
    color: var(--gray-900);
}

.rc-order-id {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-5);
    background: var(--white);
    border-radius: var(--radius-md);
    font-family: 'JetBrains Mono', monospace;
    font-size: var(--text-lg);
    font-weight: 600;
    border: 1px solid var(--gray-200);
}

/* Document Body */
.rc-doc-body {
    padding: var(--space-8);
}

/* Info Grid */
.rc-info-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: var(--space-6);
    margin-bottom: var(--space-8);
    padding-bottom: var(--space-8);
    border-bottom: 1px dashed var(--gray-200);
}

.rc-info-card {
    background: var(--gray-50);
    border-radius: var(--radius-lg);
    padding: var(--space-5);
    border: 1px solid var(--gray-200);
}

.rc-info-label {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-500);
    margin-bottom: var(--space-2);
}

.rc-info-label i {
    font-size: 14px;
    color: var(--gray-500);
}

.rc-info-value {
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--gray-900);
    line-height: 1.5;
}

.rc-info-value.large {
    font-size: var(--text-lg);
}

/* Items Section */
.rc-section-title {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--space-5);
}

.rc-section-icon {
    width: 40px;
    height: 40px;
    background: var(--gray-800);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--white);
    font-size: var(--text-lg);
}

/* Table */
.rc-table-wrap {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: var(--space-8);
}

.rc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--text-sm);
}

.rc-table thead {
    background: var(--gray-50);
}

.rc-table th {
    padding: var(--space-4) var(--space-5);
    font-size: var(--text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-600);
    text-align: left;
    border-bottom: 1px solid var(--gray-200);
}

.rc-table th.text-center { text-align: center; }
.rc-table th.text-right  { text-align: right;  }

.rc-table td {
    padding: var(--space-4) var(--space-5);
    color: var(--gray-700);
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
}

.rc-table tbody tr:last-child td {
    border-bottom: none;
}

.rc-table tbody tr:hover {
    background: var(--gray-25);
}

.rc-table .text-center { text-align: center; }
.rc-table .text-right  { text-align: right;  }

.rc-product-name {
    font-weight: 600;
    color: var(--gray-900);
}

.rc-table tfoot {
    background: var(--gray-50);
}

.rc-table tfoot td {
    padding: var(--space-5);
    font-size: var(--text-lg);
    font-weight: 700;
    color: var(--gray-800);
    border-top: 2px double var(--gray-400);
    border-bottom: none;
}

/* Notes Section */
.rc-notes-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: var(--space-6);
    margin-bottom: var(--space-8);
}

.rc-notes-title {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-600);
    margin-bottom: var(--space-3);
}

.rc-notes-content {
    font-size: var(--text-sm);
    color: var(--gray-700);
    line-height: 1.7;
}

.rc-notes-content p {
    margin-bottom: var(--space-2);
}

.rc-notes-content p:last-child {
    margin-bottom: 0;
}

/* Action Buttons */
.rc-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-4);
    justify-content: flex-end;
    margin-bottom: var(--space-8);
}

.rc-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    height: 52px;
    padding: 0 var(--space-6);
    font-size: var(--text-base);
    font-weight: 600;
    font-family: inherit;
    text-decoration: none;
    border-radius: var(--radius-lg);
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
}

.rc-btn-primary {
    background: var(--brand-primary);
    color: var(--white);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
}

.rc-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(0,0,0,0.18);
}

.rc-btn-secondary {
    background: var(--white);
    color: var(--gray-800);
    border: 1px solid var(--gray-300);
}

.rc-btn-secondary:hover {
    background: var(--gray-50);
    border-color: var(--gray-400);
}

/* =====================================================
   PRINT STYLES (no heavy fills; mostly white)
   ===================================================== */
@media print {
    @page {
        size: A4;
        margin: 1cm;
    }

    body {
        background: #ffffff !important;
        color: #222222 !important;
    }

    .rc-page {
        padding: 0 !important;
    }

    .rc-actions,
    .navbar,
    .footer,
    .rc-error-card {
        display: none !important;
    }

    .rc-document {
        box-shadow: none !important;
        border-radius: 0 !important;
        page-break-inside: avoid;
        border: 1px solid #cccccc;
    }

    .rc-doc-header {
        background: #ffffff !important;
        color: #222222 !important;
        border-bottom: 1px solid #cccccc !important;
    }

    .rc-doc-header::before {
        display: none !important;
    }

    .rc-info-card {
        background: #f7f7f7 !important;
    }

    .rc-table-wrap {
        border: 1px solid #999999 !important;
    }

    .rc-table thead {
        background: #f0f0f0 !important;
    }

    .rc-table tfoot {
        background: #f7f7f7 !important;
    }
}

/* =====================================================
   RESPONSIVE: TABLET
   ===================================================== */
@media (max-width: 768px) {
    .rc-page {
        padding: var(--space-6) var(--space-4);
    }

    .rc-doc-header {
        padding: var(--space-6) var(--space-6);
    }

    .rc-doc-title {
        font-size: var(--text-2xl);
    }

    .rc-doc-body {
        padding: var(--space-6);
    }

    .rc-info-section {
        grid-template-columns: 1fr;
        gap: var(--space-4);
    }

    .rc-table th,
    .rc-table td {
        padding: var(--space-3) var(--space-4);
        font-size: var(--text-xs);
    }

    .rc-actions {
        flex-direction: column;
    }

    .rc-btn {
        width: 100%;
    }
}

/* =====================================================
   RESPONSIVE: MOBILE
   ===================================================== */
@media (max-width: 480px) {
    .rc-page {
        padding: var(--space-5) var(--space-3);
    }

    .rc-doc-header {
        padding: var(--space-5) var(--space-4);
    }

    .rc-doc-title {
        font-size: var(--text-xl);
    }

    .rc-order-id {
        font-size: var(--text-base);
        padding: var(--space-2) var(--space-4);
    }

    .rc-doc-body {
        padding: var(--space-5);
    }

    .rc-section-title {
        font-size: var(--text-lg);
    }

    .rc-section-icon {
        width: 36px;
        height: 36px;
        font-size: var(--text-base);
    }

    .rc-table-wrap {
        margin: 0 calc(var(--space-5) * -1);
        border-radius: 0;
        border-left: none;
        border-right: none;
    }

    .rc-table th,
    .rc-table td {
        padding: var(--space-2) var(--space-3);
    }

    .rc-btn {
        height: 48px;
        font-size: var(--text-sm);
    }
}

/* =====================================================
   ACCESSIBILITY
   ===================================================== */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        transition-duration: 0.01ms !important;
    }
}

.rc-btn:focus-visible {
    outline: 3px solid var(--gray-600);
    outline-offset: 2px;
}
    </style>
</head>
<body>

<div class="rc-page">
    <div class="rc-container">

        <?php if ($error_message): ?>
            <!-- Error State -->
            <div class="rc-error-card">
                <div class="rc-error-icon">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <h2 class="rc-error-title">Unable to Load Receipt</h2>
                <p class="rc-error-text"><?= h($error_message) ?></p>
                <a href="<?= h($back_url) ?>" class="rc-btn rc-btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Orders
                </a>
            </div>

        <?php elseif ($order): ?>
            <!-- Action Buttons (Before Document) -->
            <div class="rc-actions">
                <button class="rc-btn rc-btn-primary" onclick="window.print()">
                    <i class="bi bi-printer-fill"></i> Print Document
                </button>
                <a href="<?= h($back_url) ?>" class="rc-btn rc-btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Orders
                </a>
            </div>

            <!-- Receipt Document -->
            <div class="rc-document">
                
                <!-- Document Header -->
                <header class="rc-doc-header">
                    <div class="rc-doc-type"><?= $isReceiptValid ? 'Official Receipt' : 'Order Summary' ?></div>
                    <h1 class="rc-doc-title"><?= h($document_title) ?></h1>
                    <div class="rc-order-id">
                        <i class="bi bi-hash"></i>
                        <span><?= h($order['id']) ?></span>
                    </div>
                </header>

                <!-- Document Body -->
                <div class="rc-doc-body">

                    <!-- Info Section -->
                    <div class="rc-info-section">
                        <!-- Customer Info -->
                        <div class="rc-info-card">
                            <div class="rc-info-label">
                                <i class="bi bi-person-fill"></i>
                                Customer Information
                            </div>
                            <div class="rc-info-value"><?= h($order['customer_name'] ?? 'N/A') ?></div>
                        </div>

                        <div class="rc-info-card">
                            <div class="rc-info-label">
                                <i class="bi bi-envelope-fill"></i>
                                Email Address
                            </div>
                            <div class="rc-info-value"><?= h($displayEmail) ?></div>
                        </div>

                        <div class="rc-info-card">
                            <div class="rc-info-label">
                                <i class="bi bi-telephone-fill"></i>
                                Phone Number
                            </div>
                            <div class="rc-info-value"><?= h($phone_display) ?></div>
                        </div>

                        <div class="rc-info-card">
                            <div class="rc-info-label">
                                <i class="bi bi-calendar-event-fill"></i>
                                Order Date
                            </div>
                            <div class="rc-info-value"><?= date('M j, Y, g:i A', strtotime($order['created_at'])) ?></div>
                        </div>

                        <div class="rc-info-card">
                            <div class="rc-info-label">
                                <i class="bi bi-credit-card-fill"></i>
                                Payment Method
                            </div>
                            <div class="rc-info-value"><?= h($payment_display) ?></div>
                        </div>

                        <div class="rc-info-card">
                            <div class="rc-info-label">
                                <i class="bi bi-check-circle-fill"></i>
                                Order Status
                            </div>
                            <div class="rc-info-value"><?= h(ucfirst(str_replace('_', ' ', $order['status']))) ?></div>
                        </div>
                    </div>

                    <!-- Items Table -->
                    <h3 class="rc-section-title">
                        <div class="rc-section-icon">
                            <i class="bi bi-cart-check-fill"></i>
                        </div>
                        <span>Items Purchased</span>
                    </h3>

                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th class="text-right">Price/Unit</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <span class="rc-product-name"><?= h($item['name']) ?></span>
                                    </td>
                                    <td class="text-right">₱<?= number_format((float)$item['price'], 2) ?></td>
                                    <td class="text-center"><?= (int)$item['quantity'] ?></td>
                                    <td class="text-right">₱<?= number_format((float)$item['price'] * (int)$item['quantity'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-right">Grand Total</td>
                                    <td class="text-right">₱<?= number_format((float)$order['total'], 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Shipping & Notes -->
                    <div class="rc-notes-card">
                        <div class="rc-notes-title">
                            <i class="bi bi-geo-alt-fill"></i>
                            Shipping Address & Notes
                        </div>
                        <div class="rc-notes-content">
                            <p><strong>Delivery Address:</strong></p>
                            <p><?= nl2br(h($order['address'] ?? 'Not specified')) ?></p>
                            
                            <?php if (!empty(trim($order['notes'] ?? ''))): ?>
                                <p style="margin-top: var(--space-4);"><strong>Additional Notes:</strong></p>
                                <p><?= nl2br(h($order['notes'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>

        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
