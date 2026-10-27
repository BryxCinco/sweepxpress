<?php
// purchaseorder.php - ENTERPRISE B2B PO SYSTEM
// Responsive 320px–2560px | Light Mode | Human-crafted

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/send_email.php';
// Safe HTML helper if not globally defined
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// -------------------------------------------------
// INITIAL STATE / SECURITY
// -------------------------------------------------
$message = '';
$user = $_SESSION['user'] ?? null;
$userId = $user['id'] ?? null;
$order_submitted = false;

// CSRF token (pareho sa checkout.php)
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf_token = $_SESSION['csrf_token'];

// Mga tinatanggap na payment terms (dati: kahit anong text mula sa form)
$allowedTerms = [
    'Net 30'        => 'Net 30 — payment due within 30 days',
    'Net 45'        => 'Net 45 — payment due within 45 days',
    'Net 60'        => 'Net 60 — payment due within 60 days',
    'Upon Delivery' => 'Cash on delivery',
];

// Role check
if (!$user || ($user['role'] !== 'business' && $user['role'] !== 'admin')) {
    $_SESSION['error_message'] = "You must be logged in as a Business or Admin to create a Purchase Order.";
    header("Location: /sweepxpress/login.php");
    exit;
}

// -------------------------------------------------
// CART LOAD
// -------------------------------------------------
$cart  = $_SESSION['cart'] ?? [];
$items = [];
$total = 0;

if ($cart) {
    $ids  = implode(',', array_map('intval', array_keys($cart)));
    $stmt = $pdo->query("SELECT * FROM products WHERE id IN ($ids)");

    foreach ($stmt as $row) {
        $qty = (int)($cart[$row['id']] ?? 0);
        if ($qty > 0) {
            $subtotal = $qty * (float)$row['price'];
            $items[]  = ['p' => $row, 'qty' => $qty, 'subtotal' => $subtotal];
            $total   += $subtotal;
        }
    }
}

// Empty cart protection
if (!$items && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['info_message'] = "Your cart is empty. Please add items to create a Purchase Order.";
    header("Location: /sweepxpress/cart.php");
    exit;
}

