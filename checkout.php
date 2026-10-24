<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/send_email.php';


// ...

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('is_logged_in')) {
    function is_logged_in() { return isset($_SESSION['user']); }
}

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(24)); }
$csrf_token = $_SESSION['csrf_token'];

if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="co-empty">
            <div class="co-empty-icon">🛒</div>
            <h2>Your Cart is Empty</h2>
            <p>Add some products to proceed to checkout</p>
            <a href="/sweepxpress/index.php" class="co-btn-primary">Start Shopping</a>
          </div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$cart = $_SESSION['cart'];
$ids = array_map('intval', array_keys($cart));
$ids_placeholder = implode(',', $ids) ?: '0';

try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($ids_placeholder)");
    $stmt->execute();
    $productRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
} catch (PDOException $e) {
    error_log("DB error fetching products in checkout: " . $e->getMessage());
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Could not load cart items. Please try again later.</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$items = [];
$total = 0.0;
foreach ($productRows as $row) {
    $pid = (int)$row['id'];
    $qty = isset($cart[$pid]) ? (int)$cart[$pid] : 0;
    if ($qty <= 0) continue;
    $price = (float)$row['price'];
    $subtotal = $qty * $price;
    $items[] = ['p' => $row, 'qty' => $qty, 'subtotal' => $subtotal];
    $total += $subtotal;
}

$success = false;
$message = '';
$payment_method = 'COD';
$order_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $sent_csrf)) {
        $message = '<div class="co-alert error">Invalid session. Please refresh the page and try again.</div>';
    } else {
        $name = trim($_POST['name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? ''); 
        $street_address = trim($_POST['street_address'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $address = "$street_address, $barangay, $city, $province"; 
        $payment_method = 'COD';
        $order_type = 'B2C'; 

        if ($name === '' || $phone_number === '' || $street_address === '' || $barangay === '' || $city === '' || $province === '') {
            $message = '<div class="co-alert error">Please fill in all required fields.</div>';
        } elseif (!preg_match('/^[A-Za-z\s.\'-]+$/', $name)) {
            $message = '<div class="co-alert error">Full Name can only contain letters, spaces, dots, apostrophes, and hyphens.</div>';
        } elseif (!preg_match('/^\+?\d[\d\s\(\)-]{6,20}\d$/', $phone_number)) { 
            $message = '<div class="co-alert error">Please enter a valid phone number.</div>';
        } elseif (empty($items)) {
            $message = '<div class="co-alert error">Your cart items are invalid. Please try again.</div>';
        } else {
            $payment_status = 'Pending';
            
            try {
                $pdo->beginTransaction();
                $uid = is_logged_in() ? $_SESSION['user']['id'] : null;
                $status = 'pending';
                $customer_name = $name;
                
                $stmt = $pdo->prepare("INSERT INTO orders 
                    (user_id, total, address, status, created_at, payment_method, payment_status, customer_name, notes, phone_number)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $uid, $total, $address, $status,
                    $payment_method, $payment_status, $customer_name, $notes, $phone_number 
                ]);
                $stmt->closeCursor();
                $order_id = $pdo->lastInsertId();

                $oi = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $getInventory = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = 1 FOR UPDATE");
                $updateInventory = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                $insertStockHistory = $pdo->prepare("INSERT INTO product_stock_history (product_id, action, quantity, note, created_at) VALUES (?, 'OUT', ?, ?, NOW())");
                $insertStockMovement = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at) VALUES (?, 1, 'OUT', ?, ?, ?, ?, NOW())");

                foreach ($items as $it) {
                    $product = $it['p'];
                    $pid = (int)$product['id'];
                    $qty = (int)$it['qty'];
                    $price = (float)$product['price'];
                    $oi->execute([$order_id, $pid, $qty, $price]);
                    $oi->closeCursor();
                    $getInventory->execute([$pid]);
                    $inv = $getInventory->fetch(PDO::FETCH_ASSOC);
                    $getInventory->closeCursor();
                    if ($inv) {
                        $invId = $inv['id'];
                        $newQty = max(0, (int)$inv['quantity'] - $qty);
                        $updateInventory->execute([$newQty, $invId]);
                        $updateInventory->closeCursor();
                        $note = "Order #$order_id checkout";
                        $insertStockHistory->execute([$pid, $qty, $note]);
                        $insertStockHistory->closeCursor();
                        $user_name = is_logged_in() ? ($_SESSION['user']['name'] ?? 'User') : 'Guest';
                        $insertStockMovement->execute([$pid, $qty, $note, $user_name, $user_name]);
                        $insertStockMovement->closeCursor();
                    }
                }
                $pdo->commit();
                
                // =======================================
                // SEND EMAIL AFTER SUCCESSFUL CHECKOUT
                // =======================================

                $customer_email = $_SESSION['user']['email'] ?? null;

                if ($customer_email) {
                    $subject = "Your Order #$order_id Has Been Placed!";
                    $body = "
                        <h2>Thank you for your order!</h2>
                        <p>Hello <b>$customer_name</b>,</p>
                        <p>Your order has been successfully placed and is now pending.</p>
                        <p><b>Order ID:</b> $order_id</p>
                        <p>Total: <b>₱" . number_format($total, 2) . "</b></p>
                        <br>
                        <p>You will receive updates once the order status changes.</p>
                        <br>
                        <p>Thank you for shopping with SweepXpress!</p>
                    ";

                    send_email($customer_email, $subject, $body);
                }


                try {
                    $silent_mode = true;
                    $ai_alert_path = __DIR__ . '/admin/cron_stock_alert.php'; 
                    if (file_exists($ai_alert_path)) {
                        include $ai_alert_path;
                    }
                } catch (Exception $e) {
                    error_log("AI Inventory Alert Error: " . $e->getMessage());
                }

                $_SESSION['cart'] = [];
                $success = true;
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Checkout error: " . $e->getMessage());
                $message = '<div class="co-alert error">An error occurred while placing your order. Please try again.</div>';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ============================================
   CHECKOUT - HUMAN-CRAFTED UI/UX DESIGN
   Clean, Professional, Real E-Commerce Feel
   Fully Responsive: 320px → 2560px+
   ============================================ */

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
    /* Clean Color Palette */
    --primary: #2563eb;
    --primary-dark: #1e40af;
    --primary-light: #dbeafe;
    
    --success: #10b981;
    --success-light: #d1fae5;
    --error: #ef4444;
    --error-light: #fee2e2;
    
    /* Professional Neutrals */
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
    
    /* Standard 8px Grid */
    --s-05: 4px;
    --s-1: 8px;
    --s-2: 12px;
    --s-3: 16px;
    --s-4: 20px;
    --s-5: 24px;
    --s-6: 32px;
    --s-8: 40px;
    --s-10: 48px;
    
    /* Real Typography */
    --text-xs: 12px;
    --text-sm: 14px;
    --text-base: 16px;
    --text-lg: 18px;
    --text-xl: 20px;
    --text-2xl: 24px;
    --text-3xl: 28px;
    
    /* Subtle Radius */
    --radius-sm: 4px;
    --radius: 6px;
    --radius-lg: 8px;
    --radius-xl: 12px;
    
    /* Minimal Shadows */
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
    --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    color: var(--gray-900);
}

/* Page Layout */
.co-page {
    background: var(--gray-50);
    min-height: 100vh;
    padding: var(--s-5) var(--s-3);
}

.co-container {
    max-width: 1080px;
    margin: 0 auto;
}

/* Header */
.co-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: var(--s-6);
    gap: var(--s-3);
}

.co-breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--s-1);
    font-size: var(--text-sm);
    color: var(--gray-500);
    margin-bottom: var(--s-2);
}

.co-breadcrumb a {
    color: var(--primary);
    text-decoration: none;
}

.co-breadcrumb a:hover {
    text-decoration: underline;
}

.co-breadcrumb .bi {
    font-size: 10px;
}

.co-title {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
    letter-spacing: -0.01em;
}

.co-btn-back {
    display: inline-flex;
    align-items: center;
    gap: var(--s-1);
    padding: var(--s-1) var(--s-3);
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    color: var(--gray-700);
    font-size: var(--text-sm);
    font-weight: 500;
    text-decoration: none;
    transition: all 0.15s;
}

.co-btn-back:hover {
    border-color: var(--gray-400);
    background: var(--gray-50);
}

/* Alert */
.co-alert {
    padding: var(--s-3) var(--s-3);
    border-radius: var(--radius-lg);
    border-left: 3px solid;
    margin-bottom: var(--s-5);
    font-size: var(--text-sm);
    line-height: 1.5;
}

.co-alert.error {
    background: var(--error-light);
    border-color: var(--error);
    color: #991b1b;
}

/* Grid */
.co-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: var(--s-5);
    align-items: start;
}

