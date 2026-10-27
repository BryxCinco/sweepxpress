<?php
// customers/order_details.php — detalye ng isang order ng naka-login na customer

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
    // Isang delivery row lang (dati: LEFT JOIN na puwedeng kumuha ng kahit alin)
    $stmt = $pdo->prepare("
        SELECT o.*, d.delivery_date
        FROM orders o
        LEFT JOIN (
            SELECT order_id, MAX(delivery_date) AS delivery_date
            FROM deliveries
            GROUP BY order_id
        ) d ON d.order_id = o.id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        header("Location: /sweepxpress/customers/my_orders.php?status=not_found");
        exit();
    }

    // LEFT JOIN: lalabas pa rin ang item kahit na-delete na ang produkto
    $stmt_items = $pdo->prepare("
        SELECT
            oi.*,
            p.name,
            p.image_path,
            (oi.quantity * oi.price) AS subtotal
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $stmt_items->execute([$order_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error in order_details.php: " . $e->getMessage());
    die("An unexpected error occurred. Please try again later.");
}

$display_status = $order['status'];
$admin_note = trim($order['admin_note'] ?? '');

// ====================================================================
// 3. STATUS MESSAGES (galing sa cancel_order.php / confirm_received.php)
// ====================================================================
// [alert type, title, text]
$statusMessages = [
    'request_submitted' => ['success', 'Request submitted', 'Your cancellation request has been submitted for review.'],
    'reason_error'      => ['warning', 'Submission failed', 'Please provide a detailed reason (at least 10 characters).'],
    'request_denied'    => ['error',   'Action not allowed', 'Cannot submit cancellation request. Order is no longer pending.'],
    'error'             => ['error',   'System error', 'An unexpected error occurred.'],
    'received_success'  => ['success', 'Order completed', 'Thank you for confirming that you received your order.'],
    'received_error'    => ['info',    'Order status updated', 'You indicated that you did not receive the order. The status has been set back to Preparing.'],
    'invalid_confirm'   => ['warning', 'Invalid action', 'This order cannot be confirmed as received.'],
];
$flash = $statusMessages[$_GET['status'] ?? ''] ?? null;

// ADMIN DECISION (para sa pending o cancelled na may note) — ipinapakita hanggang pindutin ang "Got it"
$decision = null;
if ($admin_note !== '') {
    if ($display_status === 'cancelled') {
        $decision = ['success', 'Cancellation approved', "Your request to cancel Order #{$order_id} has been approved."];
    } elseif ($display_status === 'pending' && $order['cancellation_reason'] === NULL) {
        $decision = ['error', 'Cancellation rejected', "Your request to cancel Order #{$order_id} has been rejected."];
    }
}

// Status → label + pill (kapareho ng my_orders.php)
$statusLabels = [
    'pending'                => ['Pending', 'pending'],
    'Pending PO'             => ['PO under review', 'pending'],
    'preparing'              => ['Preparing', 'preparing'],
    'shipped'                => ['Preparing', 'preparing'],
    'delivered'              => ['Delivered', 'delivered'],
    'completed'              => ['Completed', 'completed'],
    'cancellation_requested' => ['Cancel requested', 'cancelled'],
    'cancelled'              => ['Cancelled', 'cancelled'],
];
[$statusLabel, $pillClass] = $statusLabels[$display_status] ?? [ucfirst(str_replace('_', ' ', $display_status)), 'pending'];

// Progress steps: 0 Placed → 1 Preparing → 2 Delivered → 3 Completed
$stepIndex = match ($display_status) {
    'preparing', 'shipped' => 1,
    'delivered'            => 2,
    'completed'            => 3,
    default                => 0,
};
$isCancelled = in_array($display_status, ['cancelled', 'cancellation_requested'], true);
$steps = [
    ['fa-receipt', 'Placed', date('M j', strtotime($order['created_at']))],
    ['fa-box', 'Preparing', ''],
    ['fa-truck', 'Delivered', !empty($order['delivery_date']) ? date('M j', strtotime($order['delivery_date'])) : ''],
    ['fa-circle-check', 'Completed', ''],
];

$itemsSubtotal = array_sum(array_column($items, 'subtotal'));
$deliveryFee   = max(0, (float)$order['total'] - $itemsSubtotal);
$unitCount     = array_sum(array_column($items, 'quantity'));
$isPO          = $order['order_type'] === 'B2B' || $display_status === 'Pending PO';
$placeholder   = '/sweepxpress/assets/placeholder.jpg';

// ====================================================================
// 4. HTML HEADER INCLUDE
// ====================================================================
require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ORDER DETAILS (Phase 9d) — tokens + sx-pill / sx-alert / co-dialog mula sa assets/style.css
   ============================================================ */
.od { max-width: 1100px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.od-crumb { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); margin-bottom: var(--sx-space-4); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.od-crumb a { color: var(--sx-muted); text-decoration: none; }
.od-crumb a:hover { color: var(--sx-primary); }
.od-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-5);
}
.od-head h1 { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-3); margin: 0; }
.od-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.od-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.od-alerts { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.od-alert-note { margin-top: var(--sx-space-2); padding: var(--sx-space-2) var(--sx-space-3); border-radius: var(--sx-radius-md); background: rgba(255, 255, 255, 0.6); color: var(--sx-dark); white-space: pre-wrap; }
.od-alert .sx-btn { margin-top: var(--sx-space-3); }

/* Progress tracker */
.od-track { display: grid; grid-template-columns: repeat(4, 1fr); margin-bottom: var(--sx-space-5); padding: var(--sx-space-5); }
.od-step { position: relative; display: grid; justify-items: center; gap: 6px; text-align: center; }
.od-step::before {                       /* linya papunta sa naunang step */
    content: "";
    position: absolute;
    top: 21px;
    right: 50%;
    width: 100%;
    height: 3px;
    background: var(--sx-border-light);
}
.od-step:first-child::before { display: none; }
.od-step-dot {
    position: relative;
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border: 3px solid var(--sx-border-light);
    border-radius: 50%;
    background: var(--sx-surface);
    color: var(--sx-muted);
}
.od-step strong { font-size: var(--sx-text-sm); color: var(--sx-muted); }
.od-step small { min-height: 1.2em; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.od-step.done::before { background: var(--sx-accent); }
.od-step.done .od-step-dot { border-color: var(--sx-accent); background: var(--sx-accent); color: #fff; }
.od-step.done strong { color: var(--sx-dark); }
.od-step.current .od-step-dot { box-shadow: 0 0 0 5px var(--sx-accent-soft); }
.od-track.is-cancelled .od-step.done::before,
.od-track.is-cancelled .od-step.done .od-step-dot { border-color: var(--sx-border); background: var(--sx-border); }

/* Layout */
.od-layout { display: grid; gap: var(--sx-space-5); align-items: start; }
.od-card-title { display: flex; align-items: center; gap: var(--sx-space-2); margin: 0 0 var(--sx-space-4); font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.od-card-title i { color: var(--sx-primary); }

/* Items */
.od-items { padding: 0; overflow: hidden; }
.od-items .od-card-title { padding: var(--sx-space-5) var(--sx-space-5) 0; }
.od-item {
    display: grid;
    grid-template-columns: 64px 1fr auto;
    align-items: center;
    gap: var(--sx-space-4);
    padding: var(--sx-space-4) var(--sx-space-5);
    border-top: 1px solid var(--sx-border-light);
}
.od-item img { width: 64px; height: 64px; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.od-item-name { margin: 0; font-weight: var(--sx-weight-bold); line-height: 1.3; }
.od-item-name a { color: var(--sx-dark); text-decoration: none; }
.od-item-name a:hover { color: var(--sx-primary); }
.od-item-unit { font-size: var(--sx-text-sm); color: var(--sx-muted); }
.od-item-price { font-weight: var(--sx-weight-bold); color: var(--sx-dark); white-space: nowrap; }
.od-totals { display: grid; gap: var(--sx-space-2); padding: var(--sx-space-4) var(--sx-space-5); background: var(--sx-surface-alt); }
.od-totals div { display: flex; justify-content: space-between; font-size: var(--sx-text-sm); color: var(--sx-muted); }
.od-totals .grand { padding-top: var(--sx-space-2); border-top: 1px dashed var(--sx-border); font-size: var(--sx-text-base); color: var(--sx-dark); }
.od-totals .grand strong { font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-primary); }

/* Side info */
.od-side { display: grid; gap: var(--sx-space-5); }
.od-info { display: grid; gap: var(--sx-space-4); margin: 0; }
.od-info div { display: grid; gap: 2px; }
.od-info dt { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); letter-spacing: 0.04em; text-transform: uppercase; color: var(--sx-muted); }
.od-info dd { margin: 0; color: var(--sx-dark); overflow-wrap: anywhere; }
.od-po-notes summary { cursor: pointer; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); color: var(--sx-primary); }
.od-po-notes pre { margin: var(--sx-space-2) 0 0; padding: var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); font-family: inherit; font-size: var(--sx-text-xs); white-space: pre-wrap; }

/* Action card (cancel / confirm) */
.od-action h2 { margin: 0 0 var(--sx-space-2); font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.od-action p { margin: 0 0 var(--sx-space-4); color: var(--sx-muted); font-size: var(--sx-text-sm); }
.od-action .sx-field { margin-bottom: var(--sx-space-3); }
.od-action .sx-textarea { min-height: 96px; }

@media (min-width: 992px) {
    .od-layout { grid-template-columns: 1fr 340px; }
    .od-side { position: sticky; top: calc(var(--sx-nav-h, 70px) + var(--sx-space-4)); }
}
@media (max-width: 575.98px) {
    .od-track { padding: var(--sx-space-4) var(--sx-space-2); }
    .od-step-dot { width: 36px; height: 36px; font-size: 0.85rem; }
    .od-step::before { top: 17px; }
    .od-item { grid-template-columns: 52px 1fr; }
    .od-item img { width: 52px; height: 52px; }
    .od-item-price { grid-column: 2; }
    .od-head-actions { width: 100%; }
    .od-head-actions .sx-btn { flex: 1; justify-content: center; }
}
</style>

<div class="od sx-container">
    <nav class="od-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/index.php">Home</a> <span aria-hidden="true">/</span>
        <a href="/sweepxpress/customers/my_orders.php">My Orders</a> <span aria-hidden="true">/</span>
        <span>Order #<?= (int)$order['id'] ?></span>
    </nav>

    <div class="od-head">
        <div>
            <h1 class="sx-heading-lg">
                Order #<?= (int)$order['id'] ?>
                <span class="sx-pill sx-pill-<?= $pillClass ?>"><?= h($statusLabel) ?></span>
            </h1>
            <p>Placed on <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></p>
        </div>
        <div class="od-head-actions">
            <a href="/sweepxpress/Invoice.php?order_id=<?= (int)$order['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm">
                <i class="fa-solid fa-file-invoice"></i> Invoice
            </a>
            <?php if ($display_status === 'completed'): ?>
                <a href="/sweepxpress/customers/UserReceipt.php?order_id=<?= (int)$order['id'] ?>" target="_blank" class="sx-btn sx-btn-primary sx-btn-sm">
                    <i class="fa-solid fa-receipt"></i> Receipt
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flash || $decision): ?>
        <div class="od-alerts">
            <?php if ($flash): ?>
                <div class="sx-alert sx-alert-<?= $flash[0] ?>" role="status">
                    <i class="fa-solid fa-circle-info"></i>
                    <div><strong><?= h($flash[1]) ?>.</strong> <?= h($flash[2]) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($decision): ?>
                <div class="sx-alert sx-alert-<?= $decision[0] ?> od-alert" role="alert">
                    <i class="fa-solid fa-bell"></i>
                    <div>
                        <strong><?= h($decision[1]) ?>.</strong> <?= h($decision[2]) ?>
                        <div class="od-alert-note"><strong>Admin note:</strong> <?= h($admin_note) ?></div>
                        <a href="/sweepxpress/customers/order_details.php?id=<?= (int)$order_id ?>&cleanup=1" class="sx-btn sx-btn-outline sx-btn-sm">Got it</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Progress -->
    <div class="sx-card od-track<?= $isCancelled ? ' is-cancelled' : '' ?>" aria-label="Order progress">
        <?php foreach ($steps as $i => [$icon, $label, $date]):
            $cls = $i <= $stepIndex ? ' done' : '';
            $cls .= ($i === $stepIndex && !$isCancelled) ? ' current' : '';
        ?>
            <div class="od-step<?= $cls ?>">
                <span class="od-step-dot"><i class="fa-solid <?= $icon ?>"></i></span>
                <strong><?= $label ?></strong>
                <small><?= h($date) ?></small>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="od-layout">
        <!-- Items -->
        <section class="sx-card od-items">
            <h2 class="od-card-title"><i class="fa-solid fa-bag-shopping"></i> Items (<?= $unitCount ?> unit<?= $unitCount === 1 ? '' : 's' ?>)</h2>
            <?php if ($items): ?>
                <?php foreach ($items as $item):
                    $name = $item['name'] ?? 'Product no longer available';
                ?>
                    <div class="od-item">
                        <img src="<?= h($item['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                        <div>
                            <p class="od-item-name">
                                <?php if ($item['name'] !== null): ?>
                                    <a href="/sweepxpress/product.php?id=<?= (int)$item['product_id'] ?>"><?= h($name) ?></a>
                                <?php else: ?>
                                    <?= h($name) ?>
                                <?php endif; ?>
                            </p>
                            <span class="od-item-unit"><?= (int)$item['quantity'] ?> × ₱<?= number_format((float)$item['price'], 2) ?></span>
                        </div>
                        <div class="od-item-price">₱<?= number_format((float)$item['subtotal'], 2) ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="od-totals">
                    <div><span>Items subtotal</span><span>₱<?= number_format($itemsSubtotal, 2) ?></span></div>
                    <?php if ($deliveryFee > 0): ?>
                        <div><span>Delivery fee</span><span>₱<?= number_format($deliveryFee, 2) ?></span></div>
                    <?php endif; ?>
                    <div class="grand"><span>Order total</span><strong>₱<?= number_format((float)$order['total'], 2) ?></strong></div>
                </div>
            <?php else: ?>
                <div class="sx-empty">
                    <i class="fa-solid fa-box-open"></i>
                    <h3>No items found</h3>
                    <p>This order has no item details.</p>
                </div>
            <?php endif; ?>
        </section>

        <div class="od-side">
            <!-- Action: depende sa status -->
            <?php if ($display_status === 'pending'): ?>
                <section class="sx-card od-action">
                    <h2>Need to cancel?</h2>
                    <p>Tell us why and our team will review your request.</p>
                    <button type="button" class="sx-btn sx-btn-outline sx-btn-block" id="openCancel">
                        <i class="fa-solid fa-ban"></i> Request cancellation
                    </button>
                </section>
            <?php elseif ($display_status === 'cancellation_requested'): ?>
                <section class="sx-card od-action">
                    <h2>Cancellation under review</h2>
                    <p>We're reviewing your request and will update you shortly.</p>
                    <?php if ($order['cancellation_reason']): ?>
                        <div class="sx-alert sx-alert-warning"><i class="fa-solid fa-quote-left"></i><div><?= h($order['cancellation_reason']) ?></div></div>
                    <?php endif; ?>
                </section>
            <?php elseif ($display_status === 'cancelled'): ?>
                <section class="sx-card od-action">
                    <h2>Order cancelled</h2>
                    <p style="margin-bottom: 0">This order has been cancelled and will not be processed.</p>
                </section>
            <?php elseif ($display_status === 'delivered'): ?>
                <section class="sx-card od-action">
                    <h2>Did you receive it?</h2>
                    <p>Confirm once all items arrived in good condition.</p>
                    <button type="button" class="sx-btn sx-btn-accent sx-btn-block" id="openConfirm">
                        <i class="fa-solid fa-circle-check"></i> Order received
                    </button>
                </section>
            <?php endif; ?>

            <!-- Delivery & payment -->
            <section class="sx-card">
                <h2 class="od-card-title"><i class="fa-solid fa-truck-fast"></i> Delivery &amp; payment</h2>
                <dl class="od-info">
                    <div>
                        <dt>Delivery date</dt>
                        <dd><?= !empty($order['delivery_date']) ? date('F j, Y', strtotime($order['delivery_date'])) : 'Not yet scheduled' ?></dd>
                    </div>
                    <?php if (!empty($order['customer_name'])): ?>
                        <div><dt>Recipient</dt><dd><?= h($order['customer_name']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($order['phone_number'])): ?>
                        <div><dt>Phone</dt><dd><?= h($order['phone_number']) ?></dd></div>
                    <?php endif; ?>
                    <?php if (!empty($order['address'])): ?>
                        <div><dt>Address</dt><dd><?= h($order['address']) ?></dd></div>
                    <?php endif; ?>
                    <div>
                        <dt>Payment</dt>
                        <dd>
                            <?= h($order['payment_terms'] ?: ($order['payment_method'] === 'COD' ? 'Cash on delivery' : $order['payment_method'])) ?>
                            <?php if ($isPO): ?> · Purchase order<?php endif; ?>
                        </dd>
                    </div>
                </dl>
                <?php if ($isPO && !empty($order['notes'])): ?>
                    <details class="od-po-notes" style="margin-top: var(--sx-space-4)">
                        <summary>Purchase order details</summary>
                        <pre><?= h($order['notes']) ?></pre>
                    </details>
                <?php endif; ?>
            </section>

            <a href="/sweepxpress/customers/my_orders.php" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to My Orders</a>
        </div>
    </div>
</div>

<?php if ($display_status === 'pending'): ?>
    <dialog class="co-dialog" id="cancelDialog" aria-labelledby="cancelTitle">
        <form action="/sweepxpress/customers/cancel_order.php" method="POST">
            <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
            <div class="co-dialog-head">
                <h3 id="cancelTitle">Cancel order #<?= (int)$order_id ?>?</h3>
                <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="co-dialog-body">
                <div class="sx-field" style="margin-bottom: 0">
                    <label class="sx-label" for="cancellationReason">Reason for cancelling</label>
                    <textarea class="sx-textarea" id="cancellationReason" name="reason" required minlength="10"
                              placeholder="Please explain why you need to cancel this order (at least 10 characters)"></textarea>
                </div>
            </div>
            <div class="co-dialog-foot">
                <button type="button" class="sx-btn sx-btn-ghost" data-close>Keep order</button>
                <button type="submit" class="sx-btn sx-btn-danger">Submit request</button>
            </div>
        </form>
    </dialog>
<?php elseif ($display_status === 'delivered'): ?>
    <dialog class="co-dialog" id="confirmDialog" aria-labelledby="confirmTitle">
        <!-- confirm_received.php: received = yes | no (dati "status", kaya laging "Invalid action") -->
        <form action="/sweepxpress/customers/confirm_received.php" method="POST">
            <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
            <div class="co-dialog-head">
                <h3 id="confirmTitle">Confirm order received?</h3>
                <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="co-dialog-body">
                <p style="margin: 0">By confirming, you verify that all items in Order #<?= (int)$order_id ?> arrived in good condition. This completes your order.</p>
            </div>
            <div class="co-dialog-foot">
                <button type="submit" name="received" value="no" class="sx-btn sx-btn-ghost">There's an issue</button>
                <button type="submit" name="received" value="yes" class="sx-btn sx-btn-accent">Yes, I received it</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<script>
// Cancel / confirm dialogs (native <dialog>: may Esc at backdrop click)
document.addEventListener('DOMContentLoaded', function () {
    [['openCancel', 'cancelDialog'], ['openConfirm', 'confirmDialog']].forEach(([btnId, dialogId]) => {
        const btn = document.getElementById(btnId);
        const dialog = document.getElementById(dialogId);
        if (!btn || !dialog) return;
        btn.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