// -------------------------------------------------
// HANDLE PURCHASE ORDER SUBMISSION
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$order_submitted) {

    $companyName    = trim($_POST['company_name'] ?? '');
    $contactName    = trim($_POST['contact_name'] ?? '');
    $email          = $user['email'] ?? '';
    $phoneNumber    = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
    $billingAddress = trim($_POST['billing_address'] ?? '');
    $shippingAddress = trim($_POST['shipping_address'] ?? '') ?: $billingAddress; // walang laman = kapareho ng billing
    $paymentTerms   = trim($_POST['payment_terms'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'error|Invalid session. Please refresh the page and try again.';
    } elseif (empty($companyName) || empty($contactName) || empty($billingAddress) || empty($paymentTerms)) {
        $message = 'error|Please fill in all required fields marked with *';
    } elseif (!isset($allowedTerms[$paymentTerms])) {
        $message = 'error|Please choose valid payment terms.';
    } elseif ($items) {

        $business_details_note =
            "--- PURCHASE ORDER REQUEST DETAILS ---\n" .
            "Buyer Company Name: " . $companyName . "\n" .
            "Buyer Contact Name: " . $contactName . "\n" .
            "Buyer Email: " . $email . "\n" .
            "Buyer Phone: " . (!empty($phoneNumber) ? $phoneNumber : 'N/A') . "\n" .
            "Billing Address: " . $billingAddress . "\n" .
            "------------------------------------";

        $pdo->beginTransaction();
        try {
            // STOCK CHECK: i-lock ang inventory rows at siguraduhing sapat pa ang stock
            // bago gumawa ng PO. Dati, "max(0, stock - qty)" lang, kaya puwedeng mag-oversell.
            $getInventory = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = 1 FOR UPDATE");
            $shortages = [];
            foreach ($items as $it) {
                $getInventory->execute([(int)$it['p']['id']]);
                $inv = $getInventory->fetch(PDO::FETCH_ASSOC);
                $getInventory->closeCursor();
                $available = $inv ? (int)$inv['quantity'] : 0;
                if ($available < (int)$it['qty']) {
                    $shortages[] = $it['p']['name'] . ' (only ' . $available . ' left)';
                }
            }
            if ($shortages) {
                throw new RuntimeException('STOCK:' . implode(', ', $shortages));
            }

            // Create order
            $stmt = $pdo->prepare("
                INSERT INTO orders (user_id, total, address, customer_name, notes, payment_terms, status, order_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending PO', 'B2B', NOW())
            ");

            $stmt->execute([
                $userId,
                $total,
                $shippingAddress,
                $contactName,
                $business_details_note,
                $paymentTerms
            ]);

            $orderId = $pdo->lastInsertId();

            // Order items
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, price)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($items as $it) {
                $itemStmt->execute([
                    $orderId,
                    $it['p']['id'],
                    $it['qty'],
                    $it['p']['price']
                ]);
            }

            // Stock deduction (location_id = 1)
            $updateInventory     = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $insertStockHistory  = $pdo->prepare("INSERT INTO product_stock_history (product_id, action, quantity, note, created_at) VALUES (?, 'OUT', ?, ?, NOW())");
            $insertStockMovement = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at) VALUES (?, 1, 'OUT', ?, ?, ?, ?, NOW())");

            $user_name = $user['name'] ?? 'B2B Customer';

            foreach ($items as $it) {
                $pid = (int)$it['p']['id'];
                $qty = (int)$it['qty'];

                $getInventory->execute([$pid]);
                $inv = $getInventory->fetch(PDO::FETCH_ASSOC);
                $getInventory->closeCursor();

                if ($inv) {
                    $invId  = $inv['id'];
                    $newQty = max(0, (int)$inv['quantity'] - $qty);

                    $updateInventory->execute([$newQty, $invId]);

                    $note = "Purchase Order #$orderId - $companyName";
                    $insertStockHistory->execute([$pid, $qty, $note]);
                    $insertStockMovement->execute([$pid, $qty, $note, $user_name, $userId]);
                }
            }

            $pdo->commit();

            // --- SEND EMAIL NOTIFICATION ---
            $customer_name  = h($contactName); // free text — i-escape bago ilagay sa HTML email
            $customer_email = $email ?? '';

            if ($customer_email) {
                require_once __DIR__ . '/../includes/send_email.php'; // siguraduhin naka-include

                $subject = "Your Purchase Order #$orderId Has Been Submitted!";
                $body = "
                    <h2>Thank you for your Purchase Order!</h2>
                    <p>Hello <b>$customer_name</b>,</p>
                    <p>Your Purchase Order has been successfully submitted and is now pending review.</p>
                    <p><b>PO Number:</b> $orderId</p>
                    <p>Total: <b>₱" . number_format($total, 2) . "</b></p>
                    <br>
                    <p>You can track the status of your order on your account dashboard.</p>
                    <br>
                    <p>Thank you for doing business with SweepXpress!</p>
                ";

                send_email($customer_email, $subject, $body);
            }

            // Silent AI stock alert
            try {
                $silent_mode   = true;
                $ai_alert_path = __DIR__ . '/../admin/cron_stock_alert.php';
                if (file_exists($ai_alert_path)) {
                    include $ai_alert_path;
                }
            } catch (Exception $e) {
                error_log("AI PO Alert Error: " . $e->getMessage());
            }

            unset($_SESSION['cart']);
            $order_submitted = true;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (str_starts_with($e->getMessage(), 'STOCK:')) {
                $message = 'error|Some items no longer have enough stock: ' . substr($e->getMessage(), 6) . '. Please update your cart and try again.';
            } else {
                error_log("Purchase Order Error: " . $e->getMessage());
                $message = 'error|An error occurred while submitting your purchase order. Please try again.'; // dati: ipinapakita ang DB error
            }
        }
    } else {
        $message = 'warning|The cart was empty during submission.';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Purchase order — dagdag lang sa shared .co-* (assets/style.css) */
.po-terms { display: grid; gap: var(--sx-space-3); }
.po-term {
    display: flex;
    align-items: center;
    gap: var(--sx-space-3);
    padding: var(--sx-space-3) var(--sx-space-4);
    border: 1px solid var(--sx-border);
    border-radius: var(--sx-radius-md);
    background: var(--sx-surface);
    cursor: pointer;
    transition: border-color var(--sx-transition), background var(--sx-transition);
}
.po-term:hover { border-color: var(--sx-primary); }
.po-term input { accent-color: var(--sx-primary); width: 18px; height: 18px; flex-shrink: 0; }
.po-term strong { display: block; font-size: var(--sx-text-sm); color: var(--sx-dark); }
.po-term span { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.po-term:has(input:checked) { border-color: var(--sx-primary); background: var(--sx-primary-soft); box-shadow: 0 0 0 1px var(--sx-primary); }
@media (min-width: 768px) {
    .po-terms { grid-template-columns: 1fr 1fr; }
}
</style>

<?php
$termHints = [
    'Net 30'        => 'Due within 30 days',
    'Net 45'        => 'Due within 45 days',
    'Net 60'        => 'Due within 60 days',
    'Upon Delivery' => 'Pay when the order arrives',
];
$termTitles = ['Net 30' => 'Net 30', 'Net 45' => 'Net 45', 'Net 60' => 'Net 60', 'Upon Delivery' => 'Cash on delivery'];
$selectedTerm = $_POST['payment_terms'] ?? '';
?>
<div class="co sx-container">
<?php if ($order_submitted): ?>
    <div class="sx-card co-success">
        <div class="co-success-icon"><i class="fa-solid fa-file-circle-check"></i></div>
        <h1 class="sx-heading-lg">Purchase order submitted!</h1>
        <div class="co-success-id">PO #<?= h($orderId) ?></div>
        <p>Your purchase order has been received and the stock has been reserved. We'll email you once it's reviewed.</p>
        <div class="co-success-actions">
            <a href="/sweepxpress/Invoice.php?order_id=<?= (int)$orderId ?>" class="sx-btn sx-btn-dark sx-btn-arrow">
                View / print PO <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
            <a href="/sweepxpress/customers/my_orders.php" class="sx-btn sx-btn-outline"><i class="fa-solid fa-receipt"></i> My orders</a>
            <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-ghost">Continue shopping</a>
        </div>
    </div>

<?php elseif (!$items): ?>
    <div class="sx-card sx-empty">
        <i class="fa-solid fa-file-invoice"></i>
        <h3>Your cart is empty</h3>
        <p style="margin: 0 0 var(--sx-space-4)">Add items to your cart to create a purchase order.</p>
        <a href="/sweepxpress/cart.php" class="sx-btn sx-btn-dark">Go to cart</a>
    </div>

<?php else: ?>
    <div class="co-head">
        <div>
            <ol class="co-steps">
                <li class="done"><a href="/sweepxpress/cart.php"><span class="dot"><i class="fa-solid fa-check"></i></span> Cart</a></li>
                <li class="current"><span class="dot">2</span> PO details</li>
                <li><span class="dot">3</span> Submitted</li>
            </ol>
            <h1 class="sx-heading-lg">Purchase Order</h1>
        </div>
        <a href="/sweepxpress/cart.php" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to cart</a>
    </div>

    <?php if ($message):
        [$msgType, $msgText] = array_pad(explode('|', $message, 2), 2, '');
    ?>
        <div class="sx-alert sx-alert-<?= $msgType === 'warning' ? 'warning' : 'error' ?>" role="alert" style="margin-bottom: var(--sx-space-5)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= h($msgText) ?></div>
        </div>
    <?php endif; ?>

    <form method="POST" id="poForm">
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

        <div class="co-layout">
            <div class="co-main">
                <!-- 1. Buyer -->
                <section class="sx-card">
                    <h2 class="co-card-title"><span class="num">1</span> Buyer information</h2>

                    <div class="co-email-note">
                        <i class="fa-solid fa-envelope"></i>
                        <span>PO updates will be sent to <strong><?= h($user['email'] ?? '') ?></strong></span>
                    </div>

                    <div class="co-row">
                        <div class="sx-field">
                            <label class="sx-label co-req" for="company_name">Company name</label>
                            <input type="text" id="company_name" name="company_name" class="sx-input" required
                                   value="<?= h($_POST['company_name'] ?? '') ?>" autocomplete="organization" placeholder="ABC Corporation">
                            <span class="sx-hint">Your registered business name.</span>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label co-req" for="contact_name">Contact person</label>
                            <input type="text" id="contact_name" name="contact_name" class="sx-input" required
                                   value="<?= h($_POST['contact_name'] ?? ($user['name'] ?? '')) ?>" autocomplete="name" placeholder="Juan Dela Cruz">
                        </div>
                    </div>

                    <div class="sx-field" style="margin-bottom: 0">
                        <label class="sx-label" for="phone_number">Phone number <span class="sx-hint">(optional)</span></label>
                        <input type="tel" id="phone_number" name="phone_number" class="sx-input"
                               value="<?= h($_POST['phone_number'] ?? ($user['phone'] ?? '')) ?>"
                               pattern="[0-9\s+()-]{7,20}" title="7–15 digits" autocomplete="tel" placeholder="09171234567">
                    </div>
                </section>

                <!-- 2. Addresses -->
                <section class="sx-card">
                    <h2 class="co-card-title"><span class="num">2</span> Billing &amp; delivery</h2>
                    <div class="co-row">
                        <div class="sx-field">
                            <label class="sx-label co-req" for="billing_address">Billing address</label>
                            <textarea id="billing_address" name="billing_address" class="sx-textarea" rows="3" required
                                      placeholder="Street, barangay, city, province, ZIP"><?= h($_POST['billing_address'] ?? '') ?></textarea>
                            <span class="sx-hint">Shown on the invoice.</span>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="shipping_address">Delivery address <span class="sx-hint">(optional)</span></label>
                            <textarea id="shipping_address" name="shipping_address" class="sx-textarea" rows="3"
                                      placeholder="Leave blank if same as billing"><?= h($_POST['shipping_address'] ?? '') ?></textarea>
                            <span class="sx-hint">If different from billing.</span>
                        </div>
                    </div>
                </section>

                <!-- 3. Payment terms -->
                <section class="sx-card">
                    <h2 class="co-card-title"><span class="num">3</span> Payment terms</h2>
                    <div class="po-terms" role="radiogroup" aria-label="Payment terms">
                        <?php foreach ($allowedTerms as $value => $label): ?>
                            <label class="po-term">
                                <input type="radio" name="payment_terms" value="<?= h($value) ?>" required <?= $selectedTerm === $value ? 'checked' : '' ?>>
                                <div>
                                    <strong><?= h($termTitles[$value]) ?></strong>
                                    <span><?= h($termHints[$value]) ?></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <!-- Summary -->
            <aside class="sx-card co-summary">
                <h2>PO summary</h2>
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

                <div class="co-line"><span>Items</span><strong><?= count($items) ?> product<?= count($items) === 1 ? '' : 's' ?></strong></div>
                <div class="co-line"><span>Delivery fee</span><strong>None</strong></div>
                <div class="co-total"><span>Grand total</span><strong>₱<?= number_format($total, 2) ?></strong></div>

                <button type="submit" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-block sx-btn-arrow" id="poSubmit">
                    Submit purchase order <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </button>
                <p class="co-secure"><i class="fa-solid fa-boxes-stacked"></i> Stock is reserved when you submit.</p>
            </aside>
        </div>
    </form>

    <script>
    // Iwas dobleng submit (browser na ang bahala sa required fields)
    document.getElementById('poForm').addEventListener('submit', function () {
        const btn = document.getElementById('poSubmit');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    });
    </script>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
