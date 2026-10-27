<?php
// cart.php
require_once __DIR__ . '/config.php';


// Helper function from checkout.php for consistency
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}


// HELPER FUNCTION: To format markdown **bold** to HTML <strong>bold</strong>
if (!function_exists('format_notification')) {
    function format_notification($text) {
        // Replaces **text** with <strong>text</strong>
        return preg_replace('/\*\*(.*?)\*\*\s?/s', '<strong>$1</strong> ', $text);
    }
}


$message = '';
$notifications = []; // Array to store stock limit notifications


// Update quantities (STOCK LIMITING LOGIC ADDED HERE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qty'])) {
    
    // 1. Fetch current stock and product name for all products being updated
    $pidsToUpdate = array_keys($_POST['qty']);
    
    // Safety check: only proceed if there are product IDs to check
    if (!empty($pidsToUpdate)) {
        $placeholders = implode(',', array_fill(0, count($pidsToUpdate), '?'));


        $stockData = [];
        // SQL query to calculate real stock: join products with inventory
        $sql = "SELECT p.id, p.name, IFNULL(SUM(i.quantity), 0) as real_stock 
                FROM products p 
                LEFT JOIN inventory i ON i.product_id = p.id 
                WHERE p.id IN ($placeholders) 
                GROUP BY p.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($pidsToUpdate);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stockData[(int)$row['id']] = [
                'name' => $row['name'],
                'stock' => (int)$row['real_stock']
            ];
        }
    } else {
        $stockData = [];
    }
    
    // 2. Process updates and apply stock limit
    foreach ($_POST['qty'] as $pid => $q) {
        $pid = (int)$pid;
        // Ensure quantity is a non-negative integer
        $q = max(0, (int)$q);
        
        // Get stock and name for this product
        $productInfo = $stockData[$pid] ?? ['name' => 'Unknown Product', 'stock' => 0];
        $stock = $productInfo['stock'];
        $productName = $productInfo['name'];
        
        $requestedQuantity = $q; // Store the quantity the user requested


        // CRITICAL: Limit the requested quantity to the available stock
        $q = min($q, $stock); 
        
        // IMPROVED SERVER-SIDE NOTIFICATION TEXT
        if ($requestedQuantity > $stock && $stock > 0) {
            // Add a notification that the quantity was reduced
            $notifications[] = "The quantity you requested (**" . $requestedQuantity . " for " . h($productName) . "**) has been officially limited to the available stock: **" . $stock . "**.";
        } elseif ($requestedQuantity > 0 && $stock === 0) {
            // Add a notification if the item was requested but is now out of stock
            $notifications[] = "**" . h($productName) . "** is currently **Out of Stock** and has been removed from your cart.";
        }


        if ($q === 0) {
            unset($_SESSION['cart'][$pid]);
        } else {
            $_SESSION['cart'][$pid] = $q;
        }
    }
    
    // Store notifications in the session for display on the next page load
    if (!empty($notifications)) {
        $_SESSION['notifications'] = $notifications;
    }


    // Refresh the page to reflect the new cart state without resubmitting the form
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}


// Check for and display notifications from the previous request
if (isset($_SESSION['notifications'])) {
    // Add existing session notifications to the local array
    $notifications = array_merge($notifications, $_SESSION['notifications']);
    // Clear the session variable so the notification doesn't persist
    unset($_SESSION['notifications']); 
}


$cart = $_SESSION['cart'] ?? [];
$items = [];
$total = 0;
$itemCount = 0; // Tracks the number of unique products (for display: X items)
$totalQuantity = 0; // Tracks the total number of units