/* Card */
.co-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    margin-bottom: var(--s-4);
}

.co-card:last-child {
    margin-bottom: 0;
}

.co-card-header {
    padding: var(--s-3) var(--s-4);
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
}

.co-card-title {
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: var(--s-1);
}

.co-card-title .bi {
    color: var(--gray-500);
    font-size: 16px;
}

.co-card-body {
    padding: var(--s-4);
}

/* Form */
.co-form-group {
    margin-bottom: var(--s-4);
}

.co-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--s-3);
}

.co-label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--gray-700);
    margin-bottom: var(--s-1);
}

.co-label-required::after {
    content: '*';
    color: var(--error);
    margin-left: 2px;
}

.co-input,
.co-textarea {
    width: 100%;
    padding: 9px 12px;
    font-size: var(--text-base);
    font-family: inherit;
    color: var(--gray-900);
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    outline: none;
    transition: all 0.15s;
}

.co-input:focus,
.co-textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}

.co-input::placeholder,
.co-textarea::placeholder {
    color: var(--gray-400);
}

.co-textarea {
    min-height: 70px;
    resize: vertical;
    line-height: 1.5;
}

.co-helper {
    font-size: var(--text-xs);
    color: var(--gray-500);
    margin-top: var(--s-05);
}

/* Payment Option */
.co-payment {
    display: flex;
    align-items: center;
    gap: var(--s-2);
    padding: var(--s-3);
    background: white;
    border: 2px solid var(--gray-200);
    border-radius: var(--radius-lg);
    cursor: pointer;
    transition: all 0.15s;
}

