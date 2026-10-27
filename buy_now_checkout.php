<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php'; // login required (gaya ng checkout.php)
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
    echo '<div class="sx-container sx-section"><div class="sx-card sx-empty">
            <i class="fa-solid fa-bolt"></i>
            <h3>No Buy Now item selected</h3>
            <p style="margin: 0 0 var(--sx-space-4)">Choose a product and tap “Buy Now” to purchase it directly.</p>
            <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-dark">Start shopping</a>
          </div></div>';
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
    $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>This item is <strong>out of stock</strong>. The order cannot be placed.</div></div>';
} elseif ($qty !== $old_qty) {
    // If the old quantity (requested) was higher than stock, update session to max available
    $_SESSION['buy_now_item']['qty'] = $qty; 
    $message = '<div class="sx-alert sx-alert-warning" role="status"><i class="fa-solid fa-triangle-exclamation"></i><div>The requested quantity (<strong>'.$old_qty.'</strong>) exceeded the available stock. Quantity has been adjusted to <strong>'.$qty.'</strong>.</div></div>';
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
    $current_qty = $qty;     // default kapag pumalya ang CSRF (dati: Undefined variable)
    $final_total = $total;

    // CSRF
    $sent_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $sent_csrf)) {
        $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Invalid session. Refresh and try again.</div></div>';
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
        $payment_method = 'COD'; // COD lang ang suportado (dati: kung ano ang ipinadala ng form)
        $address = "$street, $barangay, $city, $province";
        
        // Re-calculate total based on validated current_qty
        $final_total = $current_qty * (float)($buy_now['price'] ?? 0);


        // Basic validation
        if ($name=='' || $street=='' || $barangay=='' || $city=='' || $province=='' || $contact_number=='') {
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Please fill in all required fields (name, address, phone number).</div></div>';
        }
        elseif (!preg_match('/^[A-Za-z\s.\'-]+$/', $name)) {
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Full Name can only contain letters, spaces, dots, apostrophes, and hyphens.</div></div>';
        } elseif (!preg_match('/^\+?\d[\d\s\(\)-]{6,20}\d$/', $contact_number)) { 
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Please enter a valid phone number.</div></div>';
        }
        // FINAL CHECK for stock before transaction
        else if ($current_qty < 1 || $current_qty > $max_qty) { 
             $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Sorry, the item is out of stock or the quantity requested is invalid. Available stock: '.$max_qty.'.</div></div>';
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

                    $message='<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Error: Insufficient stock. Please reload the page. Current available: '.$max_qty.'.</div></div>';
                    throw new Exception("Stock insufficiency detected during transaction.");
                }

                $pdo->commit();

                // =======================================
                // SEND EMAIL AFTER SUCCESSFUL CHECKOUT
                // =======================================

                $customer_name  = $name; 
                // Email galing lang sa session — hindi puwedeng pumili ng ibang padadalhan
                $customer_email = $_SESSION['user']['email'] ?? '';
                $safe_name      = h($customer_name);

                if ($customer_email) {
                    $subject = "Your Order #$order_id Has Been Placed!";
                    $body = "
                        <h2>Thank you for your order!</h2>
                        <p>Hello <b>$safe_name</b>,</p>
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
                    $message='<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Error placing order. Please try again.</div></div>';
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

// Header pagkatapos ng lahat ng pagproseso (dati: hindi na-include, kaya walang navbar at style.css)
require_once __DIR__ . '/includes/header.php';
?>

<?php
// Para sa display (styles: .co-* sa assets/style.css, kapareho ng checkout.php)
$pid       = (int)$buy_now['id'];
$unitPrice = (float)($buy_now['price'] ?? 0);
$imgPath   = !empty($buy_now['image_path']) ? $buy_now['image_path'] : '/sweepxpress/assets/placeholder.jpg';
?>
<div class="co sx-container">
<?php if ($success): ?>
    <div class="sx-card co-success">
        <div class="co-success-icon"><i class="fa-solid fa-check"></i></div>
        <h1 class="sx-heading-lg">Order placed!</h1>
        <div class="co-success-id">Order #<?= h($order_id) ?></div>
        <p>Thank you for your purchase. We'll prepare your order and send status updates to your email. Please prepare <strong>₱<?= number_format($final_total, 2) ?></strong> in cash on delivery.</p>
        <div class="co-success-actions">
            <a href="/sweepxpress/customers/order_details.php?id=<?= (int)$order_id ?>" class="sx-btn sx-btn-dark sx-btn-arrow">
                View order <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a href="/sweepxpress/Invoice.php?order_id=<?= (int)$order_id ?>" class="sx-btn sx-btn-outline"><i class="fa-solid fa-file-invoice"></i> Invoice</a>
            <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-ghost">Continue shopping</a>
        </div>
    </div>

<?php else: ?>
    <div class="co-head">
        <div>
            <ol class="co-steps">
                <li class="done"><a href="/sweepxpress/product.php?id=<?= $pid ?>"><span class="dot"><i class="fa-solid fa-check"></i></span> Product</a></li>
                <li class="current"><span class="dot">2</span> Details</li>
                <li><span class="dot">3</span> Done</li>
            </ol>
            <h1 class="sx-heading-lg">Buy Now</h1>
        </div>
        <a href="/sweepxpress/product.php?id=<?= $pid ?>" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to product</a>
    </div>

    <?php if ($message): ?>
        <div style="margin-bottom: var(--sx-space-5)"><?= $message ?></div>
    <?php endif; ?>

    <form method="post" id="checkoutForm">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

        <div class="co-layout">
            <div class="co-main">
                <!-- 1. Delivery details -->
                <section class="sx-card">
                    <h2 class="co-card-title"><span class="num">1</span> Delivery details</h2>

                    <div class="co-email-note">
                        <i class="fa-solid fa-envelope"></i>
                        <span>Order updates will be sent to <strong><?= h($_SESSION['user']['email'] ?? '') ?></strong></span>
                    </div>

                    <div class="co-row">
                        <div class="sx-field">
                            <label class="sx-label co-req" for="bn-name">Full name</label>
                            <input type="text" id="bn-name" name="name" class="sx-input" required
                                   value="<?= h($_POST['name'] ?? ($_SESSION['user']['name'] ?? '')) ?>"
                                   pattern="^[A-Za-z\s.'-]+$" autocomplete="name" placeholder="Juan Dela Cruz" <?= $disabled_attr ?>>
                            <span class="sx-hint">Letters, spaces, dots, apostrophes and hyphens only.</span>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label co-req" for="bn-phone">Phone number</label>
                            <input type="tel" id="bn-phone" name="contact_number" class="sx-input" required
                                   value="<?= h($_POST['contact_number'] ?? ($_SESSION['user']['phone'] ?? '')) ?>"
                                   pattern="^\+?\d[\d\s\(\)-]{6,20}\d$" autocomplete="tel" placeholder="09171234567" <?= $disabled_attr ?>>
                            <span class="sx-hint">For delivery coordination.</span>
                        </div>
                    </div>

                    <div class="sx-field">
                        <label class="sx-label co-req" for="bn-street">House/unit number &amp; street</label>
                        <input type="text" id="bn-street" name="street_address" class="sx-input" required
                               value="<?= h($_POST['street_address'] ?? '') ?>" autocomplete="address-line1" placeholder="Blk 1 Lot 2, Sampaguita St." <?= $disabled_attr ?>>
                    </div>

                    <div class="co-row">
                        <div class="sx-field">
                            <label class="sx-label co-req" for="bn-barangay">Barangay</label>
                            <input type="text" id="bn-barangay" name="barangay" class="sx-input" required
                                   value="<?= h($_POST['barangay'] ?? '') ?>" placeholder="Barangay name" <?= $disabled_attr ?>>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label co-req" for="bn-city">City / municipality</label>
                            <input type="text" id="bn-city" name="city" class="sx-input" required
                                   value="<?= h($_POST['city'] ?? '') ?>" autocomplete="address-level2" placeholder="City name" <?= $disabled_attr ?>>
                        </div>
                    </div>

                    <div class="sx-field">
                        <label class="sx-label co-req" for="bn-province">Province</label>
                        <input type="text" id="bn-province" name="province" class="sx-input" required
                               value="<?= h($_POST['province'] ?? '') ?>" autocomplete="address-level1" placeholder="Province name" <?= $disabled_attr ?>>
                    </div>

                    <div class="sx-field" style="margin-bottom: 0">
                        <label class="sx-label" for="bn-notes">Delivery notes (optional)</label>
                        <textarea id="bn-notes" name="notes" class="sx-textarea" rows="3"
                                  placeholder="Leave with the guard, preferred delivery time, etc." <?= $disabled_attr ?>><?= h($_POST['notes'] ?? '') ?></textarea>
                    </div>
                </section>

                <!-- 2. Payment -->
                <section class="sx-card">
                    <h2 class="co-card-title"><span class="num">2</span> Payment</h2>
                    <label class="co-pay">
                        <input type="radio" name="payment_method" value="COD" checked required hidden>
                        <img src="/sweepxpress/assets/Cash.png" alt="">
                        <div>
                            <strong>Cash on delivery</strong>
                            <span>Pay with cash when your order arrives.</span>
                        </div>
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    </label>
                    <p class="co-pay-note">Please prepare the exact amount of <strong>₱<span data-total><?= number_format($total, 2) ?></span></strong>. Payment is collected upon delivery.</p>
                </section>
            </div>

            <!-- Summary -->
            <aside class="sx-card co-summary">
                <h2>Order summary</h2>
                <div class="co-items">
                    <div class="co-item">
                        <div class="co-item-img">
                            <img src="<?= h($imgPath) ?>" alt="" onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                        </div>
                        <div>
                            <div class="co-item-name"><?= h($buy_now['name'] ?? 'Product') ?></div>
                            <div class="co-item-unit">₱<?= number_format($unitPrice, 2) ?> each · <?= $max_qty ?> in stock</div>
                        </div>
                        <div class="co-item-price">₱<span data-total><?= number_format($total, 2) ?></span></div>
                    </div>
                </div>

                <div class="sx-field" style="margin-bottom: var(--sx-space-4)">
                    <label class="sx-label" for="qty_<?= $pid ?>">Quantity</label>
                    <div class="sx-qty" id="bnQty">
                        <button type="button" data-step="-1" aria-label="Decrease quantity" <?= $disabled_attr ?>><i class="fa-solid fa-minus"></i></button>
                        <input type="number" class="qty-input" id="qty_<?= $pid ?>" name="qty[<?= $pid ?>]"
                               value="<?= (int)$qty ?>" min="<?= ($max_qty > 0) ? 1 : 0 ?>" max="<?= $max_qty ?>"
                               data-max-qty="<?= $max_qty ?>" data-price="<?= h($unitPrice) ?>" data-product-name="<?= h($buy_now['name'] ?? 'This item') ?>" <?= $disabled_attr ?>>
                        <button type="button" data-step="1" aria-label="Increase quantity" <?= $disabled_attr ?>><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>

                <div class="co-line"><span>Subtotal (<span data-units><?= (int)$qty ?></span> unit<span data-plural><?= (int)$qty === 1 ? '' : 's' ?></span>)</span><strong>₱<span data-total><?= number_format($total, 2) ?></span></strong></div>
                <div class="co-line"><span>Delivery fee</span><strong>None</strong></div>
                <div class="co-total"><span>Total</span><strong>₱<span data-total><?= number_format($total, 2) ?></span></strong></div>

                <button type="button" id="placeOrderBtn" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block sx-btn-arrow" <?= $disabled_attr ?>>
                    Place order <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </button>
                <p class="co-secure"><i class="fa-solid fa-lock"></i> You'll review your order before it's placed.</p>
            </aside>
        </div>
    </form>

    <!-- Confirmation -->
    <dialog class="co-dialog" id="confirmModal" aria-labelledby="confirmTitle">
        <div class="co-dialog-head">
            <h3 id="confirmTitle">Confirm your order</h3>
            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" id="closeModalBtn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="co-dialog-body">
            <dl class="co-dl">
                <dt>Name</dt><dd id="modalName"></dd>
                <dt>Phone</dt><dd id="modalPhone"></dd>
                <dt>Address</dt><dd id="modalAddress"></dd>
                <dt>Notes</dt><dd id="modalNotes"></dd>
                <dt>Payment</dt><dd>Cash on delivery</dd>
                <dt>Item</dt><dd><?= h($buy_now['name'] ?? 'Product') ?> × <span data-units><?= (int)$qty ?></span></dd>
            </dl>
            <div class="co-total" style="margin-bottom: 0"><span>Total</span><strong>₱<span data-total><?= number_format($total, 2) ?></span></strong></div>
        </div>
        <div class="co-dialog-foot">
            <button type="button" class="sx-btn sx-btn-outline" id="cancelOrderBtn">Cancel</button>
            <button type="button" class="sx-btn sx-btn-primary" id="confirmOrderBtn"><i class="fa-solid fa-check"></i> Yes, place order</button>
        </div>
    </dialog>

    <script>
    (function () {
        'use strict';

        const form = document.getElementById('checkoutForm');
        const dialog = document.getElementById('confirmModal');
        const confirmBtn = document.getElementById('confirmOrderBtn');
        const placeBtn = document.getElementById('placeOrderBtn');
        const qtyBox = document.getElementById('bnQty');
        const qtyInput = qtyBox.querySelector('input');
        const max = parseInt(qtyInput.dataset.maxQty, 10) || 0;
        const price = parseFloat(qtyInput.dataset.price) || 0;
        const peso = (n) => n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const val = (sel) => form.querySelector(sel).value.trim();

        // I-clamp ang dami sa 1..stock at i-update ang lahat ng total sa page (server pa rin ang huling nagve-verify)
        function setQty(n, warn) {
            const clamped = Math.min(Math.max(n, max > 0 ? 1 : 0), max);
            if (warn && n > max) sxToast(`${qtyInput.dataset.productName}: only ${max} in stock`, 'error');
            qtyInput.value = clamped;
            qtyBox.classList.remove('over-limit');
            document.querySelectorAll('[data-total]').forEach(el => { el.textContent = peso(clamped * price); });
            document.querySelectorAll('[data-units]').forEach(el => { el.textContent = clamped; });
            document.querySelectorAll('[data-plural]').forEach(el => { el.textContent = clamped === 1 ? '' : 's'; });
        }

        qtyBox.querySelectorAll('button[data-step]').forEach(btn => {
            btn.addEventListener('click', () => {
                setQty((parseInt(qtyInput.value, 10) || 0) + parseInt(btn.dataset.step, 10), true);
            });
        });
        qtyInput.addEventListener('input', () => {
            qtyBox.classList.toggle('over-limit', (parseInt(qtyInput.value, 10) || 0) > max);
        });
        qtyInput.addEventListener('change', () => setQty(parseInt(qtyInput.value, 10) || 0, true));

        placeBtn.addEventListener('click', function () {
            setQty(parseInt(qtyInput.value, 10) || 0, true);
            if (max === 0) return;
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            document.getElementById('modalName').textContent = val('[name="name"]');
            document.getElementById('modalPhone').textContent = val('[name="contact_number"]');
            document.getElementById('modalAddress').textContent =
                `${val('[name="street_address"]')}, ${val('[name="barangay"]')}, ${val('[name="city"]')}, ${val('[name="province"]')}`;
            document.getElementById('modalNotes').textContent = val('[name="notes"]') || 'None';
            dialog.showModal();
        });

        document.getElementById('closeModalBtn').addEventListener('click', () => dialog.close());
        document.getElementById('cancelOrderBtn').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });

        confirmBtn.addEventListener('click', function () {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Placing order...';
            form.submit();
        });
    })();
    </script>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