if ($cart) {
    // 1. Get product IDs from the cart session
    $productIds = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));


    // 2. Prepare and execute the statement (MODIFIED to include real_stock)
    $sql = "SELECT p.*, IFNULL(SUM(i.quantity), 0) as real_stock 
            FROM products p 
            LEFT JOIN inventory i ON i.product_id = p.id 
            WHERE p.id IN ($placeholders) 
            GROUP BY p.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($productIds);


    // 3. Process results
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $qty = (int)($cart[$pid] ?? 0);
        $stock = (int)$row['real_stock']; // Get the stock
        $productName = $row['name']; // Get product name for notification


        // SERVER-SIDE SANITIZATION: Check if the cart quantity exceeds stock. 
        if ($qty > $stock) {
             // LOGIC: Add a notification for quantity reduction during initial load/sanitization
            if ($stock > 0) {
                $notifications[] = "The quantity for **" . h($productName) . "** was updated to **" . $stock . "** because only that amount is currently in stock.";
            } else {
                $notifications[] = "**" . h($productName) . "** is currently **Out of Stock** and has been removed from your cart.";
            }


            $qty = $stock;
            $_SESSION['cart'][$pid] = $stock; // Update session immediately
        }


        if ($qty > 0) {
            $subtotal = $qty * (float)$row['price'];
            $row['stock'] = $stock; // Add stock to product array
            $items[] = ['p'=>$row, 'qty'=>$qty, 'subtotal'=>$subtotal];
            $total += $subtotal;
            $itemCount++; // Count unique products
            $totalQuantity += $qty; // Count total units
        } else {
            // Remove item if stock is zero and cart quantity was reset to 0
            unset($_SESSION['cart'][$pid]);
        }
    }
}



// Determine checkout path based on user role
$checkoutUrl = '/sweepxpress/checkout.php';
$checkoutText = 'Proceed to Checkout';


if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'business') {
    $checkoutUrl = '/sweepxpress/business/purchaseorder.php';
    $checkoutText = 'Generate Purchase Order';
}


// INCLUDE HEADER HERE
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ============================================================
   CART (Phase 9c) — tokens + sx-alert / sx-qty mula sa assets/style.css
   ============================================================ */
.cart { padding-block: var(--sx-space-5) var(--sx-space-8); }
.cart-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-5);
}
.cart-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.cart-alerts { display: grid; gap: var(--sx-space-2); margin-bottom: var(--sx-space-5); }
.cart-layout { display: grid; gap: var(--sx-space-5); align-items: start; }

/* Items */
.cart-items { padding: 0; overflow: hidden; }
.cart-item {
    display: grid;
    grid-template-columns: 88px 1fr;
    gap: var(--sx-space-4);
    padding: var(--sx-space-4) var(--sx-space-5);
    border-bottom: 1px solid var(--sx-border-light);
    transition: opacity var(--sx-transition);
}
.cart-item:last-child { border-bottom: none; }
.cart-item.updating { opacity: 0.5; pointer-events: none; }
.cart-thumb {
    display: block;
    width: 88px;
    height: 88px;
    border-radius: var(--sx-radius-md);
    background: var(--sx-surface-alt);
    overflow: hidden;
}
.cart-thumb img { width: 100%; height: 100%; object-fit: cover; }
.cart-info { display: grid; gap: var(--sx-space-3); min-width: 0; }
.cart-info-top { display: flex; justify-content: space-between; gap: var(--sx-space-3); }
.cart-name { margin: 0; font-size: var(--sx-text-base); font-weight: var(--sx-weight-bold); line-height: 1.3; }
.cart-name a { color: var(--sx-dark); text-decoration: none; }
.cart-name a:hover { color: var(--sx-primary); }
.cart-meta { margin: 4px 0 0; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.cart-meta .low { color: var(--sx-status-pending); font-weight: var(--sx-weight-semibold); }
.cart-remove {
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: var(--sx-muted);
    cursor: pointer;
    transition: background var(--sx-transition), color var(--sx-transition);
}
.cart-remove:hover { background: var(--sx-status-cancelled-bg); color: var(--sx-danger); }
.cart-info-bottom { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-3); }
.cart-subtotal { font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); letter-spacing: var(--sx-tracking-tight); color: var(--sx-dark); }