.co-payment:hover {
    border-color: var(--primary);
}

.co-payment.selected {
    border-color: var(--primary);
    background: var(--primary-light);
}

.co-payment-radio {
    width: 18px;
    height: 18px;
    border: 2px solid var(--gray-300);
    border-radius: 50%;
    position: relative;
    flex-shrink: 0;
}

.co-payment.selected .co-payment-radio {
    border-color: var(--primary);
}

.co-payment.selected .co-payment-radio::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 8px;
    height: 8px;
    background: var(--primary);
    border-radius: 50%;
}

.co-payment-icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--gray-100);
    border-radius: var(--radius);
}

.co-payment-icon img {
    width: 24px;
    height: 24px;
    object-fit: contain;
}

.co-payment-content {
    flex: 1;
}

.co-payment-title {
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 2px;
}

.co-payment-desc {
    font-size: var(--text-sm);
    color: var(--gray-500);
}

.co-payment-info {
    margin-top: var(--s-3);
    padding: var(--s-3);
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
    border-radius: var(--radius);
}

.co-payment-info-title {
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: var(--s-1);
    display: flex;
    align-items: center;
    gap: var(--s-05);
}

.co-payment-info-text {
    font-size: var(--text-sm);
    color: var(--gray-600);
    line-height: 1.5;
}

/* Summary */
.co-summary {
    position: sticky;
    top: var(--s-5);
}

.co-summary-items {
    max-height: 340px;
    overflow-y: auto;
    margin-bottom: var(--s-3);
}

