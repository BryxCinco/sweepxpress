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
    echo '<div class="sx-container sx-section"><div class="sx-card sx-empty">
            <i class="fa-solid fa-cart-shopping"></i>
            <h3>Your cart is empty</h3>
            <p style="margin: 0 0 var(--sx-space-4)">Add some products to proceed to checkout.</p>
            <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-dark">Start shopping</a>
          </div></div>';
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
    echo '<div class="sx-container sx-section"><div class="sx-alert sx-alert-error"><i class="fa-solid fa-circle-exclamation"></i><div>Could not load cart items. Please try again later.</div></div></div>';
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
        $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Invalid session. Please refresh the page and try again.</div></div>';
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
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Please fill in all required fields.</div></div>';
        } elseif (!preg_match('/^[A-Za-z\s.\'-]+$/', $name)) {
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Full Name can only contain letters, spaces, dots, apostrophes, and hyphens.</div></div>';
        } elseif (!preg_match('/^\+?\d[\d\s\(\)-]{6,20}\d$/', $phone_number)) { 
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Please enter a valid phone number.</div></div>';
        } elseif (empty($items)) {
            $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>Your cart items are invalid. Please try again.</div></div>';
        } else {
            $payment_status = 'Pending';
            
            try {
                $pdo->beginTransaction();

                // STOCK CHECK: i-lock ang inventory rows (FOR UPDATE) at siguraduhing sapat pa ang stock
                // bago gumawa ng order. Dati, "max(0, stock - qty)" lang, kaya puwedeng mag-oversell.
                $getInventory = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = 1 FOR UPDATE");
                $shortages = [];
                foreach ($items as $it) {
                    $getInventory->execute([(int)$it['p']['id']]);
                    $inv = $getInventory->fetch(PDO::FETCH_ASSOC);
                    $getInventory->closeCursor();
                    $available = $inv ? (int)$inv['quantity'] : 0;
                    if ($available < (int)$it['qty']) {
                        $shortages[] = h($it['p']['name']) . ' (only ' . $available . ' left)';
                    }
                }
                if ($shortages) {
                    $pdo->rollBack();
                    $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>'
                             . 'Some items no longer have enough stock: ' . implode(', ', $shortages)
                             . '. Please <a href="/sweepxpress/cart.php">update your cart</a> and try again.</div></div>';
                } else {
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
                } // end STOCK CHECK
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Checkout error: " . $e->getMessage());
                $message = '<div class="sx-alert sx-alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>An error occurred while placing your order. Please try again.</div></div>';
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>


<div class="co sx-container">
<?php if ($success): ?>
    <div class="sx-card co-success">
        <div class="co-success-icon"><i class="fa-solid fa-check"></i></div>
        <h1 class="sx-heading-lg">Order placed!</h1>
        <div class="co-success-id">Order #<?= h($order_id) ?></div>
        <p>Thank you for your purchase. We'll prepare your order and send status updates to your email. Please prepare <strong>₱<?= number_format($total, 2) ?></strong> in cash on delivery.</p>
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
                <li class="done"><a href="/sweepxpress/cart.php"><span class="dot"><i class="fa-solid fa-check"></i></span> Cart</a></li>
                <li class="current"><span class="dot">2</span> Details</li>
                <li><span class="dot">3</span> Done</li>
            </ol>
            <h1 class="sx-heading-lg">Checkout</h1>
        </div>
        <a href="/sweepxpress/cart.php" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to cart</a>
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
                            <label class="sx-label co-req" for="co-name">Full name</label>
                            <input type="text" id="co-name" name="name" class="sx-input" required
                                   value="<?= h($_POST['name'] ?? ($_SESSION['user']['name'] ?? '')) ?>"
                                   pattern="^[A-Za-z\s.'-]+$" autocomplete="name" placeholder="Juan Dela Cruz">
                            <span class="sx-hint">Letters, spaces, dots, apostrophes and hyphens only.</span>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label co-req" for="co-phone">Phone number</label>
                            <input type="tel" id="co-phone" name="phone_number" class="sx-input" required
                                   value="<?= h($_POST['phone_number'] ?? ($_SESSION['user']['phone'] ?? '')) ?>"
                                   pattern="^\+?\d[\d\s\(\)-]{6,20}\d$" autocomplete="tel" placeholder="09171234567">
                            <span class="sx-hint">For delivery coordination.</span>
                        </div>
                    </div>

                    <div class="sx-field">
                        <label class="sx-label co-req" for="co-street">House/unit number &amp; street</label>
                        <input type="text" id="co-street" name="street_address" class="sx-input" required
                               value="<?= h($_POST['street_address'] ?? '') ?>" autocomplete="address-line1" placeholder="123 Main Street">
                    </div>

                    <div class="co-row">
                        <div class="sx-field">
                            <label class="sx-label co-req" for="co-barangay">Barangay</label>
                            <input type="text" id="co-barangay" name="barangay" class="sx-input" required
                                   value="<?= h($_POST['barangay'] ?? '') ?>" placeholder="Barangay name">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label co-req" for="co-city">City / municipality</label>
                            <input type="text" id="co-city" name="city" class="sx-input" required
                                   value="<?= h($_POST['city'] ?? '') ?>" autocomplete="address-level2" placeholder="City name">
                        </div>
                    </div>

                    <div class="sx-field">
                        <label class="sx-label co-req" for="co-province">Province</label>
                        <input type="text" id="co-province" name="province" class="sx-input" required
                               value="<?= h($_POST['province'] ?? '') ?>" autocomplete="address-level1" placeholder="Province name">
                    </div>

                    <div class="sx-field" style="margin-bottom: 0">
                        <label class="sx-label" for="co-notes">Delivery notes (optional)</label>
                        <textarea id="co-notes" name="notes" class="sx-textarea" rows="3"
                                  placeholder="Landmark, gate code, or other instructions"><?= h($_POST['notes'] ?? '') ?></textarea>
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
                    <p class="co-pay-note">Please prepare the exact amount of <strong>₱<?= number_format($total, 2) ?></strong>. Payment is collected upon delivery.</p>
                </section>
            </div>

            <!-- Summary -->
            <aside class="sx-card co-summary">
                <h2>Order summary</h2>
                <div class="co-items">
                    <?php foreach ($items as $it):
                        $imgPath = !empty($it['p']['image_path']) ? $it['p']['image_path'] : '/sweepxpress/assets/placeholder.jpg';
                    ?>
                        <div class="co-item">
                            <div class="co-item-img">
                                <img src="<?= h($imgPath) ?>" alt="" onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                                <span><?= (int)$it['qty'] ?></span>
                            </div>
                            <div>
                                <div class="co-item-name"><?= h($it['p']['name']) ?></div>
                                <div class="co-item-unit"><?= (int)$it['qty'] ?> × ₱<?= number_format($it['p']['price'], 2) ?></div>
                            </div>
                            <div class="co-item-price">₱<?= number_format($it['subtotal'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="co-line"><span>Subtotal</span><strong>₱<?= number_format($total, 2) ?></strong></div>
                <div class="co-line"><span>Delivery fee</span><strong>None</strong></div>
                <div class="co-total"><span>Total</span><strong>₱<?= number_format($total, 2) ?></strong></div>

                <button type="button" id="placeOrderBtn" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block sx-btn-arrow">
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
                <dt>Items</dt><dd><?= count($items) ?> product<?= count($items) === 1 ? '' : 's' ?></dd>
            </dl>
            <div class="co-total" style="margin-bottom: 0"><span>Total</span><strong>₱<?= number_format($total, 2) ?></strong></div>
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
        const val = (sel) => form.querySelector(sel).value.trim();

        document.getElementById('placeOrderBtn').addEventListener('click', function () {
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            document.getElementById('modalName').textContent = val('[name="name"]');
            document.getElementById('modalPhone').textContent = val('[name="phone_number"]');
            document.getElementById('modalAddress').textContent =
                `${val('[name="street_address"]')}, ${val('[name="barangay"]')}, ${val('[name="city"]')}, ${val('[name="province"]')}`;
            document.getElementById('modalNotes').textContent = val('[name="notes"]') || 'None';
            dialog.showModal();
        });

        document.getElementById('closeModalBtn').addEventListener('click', () => dialog.close());
        document.getElementById('cancelOrderBtn').addEventListener('click', () => dialog.close());
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); }); // click sa backdrop

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
