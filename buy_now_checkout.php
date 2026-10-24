<?php
session_start();
require_once __DIR__ . '/config.php';
// Sa checkout.php at buy_now_checkout.php:

// ...
require_once __DIR__ . '/includes/send_email.php';
// ...

if (!function_exists('h')) { function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('is_logged_in')) { function is_logged_in() { return isset($_SESSION['user']); } }

// CSRF token
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf_token = $_SESSION['csrf_token'];

// Buy Now item retrieval
$buy_now = $_SESSION['buy_now_item'] ?? null;
if (!$buy_now) {
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="co-empty">
            <div class="co-empty-icon">🛒</div>
            <h2>No Buy Now Item Selected</h2>
            <p>Please select a product to purchase directly.</p>
            <a href="/sweepxpress/index.php" class="co-btn-primary">Start Shopping</a>
          </div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// =========================================================================
// ✅ Stock and Product Details Fetch (Refined)
// =========================================================================
$product_id = $buy_now['id'];
$actual_stock = 0; 
$product_data = [];
try {
    $stmt_product = $pdo->prepare("
        SELECT p.price, p.name, p.image, p.image_path, i.quantity 
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id AND i.location_id = 1
        WHERE p.id = ?
    ");
    $stmt_product->execute([$product_id]);
    $product_data = $stmt_product->fetch(PDO::FETCH_ASSOC);
    
    if ($product_data) {
        $buy_now = array_merge($buy_now, $product_data);
        $actual_stock = (int)($product_data['quantity'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Stock check error: ".$e->getMessage()); 
}
$max_qty = $actual_stock > 0 ? $actual_stock : 0; 
// =========================================================================

// Quantity correction (PHP logic kept: Corrects the quantity stored in session on page load)
$qty = isset($buy_now['qty']) && $buy_now['qty'] > 0 ? (int)$buy_now['qty'] : 1;

// --- CRITICAL FIX: Ensure $qty never exceeds $max_qty ---
$old_qty = $qty;
$qty = min($qty, $max_qty > 0 ? $max_qty : 0); 
if ($qty < 1 && $max_qty > 0) $qty = 1;

// If quantity was corrected due to stock, update session and show warning
$message = '';
if ($max_qty === 0) {
    $qty = 0; // Force quantity to 0 if OOS
    $message = '<div class="co-alert error" id="persistent-alert"><i class="bi bi-exclamation-triangle-fill"></i> Item is **Out of Stock**. Order cannot be placed.</div>';
} elseif ($qty !== $old_qty) {
    // If the old quantity (requested) was higher than stock, update session to max available
    $_SESSION['buy_now_item']['qty'] = $qty; 
    $message = '<div class="co-alert error" id="persistent-alert"><i class="bi bi-exclamation-triangle-fill"></i> The requested quantity (**'.$old_qty.'**) exceeded the available stock. Quantity has been adjusted to **'.$qty.'** unit(s).</div>';
} else {
    // Ensure session is updated with the final $qty
     $_SESSION['buy_now_item']['qty'] = $qty;
}

$total = $qty * (float)($buy_now['price'] ?? 0);


// POST handler (Final Order Placement)
$success = false;
$payment_method = 'COD';
$order_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // CSRF
    $sent_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $sent_csrf)) {
        $message = '<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Invalid session. Refresh and try again.</div>';
    } else {
        // Fetch current quantity from form submission (if client side was manipulated)
        $form_qty_array = $_POST['qty'] ?? [];
        $submitted_qty = (int)($form_qty_array[$product_id] ?? 0);
        
        // --- CRITICAL FIX: Re-verify quantity vs actual stock before transaction ---
        $current_qty = min($submitted_qty, $max_qty); // Use the lesser of submitted qty and max_qty
        
        // Customer info
        $name = trim($_POST['name'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $street = trim($_POST['street_address'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $payment_method = $_POST['payment_method'] ?? 'COD';
        $address = "$street, $barangay, $city, $province";
        
        // Re-calculate total based on validated current_qty
        $final_total = $current_qty * (float)($buy_now['price'] ?? 0);


        // Basic validation
        if ($name=='' || $street=='' || $barangay=='' || $city=='' || $province=='' || $contact_number=='') {
            $message = '<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Please fill in all required fields (Name, Address, Contact Number).</div>';
        }
        elseif (!preg_match('/^[A-Za-z\s.\'-]+$/', $name)) {
            $message = '<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Full Name can only contain letters, spaces, dots, apostrophes, and hyphens.</div>';
        } elseif (!preg_match('/^\+?\d[\d\s\(\)-]{6,20}\d$/', $contact_number)) { 
            $message = '<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Please enter a valid phone number.</div>';
        }
        // FINAL CHECK for stock before transaction
        else if ($current_qty < 1 || $current_qty > $max_qty) { 
             $message = '<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Sorry, the item is out of stock or the quantity requested is invalid. Available stock: '.$max_qty.'.</div>';
        }
        else {
            try {
                $pdo->beginTransaction();
                $uid = is_logged_in() ? $_SESSION['user']['id'] : null;
                $qty_to_deduct = $current_qty; // This is the final quantity used for transaction

                switch (strtoupper($payment_method)) {
                    case 'COD': default: $payment_status='Pending'; break;
                }
                
                // 1. INSERT Order
                $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, address, status, created_at, payment_method, payment_status, customer_name, phone_number, notes) VALUES (:uid, :total, :address, :status, NOW(), :pmethod, :pstatus, :cname, :cnumber, :notes)");
                
               $stmt->execute([
                'uid'     => $uid,
                'total'   => $final_total, // Use validated total
                'address' => $address,
                'status'  => 'pending',
                'pmethod' => $payment_method,
                'pstatus' => $payment_status,
                'cname'   => $name,
                'cnumber'   => $contact_number, 
                'notes'   => $notes,
            ]);

                $order_id = $pdo->lastInsertId();

                // 2. INSERT Order Items
                $oi = $pdo->prepare("INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)");
                $oi->execute([$order_id,$buy_now['id'],$qty_to_deduct,$buy_now['price']]);

                // 3. Inventory update (Optimized check)
                $getInv = $pdo->prepare("SELECT id,quantity FROM inventory WHERE product_id=? AND location_id=1 FOR UPDATE");
                $updInv = $pdo->prepare("UPDATE inventory SET quantity=? WHERE id=?");
                $insHist = $pdo->prepare("INSERT INTO product_stock_history (product_id,action,quantity,note,created_at) VALUES (?, 'OUT', ?, ?, NOW())");
                $insMove = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at) VALUES (?, 1, 'OUT', ?, ?, ?, ?, NOW())");
                
                $getInv->execute([$buy_now['id']]);
                $inv = $getInv->fetch(PDO::FETCH_ASSOC);
                
                if ($inv && (int)$inv['quantity'] >= $qty_to_deduct) {
                    $newStock = (int)$inv['quantity'] - $qty_to_deduct;
                    $updInv->execute([$newStock,$inv['id']]);
                    $note = "Order #$order_id checkout";
                    $insHist->execute([$buy_now['id'],$qty_to_deduct,$note]);
                    $user_name = is_logged_in() ? ($_SESSION['user']['name'] ?? 'User') : 'Guest';
                    $insMove->execute([$buy_now['id'], $qty_to_deduct, $note, $user_name, $user_name]);
                } else {
                    $pdo->rollBack();
                    // Re-fetch max_qty to ensure display is correct after error (optional but good practice)
                    $stmt_stock = $pdo->prepare("SELECT quantity FROM inventory WHERE product_id = :pid AND location_id = 1");
                    $stmt_stock->execute([':pid' => $product_id]);
                    $inv_data = $stmt_stock->fetch(PDO::FETCH_ASSOC);
                    $max_qty = (int)($inv_data['quantity'] ?? 0);

                    $message='<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Error: Insufficient stock. Please reload the page. Current available: '.$max_qty.'.</div>';
                    throw new Exception("Stock insufficiency detected during transaction.");
                }

                $pdo->commit();

                // =======================================
                // SEND EMAIL AFTER SUCCESSFUL CHECKOUT
                // =======================================

                $customer_name  = $name; 
                $customer_email = $_POST['email'] ?? ($_SESSION['user']['email'] ?? '');

                if ($customer_email) {
                    $subject = "Your Order #$order_id Has Been Placed!";
                    $body = "
                        <h2>Thank you for your order!</h2>
                        <p>Hello <b>$customer_name</b>,</p>
                        <p>Your order has been successfully placed and is now pending.</p>
                        <p><b>Order ID:</b> $order_id</p>
                        <p>Total: <b>₱" . number_format($final_total, 2) . "</b></p>
                        <br>
                        <p>You will receive updates once the order status changes.</p>
                        <br>
                        <p>Thank you for shopping with SweepXpress!</p>
                    ";

                    send_email($customer_email, $subject, $body);
                }


                // Success: Clear buy_now item from session
                unset($_SESSION['buy_now_item']); 
                $success=true;
            } catch(Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Checkout error: ".$e->getMessage()); 
                // Only show a generic message if a more specific one wasn't set by the stock check failure above.
                if (strpos($message, 'Error: Insufficient stock') === false) { 
                    $message='<div class="co-alert error"><i class="bi bi-x-circle-fill"></i> Error placing order. Please try again.</div>';
                }
            }
        }
    }
    
    // After POST failure, reload $qty and $total based on the latest $max_qty and form data if available
    // Otherwise, $qty and $total remain based on initial PHP logic/session.
    $qty = $current_qty; // Use the value submitted or corrected for display
    $total = $final_total; 
    // Re-render the page with the error message and current form values
}

// Ensure the final $qty and $total for display are correct
if ($max_qty === 0) {
    $qty = 0;
    $total = 0.0;
} else {
    // If POST failed, $qty/total already set. If page load, use the session corrected value.
    if (!isset($qty) || !isset($total)) {
        $qty = $_SESSION['buy_now_item']['qty'] ?? 1;
        $total = $qty * (float)($buy_now['price'] ?? 0);
    }
}
$disabled_attr = ($qty === 0) ? 'disabled' : '';

?>

<style>
/* ============================================
   CHECKOUT - HUMAN-CRAFTED UI/UX DESIGN
   (CSS omitted for brevity, assume it is complete and correct)
   ============================================ */

/* Add required CSS for the quantity input validation visualization */
.qty-input.over-limit {
    border-color: var(--error) !important;
    box-shadow: 0 0 0 3px var(--error-light) !important;
}

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
    --primary: #2563eb;
    --primary-dark: #1e40af;
    --primary-light: #dbeafe;
    --success: #10b981;
    --success-light: #d1fae5;
    --error: #ef4444;
    --error-light: #fee2e2;
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
    --s-05: 4px; --s-1: 8px; --s-2: 12px; --s-3: 16px; --s-4: 20px; --s-5: 24px; --s-6: 32px; --s-8: 40px; --s-10: 48px;
    --text-xs: 12px; --text-sm: 14px; --text-base: 16px; --text-lg: 18px; --text-xl: 20px; --text-2xl: 24px; --text-3xl: 28px;
    --radius-sm: 4px; --radius: 6px; --radius-lg: 8px; --radius-xl: 12px;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05); --shadow: 0 1px 3px rgba(0,0,0,0.1); --shadow-md: 0 4px 6px rgba(0,0,0,0.07); --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
}

* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; color: var(--gray-900); }
.co-page { background: var(--gray-50); min-height: 100vh; padding: var(--s-5) var(--s-3); }
.co-container { max-width: 1080px; margin: 0 auto; }
.co-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: var(--s-6); gap: var(--s-3); }
.co-breadcrumb { display: flex; align-items: center; gap: var(--s-1); font-size: var(--text-sm); color: var(--gray-500); margin-bottom: var(--s-2); }
.co-breadcrumb a { color: var(--primary); text-decoration: none; }
.co-breadcrumb a:hover { text-decoration: underline; }
.co-breadcrumb .bi { font-size: 10px; }
.co-title { font-size: var(--text-2xl); font-weight: 700; color: var(--gray-900); letter-spacing: -0.01em; }
.co-btn-back { display: inline-flex; align-items: center; gap: var(--s-1); padding: var(--s-1) var(--s-3); background: white; border: 1px solid var(--gray-300); border-radius: var(--radius); color: var(--gray-700); font-size: var(--text-sm); font-weight: 500; text-decoration: none; transition: all 0.15s; }
.co-btn-back:hover { border-color: var(--gray-400); background: var(--gray-50); }
.co-alert { padding: var(--s-3) var(--s-3); border-radius: var(--radius-lg); border-left: 3px solid; margin-bottom: var(--s-5); font-size: var(--text-sm); line-height: 1.5; }
.co-alert.error { background: var(--error-light); border-color: var(--error); color: #991b1b; }
.co-alert.success { background: var(--success-light); border-color: var(--success); color: #065f46; }
.co-alert.error strong, .co-alert.success strong { font-weight: 600; }
.co-alert i.bi { margin-right: var(--s-1); font-size: 16px; vertical-align: text-bottom; }
.co-grid { display: grid; grid-template-columns: 1fr 400px; gap: var(--s-6); align-items: start; }
.co-card { background: white; border: 1px solid var(--gray-200); border-radius: var(--radius-xl); box-shadow: var(--shadow-sm); overflow: hidden; }
.co-card-header { padding: var(--s-4); border-bottom: 1px solid var(--gray-100); background: var(--gray-50); }
.co-card-title { display: flex; align-items: center; gap: var(--s-1); font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }
.co-card-body { padding: var(--s-4); }
.co-form-group { margin-bottom: var(--s-4); }
.co-form-group:last-child { margin-bottom: 0; }
.co-label { display: block; font-size: var(--text-sm); font-weight: 500; color: var(--gray-700); margin-bottom: var(--s-1); }
.co-label-required::after { content: '*'; color: var(--error); margin-left: 2px; }
.co-input, .co-textarea { width: 100%; padding: 9px 12px; font-size: var(--text-base); font-family: inherit; color: var(--gray-900); background: white; border: 1px solid var(--gray-300); border-radius: var(--radius); outline: none; transition: all 0.15s; }
.co-input:focus, .co-textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
.co-input::placeholder, .co-textarea::placeholder { color: var(--gray-400); }
.co-textarea { min-height: 70px; resize: vertical; line-height: 1.5; }
.co-helper { font-size: var(--text-xs); color: var(--gray-500); margin-top: var(--s-05); }
.co-payment { display: flex; align-items: center; gap: var(--s-2); padding: var(--s-3); background: white; border: 2px solid var(--gray-200); border-radius: var(--radius-lg); cursor: pointer; transition: all 0.15s; }
.co-payment:hover { border-color: var(--primary); }
.co-payment.selected { border-color: var(--primary); background: var(--primary-light); }
.co-payment-radio { width: 18px; height: 18px; border: 2px solid var(--gray-300); border-radius: 50%; position: relative; flex-shrink: 0; }
.co-payment.selected .co-payment-radio { border-color: var(--primary); }
.co-payment.selected .co-payment-radio::after { content: ''; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 8px; height: 8px; background: var(--primary); border-radius: 50%; }
.co-payment-icon { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: var(--gray-100); border-radius: var(--radius); }
.co-payment-icon img { width: 24px; height: 24px; object-fit: contain; }
.co-payment-content { flex: 1; }
.co-payment-title { font-size: var(--text-base); font-weight: 600; color: var(--gray-900); margin-bottom: 2px; }
.co-payment-desc { font-size: var(--text-sm); color: var(--gray-500); }
.co-payment-info { margin-top: var(--s-3); padding: var(--s-3); background: #eff6ff; border-left: 3px solid #3b82f6; border-radius: var(--radius); }
.co-payment-info-title { font-size: var(--text-sm); font-weight: 600; color: #1e40af; display: flex; align-items: center; gap: var(--s-1); margin-bottom: var(--s-1); }
.co-payment-info-text { font-size: var(--text-sm); color: #1e40af; line-height: 1.4; }
.co-summary-items { display: flex; flex-direction: column; gap: var(--s-3); }
.co-summary-item { display: grid; grid-template-columns: 64px 1fr auto; gap: var(--s-3); align-items: center; border-bottom: 1px solid var(--gray-100); padding-bottom: var(--s-3); }
.co-summary-item:last-child { border-bottom: none; padding-bottom: 0; }
.co-summary-img { width: 64px; height: 64px; object-fit: cover; border-radius: var(--radius); border: 1px solid var(--gray-200); }
.co-summary-details { flex: 1; min-width: 0; }
.co-summary-name { font-size: var(--text-base); font-weight: 600; color: var(--gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
.co-summary-qty-price { font-size: var(--text-sm); color: var(--gray-500); }
.co-summary-subtotal { font-size: var(--text-base); font-weight: 600; color: var(--gray-900); }
.co-summary-divider { height: 1px; background: var(--gray-200); margin: var(--s-3) 0; }
.co-total { display: flex; justify-content: space-between; align-items: center; padding: var(--s-3); background: var(--gray-50); border-radius: var(--radius-lg); margin-bottom: var(--s-4); }
.co-total-label { font-size: var(--text-base); font-weight: 500; color: var(--gray-700); }
.co-total-value { font-size: var(--text-xl); font-weight: 700; color: var(--gray-900); }
.co-actions { margin-top: var(--s-6); }
.co-btn-primary { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: var(--s-1); padding: 11px var(--s-4); background: var(--primary); color: white; border: none; border-radius: var(--radius-lg); font-size: var(--text-base); font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.15s; }
.co-btn-primary:hover { background: var(--primary-dark); }
.co-btn-primary:active { transform: scale(0.98); }
.co-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.co-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(17, 24, 39, 0.7); display: flex; align-items: center; justify-content: center; z-index: 1000; opacity: 1; transition: opacity 0.2s; }
.co-modal-overlay.hidden { opacity: 0; pointer-events: none; }
.co-modal { background: white; border-radius: var(--radius-xl); max-width: 640px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg); }
.co-modal-header { display: flex; align-items: center; justify-content: space-between; padding: var(--s-4); border-bottom: 1px solid var(--gray-200); background: var(--gray-50); }
.co-modal-title { display: flex; align-items: center; gap: var(--s-1); font-size: var(--text-lg); font-weight: 600; color: var(--gray-900); }
.co-modal-close { width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; background: transparent; border: none; border-radius: var(--radius); color: var(--gray-500); cursor: pointer; transition: all 0.15s; }
.co-modal-close:hover { background: var(--gray-200); color: var(--gray-700); }
.co-modal-body { padding: var(--s-4); }
.co-modal-section { margin-bottom: var(--s-4); }
.co-modal-section:last-child { margin-bottom: 0; }
.co-modal-section-title { display: flex; align-items: center; gap: var(--s-1); font-size: var(--text-sm); font-weight: 600; color: var(--gray-700); margin-bottom: var(--s-2); padding-bottom: var(--s-1); border-bottom: 1px solid var(--gray-200); }
.co-modal-info { display: grid; gap: var(--s-2); }
.co-modal-info-row { display: grid; grid-template-columns: 90px 1fr; gap: var(--s-2); font-size: var(--text-sm); }
.co-modal-info-label { font-weight: 500; color: var(--gray-600); }
.co-modal-info-value { color: var(--gray-900); word-break: break-word; }
.co-modal-items { border: 1px solid var(--gray-200); border-radius: var(--radius-lg); overflow: hidden; }
.co-modal-item { display: grid; grid-template-columns: 44px 1fr auto; gap: var(--s-2); padding: var(--s-2); border-bottom: 1px solid var(--gray-100); align-items: center; }
.co-modal-item:last-child { border-bottom: none; }
.co-modal-item-img { width: 44px; height: 44px; border-radius: var(--radius); object-fit: cover; border: 1px solid var(--gray-200); }
.co-modal-item-name { font-size: var(--text-sm); font-weight: 500; color: var(--gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.co-modal-item-qty { font-size: var(--text-xs); color: var(--gray-500); }
.co-modal-item-price { font-size: var(--text-sm); font-weight: 600; color: var(--gray-900); }
.co-modal-footer { display: flex; align-items: center; justify-content: space-between; padding: var(--s-4); border-top: 1px solid var(--gray-200); background: var(--gray-50); gap: var(--s-3); }
.co-btn-secondary { flex: 1; padding: 10px var(--s-3); background: white; color: var(--gray-700); border: 1px solid var(--gray-300); border-radius: var(--radius-lg); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; justify-content: center; gap: var(--s-1); }
.co-btn-secondary:hover { background: var(--gray-50); border-color: var(--gray-400); }
.co-btn-confirm { flex: 2; padding: 10px var(--s-3); background: var(--success); color: white; border: none; border-radius: var(--radius-lg); font-size: var(--text-base); font-weight: 600; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; justify-content: center; gap: var(--s-1); }
.co-btn-confirm:hover { background: #059669; }
.co-success { background: white; border: 1px solid var(--gray-200); border-radius: var(--radius-xl); padding: var(--s-8) var(--s-6); text-align: center; box-shadow: var(--shadow-md); max-width: 600px; margin: var(--s-10) auto; }
.co-success-icon { width: 72px; height: 72px; margin: 0 auto var(--s-5); background: var(--success); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 36px; }
.co-success-title { font-size: var(--text-2xl); font-weight: 700; color: var(--gray-900); margin-bottom: var(--s-3); }
.co-success-order { font-size: var(--text-lg); font-weight: 600; color: var(--primary-dark); padding: var(--s-2) var(--s-4); background: var(--primary-light); border-radius: var(--radius); display: inline-block; margin-bottom: var(--s-4); }
.co-success-text { font-size: var(--text-base); color: var(--gray-700); margin-bottom: var(--s-8); line-height: 1.6; }
.co-success-actions { display: flex; justify-content: center; gap: var(--s-3); }
.co-success-actions .co-btn-primary { width: auto; min-width: 200px; }
@media (max-width: 1024px) {
    .co-grid { grid-template-columns: 1fr; }
    .co-summary { order: -1; }
}
@media (max-width: 768px) { .co-modal-footer { flex-direction: column; } }
@media (max-width: 480px) {
    .co-summary-item { grid-template-columns: 48px 1fr auto; }
    .co-summary-img { width: 48px; height: 48px; }
    .co-payment { padding: var(--s-2); }
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
                Thank you for your purchase! Your order has been confirmed and will be processed shortly. You will receive your items via Cash on Delivery (COD).
            </p>
            <div class="co-success-actions">
                <a href="/sweepxpress/Invoice.php?order_id=<?= h($order_id) ?>" class="co-btn-primary">
                    <i class="bi bi-file-earmark-text"></i> View Invoice
                </a>
                <a href="/sweepxpress/index.php" class="co-btn-primary" style="background: var(--gray-700);">
                    <i class="bi bi-house-door"></i> Continue Shopping
                </a>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<?php // Convert markdown-like syntax to HTML for the alert (e.g., **text** to <strong>text</strong>)
$message = preg_replace('/\\*\\*(.*?)\\*\\*/s', '<strong>$1</strong>', $message); ?>
<div class="co-page">
    <div class="co-container">
        <header class="co-header">
            <div>
                <nav class="co-breadcrumb">
                    <a href="/sweepxpress/index.php">Home</a>
                    <i class="bi bi-chevron-right"></i>
                    <span>Buy Now Checkout</span>
                </nav>
                <h1 class="co-title">Buy Now Checkout</h1>
            </div>
            <a href="/sweepxpress/index.php" class="co-btn-back">
                <i class="bi bi-arrow-left"></i> Back to Shop
            </a>
        </header>
        <?= $message ?>
        <?php $disabled_attr = ($qty === 0) ? 'disabled' : ''; ?>
        <form method="post" id="checkoutForm">
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <div class="co-grid">
                <div>
                    <div class="co-card">
                        <div class="co-card-header">
                            <h2 class="co-card-title">
                                <i class="bi bi-truck"></i> Shipping Details
                            </h2>
                        </div>
                        <div class="co-card-body">
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Full Name</label>
                                <input type="text" name="name" class="co-input" required value="<?= h($_POST['name'] ?? ($_SESSION['user']['name'] ?? '')) ?>" pattern="^[A-Za-z\s.'-]+$" placeholder="Juan Dela Cruz" <?= $disabled_attr ?>>
                                <div class="co-helper">Enter your full legal name</div>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Phone Number</label>
                                <input type="tel" name="contact_number" class="co-input" required value="<?= h($_POST['contact_number'] ?? ($_SESSION['user']['phone_number'] ?? '')) ?>" pattern="^\+?\d[\d\s\(\)-]{6,20}\d$" placeholder="e.g., 09171234567" <?= $disabled_attr ?>>
                                <div class="co-helper">We'll use this to contact you for delivery.</div>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Street Address (House #, Street, Bldg)</label>
                                <input type="text" name="street_address" class="co-input" required value="<?= h($_POST['street_address'] ?? '') ?>" placeholder="e.g., Blk 1 Lot 2, Sampaguita St." <?= $disabled_attr ?>>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Barangay</label>
                                <input type="text" name="barangay" class="co-input" required value="<?= h($_POST['barangay'] ?? '') ?>" placeholder="e.g., San Roque" <?= $disabled_attr ?>>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label co-label-required">City / Municipality</label>
                                <input type="text" name="city" class="co-input" required value="<?= h($_POST['city'] ?? '') ?>" placeholder="e.g., Quezon City" <?= $disabled_attr ?>>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label co-label-required">Province</label>
                                <input type="text" name="province" class="co-input" required value="<?= h($_POST['province'] ?? '') ?>" placeholder="e.g., Metro Manila" <?= $disabled_attr ?>>
                            </div>
                            <div class="co-form-group">
                                <label class="co-label">Order Notes (Optional)</label>
                                <textarea name="notes" class="co-textarea" placeholder="e.g., Leave with the guard, preferred delivery time, etc." <?= $disabled_attr ?>><?= h($_POST['notes'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="co-card" style="margin-top: var(--s-6);">
                        <div class="co-card-header">
                            <h2 class="co-card-title">
                                <i class="bi bi-wallet2"></i> Payment Method
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
                                    Please prepare the <strong>exact amount of ₱<span id="instructionTotal"><?= number_format($total, 2) ?></span></strong> in cash when your order is delivered. Our delivery personnel will collect the payment upon successful delivery.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="co-summary">
                    <div class="co-card">
                        <div class="co-card-header">
                            <h2 class="co-card-title">
                                <i class="bi bi-receipt"></i> Order Summary
                            </h2>
                        </div>
                        <div class="co-card-body">
                            <div class="co-summary-items">
                                <?php 
                                $imgPath = $buy_now['image'] ?? $buy_now['image_path'] ?? 'assets/placeholder.jpg';
                                $pid = (int)$buy_now['id'];
                                $price_display = number_format((float)($buy_now['price'] ?? 0), 2);
                                $total_display = number_format($total, 2);
                                ?>
                                <div class="co-summary-item" data-product-id="<?= $pid ?>">
                                    <img src="<?= h($imgPath) ?>" alt="<?= h($buy_now['name']) ?>" class="co-summary-img" onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                                    <div class="co-summary-details">
                                        <div class="co-summary-name"><?= h($buy_now['name']) ?></div>
                                        <div class="co-qty-wrapper">
                                            <label for="qty_<?= $pid ?>" class="co-label" style="font-size: var(--text-sm); margin-bottom: 2px; font-weight: 500; display: block;">Quantity (Max: <?= $max_qty ?>):</label>
                                            <input type="number" class="co-input qty-input" id="qty_<?= $pid ?>" name="qty[<?= $pid ?>]" value="<?= $qty ?>" min="<?= ($max_qty > 0) ? 1 : 0 ?>" max="<?= $max_qty ?>" data-max-qty="<?= $max_qty ?>" data-product-name="<?= h($buy_now['name']); ?>" data-price="<?= h($buy_now['price']); ?>" style="width: 80px; padding: 4px 8px; font-size: var(--text-sm);" <?= $disabled_attr ?>>
                                        </div>
                                    </div>
                                    <div class="co-summary-subtotal">₱<span id="itemSubtotal"><?= $total_display ?></span></div>
                                </div>
                            </div>

                            <div class="co-summary-divider"></div>

                            <div class="co-summary-row" style="display: flex; justify-content: space-between; font-size: var(--text-base); color: var(--gray-700); margin-bottom: var(--s-2);">
                                <div>Subtotal (<span id="totalUnits"><?= h($qty) ?></span> Unit(s))</div>
                                <div>₱<span id="subtotalValue"><?= $total_display ?></span></div>
                            </div>
                            <div class="co-summary-row" style="display: flex; justify-content: space-between; font-size: var(--text-base); color: var(--gray-700); margin-bottom: var(--s-3);">
                                <div>Shipping Fee</div>
                                <div>₱0.00</div>
                            </div>

                            <div class="co-total" style="margin-bottom: 0; padding: var(--s-4);">
                                <div class="co-total-label">ORDER TOTAL</div>
                                <div class="co-total-value">₱<span id="totalValue"><?= $total_display ?></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="co-actions">
                        <button type="button" class="co-btn-primary" id="placeOrderBtn" <?= $disabled_attr ?>>
                            <i class="bi bi-shield-check"></i> Place Order (₱<span id="finalTotalBtn"><?= $total_display ?></span>)
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="co-modal-overlay hidden" id="confirmModal">
            <div class="co-modal">
                <header class="co-modal-header">
                    <h3 class="co-modal-title"><i class="bi bi-question-circle"></i> Confirm Your Order</h3>
                    <button type="button" class="co-modal-close" id="closeModalBtn">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>
                <div class="co-modal-body">
                    <p style="margin-bottom: var(--s-3); font-size: var(--text-base); color: var(--gray-700);">
                        Please review your order details before placing your final order.
                    </p>
                    <div class="co-modal-section">
                        <div class="co-modal-section-title"><i class="bi bi-person-circle"></i> Customer Details</div>
                        <div class="co-modal-info">
                            <div class="co-modal-info-row">
                                <div class="co-modal-info-label">Name:</div>
                                <div class="co-modal-info-value" id="modalName"></div>
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
                                <div class="co-modal-info-label">Payment:</div>
                                <div class="co-modal-info-value">Cash on Delivery (COD)</div>
                            </div>
                            <div class="co-modal-info-row">
                                <div class="co-modal-info-label">Notes:</div>
                                <div class="co-modal-info-value" id="modalNotes"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="co-modal-section">
                        <div class="co-modal-section-title"><i class="bi bi-cart-check"></i> Order Summary (1 Item)</div>
                        <div class="co-modal-items">
                            <?php
                                $modal_item = $buy_now;
                                $imgPath = $modal_item['image'] ?? $modal_item['image_path'] ?? 'assets/placeholder.jpg';
                                $name = $modal_item['name'] ?? 'Product';
                                $price_modal = (float)($modal_item['price'] ?? 0);
                            ?>
                            <div class="co-modal-item">
                                <img src="<?= h($imgPath) ?>" alt="<?= h($name) ?>" class="co-modal-item-img" onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                                <div>
                                    <div class="co-modal-item-name"><?= h($name) ?></div>
                                    <div class="co-modal-item-qty">Qty: <span id="modalQtyDisplay"><?= h($qty) ?></span> x ₱<?= number_format($price_modal, 2) ?></div>
                                </div>
                                <div class="co-modal-item-price">₱<span id="modalItemPriceDisplay"><?= $total_display ?></span></div> 
                            </div>
                        </div>
                        <div class="co-summary-row co-total" style="background: var(--gray-100); margin-top: var(--s-3);">
                            <div class="co-total-label">Subtotal</div>
                            <div class="co-total-value">₱<span id="modalSubtotal"><?= $total_display ?></span></div>
                        </div>
                        <div class="co-summary-row co-total" style="margin-top: 0; padding: var(--s-3);">
                            <div class="co-total-label" style="font-size: var(--text-lg); font-weight: 700;">Order Total</div>
                            <div class="co-total-value" style="font-size: var(--text-2xl);">₱<span id="modalTotal"><?= $total_display ?></span></div>
                        </div>
                    </div>
                    
                    <div class="co-alert error" style="margin-top: var(--s-4); border-left: 3px solid var(--error); background: var(--error-light);">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        By clicking "Yes, Place Order", you are confirming your purchase and agreeing to pay **₱<span id="alertTotal"><?= $total_display ?></span>** upon delivery (Cash on Delivery). This action cannot be easily reversed.
                    </div>
                </div>
                <footer class="co-modal-footer">
                    <button type="button" class="co-btn-secondary" id="cancelOrderBtn">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="button" class="co-btn-confirm" id="confirmOrderBtn">
                        <i class="bi bi-check-circle"></i> Yes, Place Order
                    </button>
                </footer>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
(function() {
    'use strict';
    
    // --- ELEMENT REFERENCES ---
    const form = document.getElementById('checkoutForm');
    const placeOrderBtn = document.getElementById('placeOrderBtn');
    const confirmModal = document.getElementById('confirmModal');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelOrderBtn = document.getElementById('cancelOrderBtn');
    const confirmOrderBtn = document.getElementById('confirmOrderBtn');
    const qtyInput = document.querySelector('.qty-input');
    const alertContainer = document.querySelector('.co-container');

    const itemSubtotal = document.getElementById('itemSubtotal');
    const subtotalValue = document.getElementById('subtotalValue');
    const totalUnits = document.getElementById('totalUnits');
    const totalValue = document.getElementById('totalValue');
    const finalTotalBtn = document.getElementById('finalTotalBtn');
    const instructionTotal = document.getElementById('instructionTotal');
    const modalQtyDisplay = document.getElementById('modalQtyDisplay');
    const modalItemPriceDisplay = document.getElementById('modalItemPriceDisplay');
    const alertTotal = document.getElementById('alertTotal');
    
    // --- CONSTANTS ---
    const maxStock = parseInt(qtyInput ? qtyInput.dataset.maxQty : '0') || 0;
    const itemPrice = parseFloat(qtyInput ? qtyInput.dataset.price : '0.00') || 0.00;
    const productName = qtyInput ? qtyInput.dataset.productName : 'Item';
    const shippingFee = 0.00; 

    // --- HELPER FUNCTIONS ---

    /** Helper function to display an immediate, in-page notification. */
    function displayClientNotification(message) {
        // Remove temporary alerts, keep persistent ones (like OOS from PHP)
        document.querySelectorAll('#temp-client-alert').forEach(el => el.remove()); 
        
        const alertDiv = document.createElement('div');
        alertDiv.className = 'co-alert error';
        alertDiv.id = 'temp-client-alert'; // Use a temporary ID
        alertDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${message}`;
        
        // Find the element after which the alert should be placed (e.g., the header)
        const header = document.querySelector('.co-header');
        if (header && alertContainer) {
            header.insertAdjacentElement('afterend', alertDiv);
        } else {
            console.warn("Alert container/header not found.");
        }
    }

    function setVisualFeedback(isOverLimit) {
        if (!qtyInput || !placeOrderBtn) return;
        
        if (isOverLimit) {
            qtyInput.classList.add('over-limit');
            // Disable button if invalid quantity OR if OOS (maxStock is 0)
            placeOrderBtn.disabled = true; 
            // Disable other form inputs if OOS (maxStock is 0)
            if (maxStock === 0) {
                 form.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(el => el.disabled = true);
            }
        } else {
            qtyInput.classList.remove('over-limit');
            document.querySelectorAll("#temp-client-alert").forEach(el => el.remove());
            // Only enable if maxStock > 0 and current Qty > 0
            placeOrderBtn.disabled = (maxStock === 0 || parseInt(qtyInput.value) === 0);
             if (maxStock > 0) {
                 form.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(el => el.disabled = false);
             }
        }
    }

    function formatCurrency(number) {
        if (isNaN(number) || !isFinite(number)) return "0.00";
        return number.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function updateTotals() {
        if (!qtyInput) return;

        const qty = parseInt(qtyInput.value) || 0;
        const subtotal = qty * itemPrice;
        const finalTotal = subtotal + shippingFee;

        // Update all display elements
        const totalDisplay = formatCurrency(finalTotal);
        
        if (itemSubtotal) itemSubtotal.textContent = formatCurrency(subtotal);
        if (modalItemPriceDisplay) modalItemPriceDisplay.textContent = formatCurrency(subtotal);
        if (subtotalValue) subtotalValue.textContent = formatCurrency(subtotal);
        if (totalUnits) totalUnits.textContent = qty;
        if (totalValue) totalValue.textContent = totalDisplay;
        if (finalTotalBtn) finalTotalBtn.textContent = totalDisplay;
        if (instructionTotal) instructionTotal.textContent = totalDisplay;
        if (alertTotal) alertTotal.textContent = totalDisplay;
        if (modalQtyDisplay) modalQtyDisplay.textContent = qty;

        // Update modal totals
        if (document.getElementById('modalSubtotal')) document.getElementById('modalSubtotal').textContent = formatCurrency(subtotal);
        if (document.getElementById('modalTotal')) document.getElementById('modalTotal').textContent = totalDisplay;
    }

    function validateAndLimitInput(input) {
        if (!input) return;
        
        let requestedValue = parseInt(input.value) || 0;
        
        // 1. OOS check
        if (maxStock === 0) {
            input.value = 0;
            setVisualFeedback(true); 
            // Display message only if no PHP message is present
            if (!document.getElementById('persistent-alert')) {
                displayClientNotification(`⛔ Cannot place order. **${productName}** is **Out of Stock**.`);
            }
            updateTotals();
            return;
        }
        
        // 2. Max Stock limit check
        if (requestedValue > maxStock) {
            setVisualFeedback(true);
            displayClientNotification(
                `⚠️ Ang requested na dami (${requestedValue}) ay mas mataas sa available stock (**${maxStock}**). Paki-ayunan ang dami sa ${maxStock} bago mag-place order.`
            );
        } 
        // 3. Minimum quantity check (if stock > 0)
        else if (requestedValue < 1) {
            input.value = 1;
            setVisualFeedback(false); // Valid quantity now
        }
        // 4. Valid quantity
        else {
            setVisualFeedback(false);
        }
        
        updateTotals();
    }


    // --- EVENT LISTENERS (Imitating checkout.php's "Place Order" function) ---
    
    // 1. PLACE ORDER BUTTON CLICK
    if (placeOrderBtn) {
        placeOrderBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Check HTML5 validity for required fields
            if (!form.reportValidity()) {
                return;
            }
            
            // Re-check quantity limit before proceeding (in case of race condition or rapid input)
            const currentQty = parseInt(qtyInput.value) || 0;
            if (currentQty > maxStock) {
                displayClientNotification(`⛔ Cannot place order. **${productName}** only has **${maxStock}** in stock.`);
                qtyInput.focus();
                setVisualFeedback(true);
                return;
            } else if (currentQty === 0) {
                displayClientNotification(`⛔ Cannot place order. Quantity must be at least 1.`);
                qtyInput.focus();
                setVisualFeedback(true);
                return;
            }

            // 2. Gather data and populate modal elements
            // Use fallback '' for empty/missing inputs to avoid errors
            const name = form.querySelector('input[name="name"]').value.trim() || '';
            const phone = form.querySelector('input[name="contact_number"]').value.trim() || '';
            const street = form.querySelector('input[name="street_address"]').value.trim() || '';
            const barangay = form.querySelector('input[name="barangay"]').value.trim() || '';
            const city = form.querySelector('input[name="city"]').value.trim() || '';
            const province = form.querySelector('input[name="province"]').value.trim() || '';
            const notes = form.querySelector('textarea[name="notes"]').value.trim() || '';
            
            // Populate modal elements
            if (document.getElementById('modalName')) document.getElementById('modalName').textContent = name;
            if (document.getElementById('modalPhone')) document.getElementById('modalPhone').textContent = phone;
            if (document.getElementById('modalAddress')) document.getElementById('modalAddress').textContent = `${street}, ${barangay}, ${city}, ${province}`;
            if (document.getElementById('modalNotes')) document.getElementById('modalNotes').textContent = notes || 'None';

            // 3. Update totals in modal
            updateTotals(); 

            // 4. Show modal
            if (confirmModal) {
                confirmModal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        });
    }

    // 2. MODAL CONTROLS
    function closeModal() {
        if (confirmModal) confirmModal.classList.add('hidden');
        document.body.style.overflow = '';
        if (confirmOrderBtn) {
            confirmOrderBtn.disabled = false;
            confirmOrderBtn.innerHTML = '<i class="bi bi-check-circle"></i> Yes, Place Order';
        }
    }
    
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelOrderBtn) cancelOrderBtn.addEventListener('click', closeModal);
    
    // 3. CONFIRM ORDER BUTTON CLICK (Final Form Submission)
    if (confirmOrderBtn) {
        confirmOrderBtn.addEventListener('click', function() {
            // Re-check stock immediately before submitting to minimize race conditions
             const currentQty = parseInt(qtyInput.value) || 0;
             if (currentQty > maxStock || currentQty === 0) {
                 // Close modal and let the user correct the form
                 closeModal(); 
                 displayClientNotification(`⛔ Critical Error: Stock/Quantity mismatch detected. Please correct the quantity to a value between 1 and ${maxStock}.`);
                 return;
             }
            
            confirmOrderBtn.disabled = true;
            confirmOrderBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
            // Final submission
            form.submit(); 
        });
    }
    
    // Close modal when clicking outside
    if (confirmModal) {
        confirmModal.addEventListener('click', function(e) {
            if (e.target === confirmModal) {
                closeModal();
            }
        });
    }
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && confirmModal && !confirmModal.classList.contains('hidden')) {
            closeModal();
        }
    });

    // --- QUANTITY INPUT HANDLER ---
    let typingTimer;
    const typingDelay = 700;
    
    if (qtyInput) {
        qtyInput.addEventListener("input", () => {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(() => {
                validateAndLimitInput(qtyInput);
            }, typingDelay);
        });

        qtyInput.addEventListener("change", () => {
            clearTimeout(typingTimer);
            validateAndLimitInput(qtyInput);
        });
        
        // Fix for Android/iOS virtual keyboard submit/enter issue
        qtyInput.addEventListener("blur", () => {
            clearTimeout(typingTimer);
            validateAndLimitInput(qtyInput);
        });
    }
    
    // --- INITIALIZATION ---
    updateTotals(); // Initial total calculation on load
    // Initial validation to set correct button and input state based on PHP's initial $qty (which is corrected against $max_qty)
    validateAndLimitInput(qtyInput); 
    
    // Final check for overall form disability on page load due to OOS
    if (maxStock === 0) {
        setVisualFeedback(true);
    }
    

})();
</script>