.co-summary-item {
    display: grid;
    grid-template-columns: 52px 1fr auto;
    gap: var(--s-2);
    padding: var(--s-2) 0;
    border-bottom: 1px solid var(--gray-100);
    align-items: center;
}

.co-summary-item:last-child {
    border-bottom: none;
}

.co-summary-img {
    width: 52px;
    height: 52px;
    border-radius: var(--radius);
    object-fit: cover;
    border: 1px solid var(--gray-200);
}

.co-summary-details {
    min-width: 0;
}

.co-summary-name {
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--gray-900);
    margin-bottom: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.co-summary-qty {
    font-size: var(--text-xs);
    color: var(--gray-500);
}

.co-summary-price {
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--gray-900);
    text-align: right;
}

.co-divider {
    height: 1px;
    background: var(--gray-200);
    margin: var(--s-3) 0;
}

.co-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: var(--s-3);
    background: var(--gray-50);
    border-radius: var(--radius-lg);
    margin-bottom: var(--s-4);
}

.co-total-label {
    font-size: var(--text-base);
    font-weight: 500;
    color: var(--gray-700);
}

.co-total-value {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--gray-900);
}

/* Buttons */
.co-btn-primary {
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--s-1);
    padding: 11px var(--s-4);
    background: var(--primary);
    color: white;
    border: none;
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s;
}

.co-btn-primary:hover {
    background: var(--primary-dark);
}

.co-btn-primary:active {
    transform: scale(0.98);
}

.co-btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.co-btn-secondary {
    flex: 1;
    padding: 10px var(--s-3);
    background: white;
    color: var(--gray-700);
    border: 1px solid var(--gray-300);
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--s-1);
}

.co-btn-secondary:hover {
    background: var(--gray-50);
    border-color: var(--gray-400);
}

.co-btn-confirm {
    flex: 2;
    padding: 10px var(--s-3);
    background: var(--success);
    color: white;
    border: none;
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--s-1);
}

.co-btn-confirm:hover {
    background: #059669;
}

/* Success State */
.co-success {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-xl);
    padding: var(--s-8) var(--s-6);
    text-align: center;
    box-shadow: var(--shadow-md);
    max-width: 600px;
    margin: var(--s-10) auto;
}

.co-success-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto var(--s-5);
    background: var(--success);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 36px;
}

.co-success-title {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--s-2);
}

.co-success-order {
    font-size: var(--text-lg);
    color: var(--primary);
    font-weight: 600;
    margin-bottom: var(--s-5);
}

.co-success-text {
    font-size: var(--text-base);
    color: var(--gray-600);
    line-height: 1.6;
    margin-bottom: var(--s-6);
}

.co-success-actions {
    display: flex;
    gap: var(--s-2);
    justify-content: center;
}

/* Empty State */
.co-empty {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-xl);
    padding: var(--s-10) var(--s-6);
    text-align: center;
    box-shadow: var(--shadow-md);
    max-width: 540px;
    margin: var(--s-10) auto;
}

.co-empty-icon {
    font-size: 64px;
    margin-bottom: var(--s-5);
    opacity: 0.4;
}

.co-empty h2 {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--s-2);
}

.co-empty p {
    font-size: var(--text-base);
    color: var(--gray-600);
    margin-bottom: var(--s-5);
}

/* Modal */
.co-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: var(--s-3);
    backdrop-filter: blur(2px);
}

.co-modal-overlay.hidden {
    display: none;
}

.co-modal {
    background: white;
    border-radius: var(--radius-xl);
    max-width: 640px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.co-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--s-4);
    border-bottom: 1px solid var(--gray-200);
    background: var(--gray-50);
}

.co-modal-title {
    display: flex;
    align-items: center;
    gap: var(--s-1);
    font-size: var(--text-lg);
    font-weight: 600;
    color: var(--gray-900);
}

.co-modal-close {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    border-radius: var(--radius);
    color: var(--gray-500);
    cursor: pointer;
    transition: all 0.15s;
}

.co-modal-close:hover {
    background: var(--gray-200);
    color: var(--gray-700);
}

.co-modal-body {
    padding: var(--s-4);
}