/* Summary */
.cart-summary h2 { margin: 0 0 var(--sx-space-4); font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.cart-row { display: flex; justify-content: space-between; gap: var(--sx-space-3); padding-block: var(--sx-space-2); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.cart-row strong { color: var(--sx-dark); font-weight: var(--sx-weight-semibold); }
.cart-total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin: var(--sx-space-3) 0 var(--sx-space-5);
    padding-top: var(--sx-space-4);
    border-top: 1px dashed var(--sx-border);
}
.cart-total span { font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.cart-total strong { font-size: var(--sx-text-2xl); font-weight: var(--sx-weight-extrabold); letter-spacing: var(--sx-tracking-tight); color: var(--sx-dark); }
.cart-actions { display: grid; gap: var(--sx-space-2); }
.cart-note {
    display: flex;
    gap: var(--sx-space-2);
    margin: var(--sx-space-4) 0 0;
    font-size: var(--sx-text-xs);
    color: var(--sx-muted);
}
.cart-note i { color: var(--sx-accent-dark); margin-top: 2px; }

@media (min-width: 992px) {
    .cart-layout { grid-template-columns: 1fr 360px; }
    .cart-summary { position: sticky; top: 96px; }
}
@media (max-width: 575.98px) {
    .cart-item { grid-template-columns: 72px 1fr; padding: var(--sx-space-4); gap: var(--sx-space-3); }
    .cart-thumb { width: 72px; height: 72px; }
}
</style>

<div class="cart sx-container">
    <div class="cart-head">
        <div>
            <span class="sx-eyebrow">Cart</span>
            <h1 class="sx-heading-lg">Shopping Cart</h1>
            <p>
                <?php if ($items): ?>
                    <?php echo $itemCount; ?> product<?php echo $itemCount === 1 ? '' : 's'; ?> · <?php echo $totalQuantity; ?> unit<?php echo $totalQuantity === 1 ? '' : 's'; ?>
                <?php else: ?>
                    Review and manage your items
                <?php endif; ?>
            </p>
        </div>
        <?php if ($items): ?>
            <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Continue shopping</a>
        <?php endif; ?>
    </div>

    <?php if ($notifications): // Server-side na abiso (hal. naibaba sa available stock) ?>
        <div class="cart-alerts">
            <?php foreach ($notifications as $note): ?>
                <div class="sx-alert sx-alert-warning" role="status">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><?php echo format_notification($note); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$items): ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-cart-shopping"></i>
            <h3>Your cart is empty</h3>
            <p style="margin: 0 0 var(--sx-space-4)">Add products to get started.</p>
            <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-dark sx-btn-arrow">
                Browse products <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>
    <?php else: ?>
        <div class="cart-layout">
            <div class="sx-card cart-items">
                <form id="cartForm" method="post">
                    <?php foreach ($items as $it):
                        $pid   = (int)$it['p']['id'];
                        $stock = (int)$it['p']['stock'];
                    ?>
                        <div class="cart-item" data-product-id="<?php echo $pid; ?>">
                            <a href="/sweepxpress/product.php?id=<?php echo $pid; ?>" class="cart-thumb" tabindex="-1" aria-hidden="true">
                                <img src="<?php echo h($it['p']['image_path'] ?: '/sweepxpress/assets/placeholder.jpg'); ?>" alt=""
                                     onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                            </a>

                            <div class="cart-info">
                                <div class="cart-info-top">
                                    <div>
                                        <h3 class="cart-name"><a href="/sweepxpress/product.php?id=<?php echo $pid; ?>"><?php echo h($it['p']['name']); ?></a></h3>
                                        <p class="cart-meta">
                                            ₱<?php echo number_format($it['p']['price'], 2); ?> each
                                            <?php if ($stock <= 10): ?> · <span class="low">Only <?php echo $stock; ?> left</span><?php endif; ?>
                                        </p>
                                    </div>
                                    <button type="button" class="cart-remove item-remove" data-product-id="<?php echo $pid; ?>" aria-label="Remove <?php echo h($it['p']['name']); ?>">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>

                                <div class="cart-info-bottom">
                                    <div class="sx-qty qty-group" data-product-id="<?php echo $pid; ?>">
                                        <button type="button" class="qty-decrease" data-product-id="<?php echo $pid; ?>" aria-label="Decrease quantity">
                                            <i class="fa-solid fa-minus"></i>
                                        </button>
                                        <input type="number"
                                               class="qty-input"
                                               name="qty[<?php echo $pid; ?>]"
                                               value="<?php echo (int)$it['qty']; ?>"
                                               min="0"
                                               max="<?php echo $stock; ?>"
                                               data-max-qty="<?php echo $stock; ?>"
                                               data-original="<?php echo (int)$it['qty']; ?>"
                                               data-product-name="<?php echo h($it['p']['name']); ?>"
                                               aria-label="Quantity for <?php echo h($it['p']['name']); ?>">
                                        <button type="button" class="qty-increase" data-product-id="<?php echo $pid; ?>" aria-label="Increase quantity">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <span class="cart-subtotal">₱<?php echo number_format($it['subtotal'], 2); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </form>
            </div>

            <aside class="sx-card cart-summary">
                <h2>Order summary</h2>
                <div class="cart-row"><span>Subtotal (<?php echo $totalQuantity; ?> unit<?php echo $totalQuantity === 1 ? '' : 's'; ?>)</span><strong>₱<?php echo number_format($total, 2); ?></strong></div>
                <div class="cart-row"><span>Delivery fee</span><strong>None</strong></div>
                <div class="cart-total"><span>Total</span><strong>₱<?php echo number_format($total, 2); ?></strong></div>

                <div class="cart-actions">
                    <a href="<?= h($checkoutUrl) ?>" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-arrow">
                        <?= h($checkoutText) ?> <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                    </a>
                    <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-outline">Continue shopping</a>
                </div>

                <?php if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'business'): ?>
                    <p class="cart-note"><i class="fa-solid fa-file-invoice"></i> You'll create a purchase order with your payment terms.</p>
                <?php else: ?>
                    <p class="cart-note"><i class="fa-solid fa-money-bill-wave"></i> Pay with cash on delivery.</p>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const cartForm = document.getElementById('cartForm');
    if (!cartForm) return;

    let submitting = false;

    // Isang submit lang kahit sabay-sabay ang input, blur at change (dati 2–3 beses)
    function submitCart(input) {
        if (submitting) return;
        submitting = true;
        input.closest('.cart-item').classList.add('updating');
        cartForm.submit();
    }

    function inputFor(productId) {
        return cartForm.querySelector(`input[name="qty[${productId}]"]`);
    }

    function flagLimit(input, on) {
        input.closest('.qty-group').classList.toggle('over-limit', on);
    }

    // textContent sa sxToast — hindi na ipinapasok ang pangalan ng produkto bilang HTML
    function stockToast(input) {
        const name = input.dataset.productName || 'This item';
        sxToast(`${name}: only ${input.dataset.maxQty} in stock`, 'error');
    }

    // 1. Increase — kapag nasa maximum na, abiso lang (walang reload)
    cartForm.querySelectorAll('.qty-increase').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = inputFor(this.dataset.productId);
            const max = parseInt(input.dataset.maxQty, 10) || 0;
            const current = parseInt(input.value, 10) || 0;

            if (current >= max) {
                flagLimit(input, true);
                stockToast(input);
                return;
            }
            input.value = current + 1;
            submitCart(input);
        });
    });

    // 2. Decrease — kapag 1 na lang, tanong muna bago tanggalin
    cartForm.querySelectorAll('.qty-decrease').forEach(btn => {
        btn.addEventListener('click', function () {
            const input = inputFor(this.dataset.productId);
            const current = parseInt(input.value, 10) || 0;
            flagLimit(input, false);

            if (current <= 1) {
                if (!confirm('Remove this item from your cart?')) return;
                input.value = 0;
            } else {
                input.value = current - 1;
            }
            submitCart(input);
        });
    });

    // 3. Manual na pag-type: hintayin matapos mag-type, i-clamp sa stock, saka i-submit kung nagbago
    cartForm.querySelectorAll('.qty-input').forEach(input => {
        let timer;

        const commit = () => {
            clearTimeout(timer);
            const max = parseInt(input.dataset.maxQty, 10) || 0;
            const requested = parseInt(input.value, 10) || 0;
            const value = Math.min(Math.max(requested, 0), max);

            if (requested > max) stockToast(input);
            flagLimit(input, false);
            input.value = value;

            if (value === 0 && !confirm('Remove this item from your cart?')) {
                input.value = input.dataset.original;
                return;
            }
            if (String(value) !== input.dataset.original) submitCart(input);
        };

        input.addEventListener('input', () => {
            const max = parseInt(input.dataset.maxQty, 10) || 0;
            flagLimit(input, (parseInt(input.value, 10) || 0) > max);
            clearTimeout(timer);
            timer = setTimeout(commit, 800);
        });
        input.addEventListener('change', commit);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
    });

    // 4. Remove
    cartForm.querySelectorAll('.item-remove').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!confirm('Remove this item from your cart?')) return;
            const input = inputFor(this.dataset.productId);
            input.value = 0;
            submitCart(input);
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