.co-modal-section {
    margin-bottom: var(--s-4);
}

.co-modal-section:last-child {
    margin-bottom: 0;
}

.co-modal-section-title {
    display: flex;
    align-items: center;
    gap: var(--s-1);
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: var(--s-2);
    padding-bottom: var(--s-1);
    border-bottom: 1px solid var(--gray-200);
}

.co-modal-info {
    display: grid;
    gap: var(--s-2);
}

.co-modal-info-row {
    display: grid;
    grid-template-columns: 90px 1fr;
    gap: var(--s-2);
    font-size: var(--text-sm);
}

.co-modal-info-label {
    font-weight: 500;
    color: var(--gray-600);
}

.co-modal-info-value {
    color: var(--gray-900);
}

.co-modal-items {
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.co-modal-item {
    display: grid;
    grid-template-columns: 44px 1fr auto;
    gap: var(--s-2);
    padding: var(--s-2);
    border-bottom: 1px solid var(--gray-100);
    align-items: center;
}

.co-modal-item:last-child {
    border-bottom: none;
}

.co-modal-item-img {
    width: 44px;
    height: 44px;
    border-radius: var(--radius);
    object-fit: cover;
    border: 1px solid var(--gray-200);
}

.co-modal-item-name {
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--gray-900);
}

.co-modal-item-qty {
    font-size: var(--text-xs);
    color: var(--gray-500);
}

.co-modal-item-price {
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--gray-900);
}

.co-modal-total {
    display: flex;
    justify-content: space-between;
    padding: var(--s-3);
    background: var(--success-light);
    border-radius: var(--radius-lg);
    margin-top: var(--s-2);
}

.co-modal-total-label {
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--gray-900);
}

.co-modal-total-value {
    font-size: var(--text-lg);
    font-weight: 700;
    color: var(--success);
}

.co-modal-footer {
    display: flex;
    gap: var(--s-2);
    padding: var(--s-4);
    background: var(--gray-50);
    border-top: 1px solid var(--gray-200);
}

/* Responsive: Tablet */
@media (max-width: 768px) {
    .co-grid {
        grid-template-columns: 1fr;
    }
    
    .co-summary {
        position: static;
    }
    
    .co-form-row {
        grid-template-columns: 1fr;
    }
    
    .co-success-actions {
        flex-direction: column;
    }
    
    .co-modal-info-row {
        grid-template-columns: 1fr;
        gap: var(--s-05);
    }
}

/* Responsive: Mobile */
@media (max-width: 640px) {
    .co-page {
        padding: var(--s-3) var(--s-2);
    }
    
    .co-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .co-btn-back {
        width: 100%;
        justify-content: center;
    }
    
    .co-title {
        font-size: var(--text-xl);
    }
    
    .co-card-body {
        padding: var(--s-3);
    }
    
    .co-success {
        padding: var(--s-6) var(--s-4);
    }
    
    .co-modal-footer {
        flex-direction: column;
    }
}

/* Responsive: Small Mobile */
@media (max-width: 480px) {
    .co-summary-item {
        grid-template-columns: 48px 1fr auto;
    }
    
    .co-summary-img {
        width: 48px;
        height: 48px;
    }
    
    .co-payment {
        padding: var(--s-2);
    }
}
</style>

<?php if ($success): ?>
<div class="co-page">
    <div class="co-container">
        <div class="co-success">
            <div class="co-success-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <h2 class="co-success-title">Order Placed Successfully!</h2>
            <div class="co-success-order">Order #<?= h($order_id) ?></div>
            <p class="co-success-text">
                Thank you for your purchase! Your order has been confirmed and will be processed shortly. 
                You will receive your items via Cash on Delivery (COD).
            </p>
            <div class="co-success-actions">
                <a href="/sweepxpress/Invoice.php?order_id=<?= h($order_id) ?>" class="co-btn-primary">
                    <i class="bi bi-file-earmark-text"></i>
                    View Invoice
                </a>
                <a href="/sweepxpress/index.php" class="co-btn-primary" style="background: var(--gray-700);">
                    <i class="bi bi-house-door"></i>
                    Continue Shopping
                </a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
                        <div class="co-page">
                            <div class="co-container">
                                
                                <header class="co-header">
                                    <div>
                                        <nav class="co-breadcrumb">
                                            <a href="/sweepxpress/index.php">Home</a>
                                            <i class="bi bi-chevron-right"></i>
                                            <a href="/sweepxpress/cart.php">Cart</a>
                                            <i class="bi bi-chevron-right"></i>
                                            <span>Checkout</span>
                                        </nav>
                                        <h1 class="co-title">Checkout</h1>
                                    </div>
                                    <a href="/sweepxpress/cart.php" class="co-btn-back">
                                        <i class="bi bi-arrow-left"></i>
                                        Back to Cart
                                    </a>
                                </header>

                                <?= $message ?>

                                <form method="post" id="checkoutForm">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                                    
                                    <div class="co-grid">
                                        
                                        <div>
                                            <div class="co-card">
                                                <div class="co-card-header">
                                                    <h2 class="co-card-title">
                                                        <i class="bi bi-truck"></i>
                                                        Shipping Details
                                                    </h2>
                                                </div>
                                                <div class="co-card-body">
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Full Name</label>
                                <input type="text" 
                                        name="name" 
                                        class="co-input"
                                        required 
                                        value="<?= h($_SESSION['user']['name'] ?? '') ?>" 
                                        pattern="^[A-Za-z\s.'-]+$"
                                        placeholder="Juan Dela Cruz">
                                <div class="co-helper">Enter your full legal name</div>
                            </div>

                            <div class="co-form-group">
                                <label class="co-label co-label-required">Email Address</label>
                                <input type="email" 
                                        name="email" 
                                        class="co-input" 
                                        required 
                                        value="<?= h($_SESSION['user']['email'] ?? '') ?>" 
                                        placeholder="juan.delacruz@example.com">
                                <div class="co-helper">For order updates and notifications</div>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Phone Number</label>
                                <input type="tel" 
                                        name="phone_number" 
                                        class="co-input" 
                                        required 
                                        value="<?= h($_POST['phone_number'] ?? '') ?>" 
                                        placeholder="09171234567"
                                        pattern="^\+?\d[\d\s\(\)-]{6,20}\d$">
                                <div class="co-helper">For delivery coordination</div>
                            </div>
                            
                            <div class="co-form-group">
                                <label class="co-label co-label-required">House/Unit Number & Street</label>
                                <input type="text" 
                                        name="street_address" 
                                        class="co-input" 
                                        required 
                                        value="<?= h($_POST['street_address'] ?? '') ?>"
                                        placeholder="123 Main Street">
                            </div>
                            
                            <div class="co-form-row">
                                <div class="co-form-group">
                                    <label class="co-label co-label-required">Barangay</label>
                                    <input type="text" 
                                            name="barangay" 
                                            class="co-input" 
                                            required 
                                            value="<?= h($_POST['barangay'] ?? '') ?>"
                                            placeholder="Barangay Name">
                                </div>
                                
                                <div class="co-form-group">
                                    <label class="co-label co-label-required">City/Municipality</label>
                                    <input type="text" 
                                            name="city" 
                                            class="co-input" 
                                            required 
                                            value="<?= h($_POST['city'] ?? '') ?>"
                                            placeholder="City Name">
                                </div>
                            </div>
                            
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Province</label>
                                <input type="text" 
                                        name="province" 
                                        class="co-input" 
                                        required 
                                        value="<?= h($_POST['province'] ?? '') ?>"
                                        placeholder="Province Name">
                            </div>
                            
                            <div class="co-form-group" style="margin-bottom: 0;">
                                <label class="co-label">Delivery Notes (Optional)</label>
                                <textarea name="notes" 
                                        class="co-textarea"
                                        placeholder="Any special instructions for delivery? (e.g., landmark, gate code)"><?= h($_POST['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="co-card">
                        <div class="co-card-header">
                            <h2 class="co-card-title">
                                <i class="bi bi-wallet2"></i>
                                Payment Method
                            </h2>
                        </div>
                        <div class="co-card-body">
                            <label class="co-payment selected">
                                <input type="radio" name="payment_method" value="COD" checked required hidden>
                                <div class="co-payment-radio"></div>
                                <div class="co-payment-icon">
                                    <img src="assets/Cash.png" alt="Cash on Delivery">
                                </div>
                                <div class="co-payment-content">
                                    <div class="co-payment-title">Cash on Delivery (COD)</div>
                                    <div class="co-payment-desc">Pay with cash when your order arrives</div>
                                </div>
                            </label>
                            
                            <div class="co-payment-info">
                                <div class="co-payment-info-title">
                                    <i class="bi bi-info-circle"></i> Payment Instructions
                                </div>
                                <p class="co-payment-info-text">
                                    Please prepare the <strong>exact amount of ₱<?= number_format($total, 2) ?></strong> in cash 
                                    when your order is delivered. Our delivery personnel will collect the payment upon successful delivery.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="co-summary">
                    <div class="co-card">
                        <div class="co-card-header">
                            <h2 class="co-card-title">
                                <i class="bi bi-receipt"></i>
                                Order Summary
                            </h2>
                        </div>
                        <div class="co-card-body">
                            <div class="co-summary-items">
                                <?php 
                                foreach ($items as $it): 
                                    // Determine correct image path
                                    $imgPath = $it['p']['image'] ?? $it['p']['image_path'] ?? 'assets/placeholder.jpg';
                                ?>
                                    <div class="co-summary-item">
                                        <img src="<?= h($imgPath) ?>" 
                                             alt="<?= h($it['p']['name']) ?>" 
                                             class="co-summary-img"
                                             onerror="this.src='assets/placeholder.jpg'">
                                        <div class="co-summary-details">
                                            <div class="co-summary-name"><?= h($it['p']['name']) ?></div>
                                            <div class="co-summary-qty">Qty: <?= (int)$it['qty'] ?> × ₱<?= number_format($it['p']['price'], 2) ?></div>
                                        </div>
                                        <div class="co-summary-price">
                                            ₱<?= number_format($it['subtotal'], 2) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="co-divider"></div>
                            
                            <div class="co-total">
                                <div class="co-total-label">Total</div>
                                <div class="co-total-value">₱<?= number_format($total, 2) ?></div>
                            </div>
                            
                            <button type="button" id="placeOrderBtn" class="co-btn-primary">
                                <i class="bi bi-bag-check"></i>
                                Place Order
                            </button>
                        </div>
                    </div>
                </div>
                
            </div>
        </form>
        
    </div>
</div>

<!-- Confirmation Modal -->
<div class="co-modal-overlay hidden" id="confirmModal">
    <div class="co-modal">
        <div class="co-modal-header">
            <h3 class="co-modal-title">
                <i class="bi bi-clipboard-check"></i>
                Confirm Your Order
            </h3>
            <button type="button" class="co-modal-close" id="closeModalBtn">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        
        <div class="co-modal-body">
            <div class="co-modal-section">
                <div class="co-modal-section-title">
                    <i class="bi bi-truck"></i>
                    Shipping Information
                </div>
                <div class="co-modal-info">
                    <div class="co-modal-info-row">
                        <div class="co-modal-info-label">Name:</div>
                        <div class="co-modal-info-value" id="modalName"></div>
                    </div>
                    <div class="co-modal-info-row">
                        <div class="co-modal-info-label">Email:</div>
                        <div class="co-modal-info-value" id="modalEmail"></div>
                    </div>
                    <div class="co-modal-info-row">
                        <div class="co-modal-info-label">Phone:</div>
                        <div class="co-modal-info-value" id="modalPhone"></div>
                    </div>
                    <div class="co-modal-info-row">
                        <div class="co-modal-info-label">Address:</div>
                        <div class="co-modal-info-value" id="modalAddress"></div>
                    </div>
                    <div class="co-modal-info-row">
                        <div class="co-modal-info-label">Notes:</div>
                        <div class="co-modal-info-value" id="modalNotes"></div>
                    </div>
                </div>
            </div>
            
            <div class="co-modal-section">
                <div class="co-modal-section-title">
                    <i class="bi bi-wallet2"></i>
                    Payment Method
                </div>
                <div class="co-modal-info">
                    <div class="co-modal-info-row">
                        <div class="co-modal-info-label">Method:</div>
                        <div class="co-modal-info-value">Cash on Delivery (COD)</div>
                    </div>
                </div>
            </div>
            
            <div class="co-modal-section">
                <div class="co-modal-section-title">
                    <i class="bi bi-basket"></i>
                    Order Items
                </div>
                <div class="co-modal-items">
                    <?php 
                    foreach ($items as $it): 
                        $imgPath = $it['p']['image'] ?? $it['p']['image_path'] ?? 'assets/placeholder.jpg';
                    ?>
                        <div class="co-modal-item">
                            <img src="<?= h($imgPath) ?>" 
                                 alt="<?= h($it['p']['name']) ?>" 
                                 class="co-modal-item-img"
                                 onerror="this.src='assets/placeholder.jpg'">
                            <div>
                                <div class="co-modal-item-name"><?= h($it['p']['name']) ?></div>
                                <div class="co-modal-item-qty">Qty: <?= (int)$it['qty'] ?></div>
                            </div>
                            <div class="co-modal-item-price">₱<?= number_format($it['subtotal'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="co-modal-total">
                    <div class="co-modal-total-label">Total Amount</div>
                    <div class="co-modal-total-value">₱<?= number_format($total, 2) ?></div>
                </div>
            </div>
        </div>
        
        <div class="co-modal-footer">
            <button type="button" class="co-btn-secondary" id="cancelOrderBtn">
                <i class="bi bi-x-circle"></i>
                Cancel
            </button>
            <button type="button" class="co-btn-confirm" id="confirmOrderBtn">
                <i class="bi bi-check-circle"></i>
                Yes, Place Order
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    const form = document.getElementById('checkoutForm');
    const placeOrderBtn = document.getElementById('placeOrderBtn');
    const confirmModal = document.getElementById('confirmModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelOrderBtn = document.getElementById('cancelOrderBtn');
    const confirmOrderBtn = document.getElementById('confirmOrderBtn');
    
    placeOrderBtn.addEventListener('click', function() {
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        
        const name = form.querySelector('input[name="name"]').value.trim();
        const email = form.querySelector('input[name="email"]').value.trim(); // <--- Kinuha ang Email
        const phone = form.querySelector('input[name="phone_number"]').value.trim();
        const street = form.querySelector('input[name="street_address"]').value.trim();
        const barangay = form.querySelector('input[name="barangay"]').value.trim();
        const city = form.querySelector('input[name="city"]').value.trim();
        const province = form.querySelector('input[name="province"]').value.trim();
        const notes = form.querySelector('textarea[name="notes"]').value.trim();
        
        document.getElementById('modalName').textContent = name;
        document.getElementById('modalEmail').textContent = email; // <--- Idinagdag sa modal display
        document.getElementById('modalPhone').textContent = phone;
        document.getElementById('modalAddress').textContent = `${street}, ${barangay}, ${city}, ${province}`;
        document.getElementById('modalNotes').textContent = notes || 'None';
        
        confirmModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    });
    
    function closeModal() {
        confirmModal.classList.add('hidden');
        document.body.style.overflow = '';
    }
    
    closeModalBtn.addEventListener('click', closeModal);
    cancelOrderBtn.addEventListener('click', closeModal);
    
    confirmOrderBtn.addEventListener('click', function() {
        confirmOrderBtn.disabled = true;
        confirmOrderBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
        form.submit();
    });
    
    confirmModal.addEventListener('click', function(e) {
        if (e.target === confirmModal) {
            closeModal();
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !confirmModal.classList.contains('hidden')) {
            closeModal();
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
