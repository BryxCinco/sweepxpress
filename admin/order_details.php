<?php
// admin/order_details.php — iisang admin page para sa isang order (dati 5 kopya:
// order_details, _b2b, _b2c, _complete, _deliveries — redirect na lang sila rito).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php'; // sx_flash, sx_csrf_*
require_once __DIR__ . '/../includes/order_ops.php';

$orderId = (int)($_GET['id'] ?? 0);

// Saang listahan galing (para sa Back link)
$backTargets = [
    'all'          => ['allorders.php', 'All orders'],
    'list'         => ['allorders.php?type=b2c', 'Retail orders'],
    'pending'      => ['allorders.php?type=b2b', 'Business orders'],
    'completed'    => ['allorders.php?tab=completed', 'Completed orders'],
    'cancellation' => ['orders_cancellation.php', 'Cancellation requests'],
    'archive'      => ['allorders.php', 'All orders'],
    'deliveries'   => ['deliveries.php', 'Deliveries'],
];
$from = isset($backTargets[$_GET['from'] ?? '']) ? $_GET['from'] : 'all';
[$backUrl, $backLabel] = $backTargets[$from];
$selfUrl = "order_details.php?id={$orderId}" . ($from !== 'all' ? "&from={$from}" : '');

// =======================================================================
// 1. UPDATE STATUS (bago ang header; PRG)
// =======================================================================
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    if (!sx_csrf_ok()) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $result = sx_update_order_status($pdo, $orderId, $_POST['status'], $_POST['delivery_date'] ?? null, $_SESSION['user']['name'] ?? 'Admin');

            // Email sa customer kapag nagbago ang status (dati: b2b at b2c lang ang nag-e-email)
            if ($result['old'] !== $result['new']) {
                require_once __DIR__ . '/../includes/order_status_notifier.php';
                notifyCustomerOrderStatus($orderId, $result['new'], $pdo);
            }

            $msg = $result['old'] === $result['new'] ? 'Delivery details saved.' : 'Status updated to ' . (sx_order_statuses()[$result['new']][0] ?? $result['new']) . '.';
            if ($result['stock']) {
                $msg .= $result['new'] === 'cancelled'
                    ? " {$result['stock']} unit(s) returned to stock."
                    : " {$result['stock']} unit(s) taken from stock again.";
            }
            sx_flash(['success', $msg]);
            header("Location: {$selfUrl}");
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log("Order status update failed: " . $e->getMessage());
            $error = 'Could not update the order. Please try again.';
        }
    }
}

// =======================================================================
// 2. FETCH
// =======================================================================
$stmtOrder = $pdo->prepare("
    SELECT o.*, u.name AS account_name, u.email AS account_email, u.role AS account_role
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.id = ?
");
$stmtOrder->execute([$orderId]);
$order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

if ($order) {
    $stmtItems = $pdo->prepare("
        SELECT oi.product_id, oi.quantity, oi.price, p.name, p.sku, p.image_path,
               COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id = oi.product_id), 0) AS stock
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
        ORDER BY oi.id
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $stmtDelivery = $pdo->prepare("SELECT * FROM deliveries WHERE order_id = ? ORDER BY id LIMIT 1");
    $stmtDelivery->execute([$orderId]);
    $delivery = $stmtDelivery->fetch(PDO::FETCH_ASSOC) ?: [];

    $status      = $order['status'];
    $isCompleted = $status === 'completed';
    $isPO        = $order['order_type'] === 'B2B' || $status === 'Pending PO';
    $subtotal    = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));
    $deliveryFee = max(0, (float)$order['total'] - $subtotal);

    // PO: due date = petsa ng order + Net N araw (ito rin ang max na delivery date, gaya ng dati)
    $poDue = null;
    if (preg_match('/^Net (\d+)$/i', $order['payment_terms'] ?? '', $m)) {
        $poDue = date('Y-m-d', strtotime($order['created_at'] . " +{$m[1]} days"));
    }

    // Mga status na puwedeng piliin
    $choices = $isCompleted ? ['completed'] : array_values(array_filter(
        ['Pending PO', 'pending', 'preparing', 'delivered', 'completed', 'cancelled'],
        fn($s) => $s !== 'Pending PO' || $status === 'Pending PO'
    ));
    $currentChoice = $status === 'shipped' ? 'preparing' : ($status === 'cancellation_requested' ? 'pending' : $status);
}

$flash = sx_flash();
$csrf  = sx_csrf_token();
$placeholder = '/sweepxpress/assets/placeholder.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ADMIN ORDER DETAILS (Phase 9f) — tokens + sx-pill / sx-table mula sa assets/style.css
   ============================================================ */
.ao { max-width: 1300px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ao-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ao-head h1 { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-3); margin: 0; }
.ao-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.ao-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.ao-tag { padding: 3px 10px; border-radius: var(--sx-radius-pill); background: var(--sx-primary-soft); color: var(--sx-primary); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); }
.ao-layout { display: grid; gap: var(--sx-space-5); align-items: start; }
.ao-main, .ao-side { display: grid; gap: var(--sx-space-5); min-width: 0; }

.ao-items { padding: 0; overflow: hidden; }
.ao-items .pf-title { padding: var(--sx-space-5) var(--sx-space-5) 0; }
.ao-item { display: grid; grid-template-columns: 52px 1fr auto; align-items: center; gap: var(--sx-space-3); padding: var(--sx-space-3) var(--sx-space-5); border-top: 1px solid var(--sx-border-light); }
.ao-item img { width: 52px; height: 52px; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.ao-item a { color: var(--sx-dark); font-weight: var(--sx-weight-bold); text-decoration: none; }
.ao-item a:hover { color: var(--sx-primary); }
.ao-item small { display: block; color: var(--sx-muted); font-size: var(--sx-text-xs); }
.ao-item .amt { font-weight: var(--sx-weight-bold); white-space: nowrap; }
.ao-totals { display: grid; gap: 6px; padding: var(--sx-space-4) var(--sx-space-5); background: var(--sx-surface-alt); font-size: var(--sx-text-sm); }
.ao-totals div { display: flex; justify-content: space-between; color: var(--sx-muted); }
.ao-totals .grand { padding-top: 8px; border-top: 1px dashed var(--sx-border); color: var(--sx-dark); font-size: var(--sx-text-base); }
.ao-totals .grand strong { font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-primary); }

.ao-info { display: grid; gap: var(--sx-space-3); margin: 0; }
.ao-info div { display: grid; gap: 2px; }
.ao-info dt { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); letter-spacing: 0.04em; text-transform: uppercase; color: var(--sx-muted); }
.ao-info dd { margin: 0; color: var(--sx-dark); overflow-wrap: anywhere; }
.ao-info dd a { color: var(--sx-primary); }
.ao-notes summary { cursor: pointer; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); color: var(--sx-primary); }
.ao-notes pre { margin: var(--sx-space-2) 0 0; padding: var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); font-family: inherit; font-size: var(--sx-text-xs); white-space: pre-wrap; }
.ao-lock { display: flex; gap: var(--sx-space-2); align-items: center; margin-bottom: var(--sx-space-3); color: var(--sx-muted); font-size: var(--sx-text-sm); }

@media (min-width: 992px) {
    .ao-layout { grid-template-columns: 1fr 360px; }
    .ao-side { position: sticky; top: calc(var(--sx-nav-h, 70px) + var(--sx-space-4)); }
}
</style>

<div class="ao sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="<?= h($backUrl) ?>"><?= h($backLabel) ?></a> <span aria-hidden="true">/</span> <span>Order #<?= $orderId ?></span>
    </nav>

<?php if (!$order): ?>
    <div class="sx-card sx-empty">
        <i class="fa-solid fa-receipt"></i>
        <h3>Order not found</h3>
        <p>Check the order number and try again.</p>
        <a href="<?= h($backUrl) ?>" class="sx-btn sx-btn-primary sx-btn-sm" style="margin-top: var(--sx-space-3)">Back to <?= h(strtolower($backLabel)) ?></a>
    </div>
<?php else: ?>
    <div class="ao-head">
        <div>
            <h1 class="sx-heading-lg">
                Order #<?= $orderId ?>
                <?= sx_order_status_pill($status) ?>
                <?php if ($isPO): ?><span class="ao-tag">Purchase order</span><?php endif; ?>
            </h1>
            <p>Placed <?= date('F j, Y \a\t g:i A', strtotime($order['created_at'])) ?></p>
        </div>
        <div class="ao-head-actions">
            <a href="adminreceipt.php?order_id=<?= $orderId ?>" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-receipt"></i> Receipt</a>
            <a href="generate_order_pdf.php?id=<?= $orderId ?>" target="_blank" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-file-pdf"></i> PDF</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-check"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= h($error) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($status === 'cancellation_requested'): ?>
        <div class="sx-alert sx-alert-warning" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>The customer asked to cancel this order.</strong>
                <?php if ($order['cancellation_reason']): ?> Reason: “<?= h($order['cancellation_reason']) ?>”.<?php endif; ?>
                <a href="orders_cancellation.php">Review the request</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="ao-layout">
        <div class="ao-main">
            <section class="sx-card ao-items">
                <h2 class="pf-title">Items (<?= array_sum(array_column($items, 'quantity')) ?> units)</h2>
                <?php foreach ($items as $it): ?>
                    <div class="ao-item">
                        <img src="<?= h($it['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                        <div>
                            <?php if ($it['name'] !== null): ?>
                                <a href="product_history.php?product_id=<?= (int)$it['product_id'] ?>"><?= h($it['name']) ?></a>
                            <?php else: ?>
                                <strong>Product no longer available</strong>
                            <?php endif; ?>
                            <small><?= (int)$it['quantity'] ?> × ₱<?= number_format((float)$it['price'], 2) ?><?= $it['sku'] ? ' · ' . h($it['sku']) : '' ?> · <?= number_format((int)$it['stock']) ?> in stock now</small>
                        </div>
                        <span class="amt">₱<?= number_format($it['price'] * $it['quantity'], 2) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <div class="sx-empty"><i class="fa-solid fa-box-open"></i><h3>No items</h3></div>
                <?php endif; ?>
                <div class="ao-totals">
                    <div><span>Items subtotal</span><span>₱<?= number_format($subtotal, 2) ?></span></div>
                    <div><span>Delivery fee</span><span><?= $deliveryFee > 0 ? '₱' . number_format($deliveryFee, 2) : 'Free' ?></span></div>
                    <div class="grand"><span>Order total</span><strong>₱<?= number_format((float)$order['total'], 2) ?></strong></div>
                </div>
            </section>

            <section class="sx-card">
                <h2 class="pf-title">Customer</h2>
                <dl class="ao-info">
                    <div>
                        <dt>Account</dt>
                        <dd>
                            <?php if ($order['user_id']): ?>
                                <a href="user_actions.php?id=<?= (int)$order['user_id'] ?>"><?= h($order['account_name'] ?: 'User #' . $order['user_id']) ?></a>
                                · <?= h(ucfirst($order['account_role'] ?? 'customer')) ?>
                            <?php else: ?>
                                Guest
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div><dt>Email</dt><dd><?= h($order['account_email'] ?: '—') ?></dd></div>
                    <div><dt>Name on order</dt><dd><?= h($order['customer_name'] ?: '—') ?></dd></div>
                    <div><dt>Phone</dt><dd><?= h($order['phone_number'] ?: '—') ?></dd></div>
                    <div><dt>Delivery address</dt><dd><?= nl2br(h($order['address'] ?: '—')) ?></dd></div>
                </dl>
                <?php if (trim($order['notes'] ?? '') !== ''): ?>
                    <details class="ao-notes" style="margin-top: var(--sx-space-4)" <?= $isPO ? 'open' : '' ?>>
                        <summary><?= $isPO ? 'Purchase order details' : 'Order notes' ?></summary>
                        <pre><?= h($order['notes']) ?></pre>
                    </details>
                <?php endif; ?>
            </section>
        </div>

        <aside class="ao-side">
            <form method="post" class="sx-card">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <h2 class="pf-title">Fulfilment</h2>
                <?php if ($isCompleted): ?>
                    <p class="ao-lock"><i class="fa-solid fa-lock"></i> Completed orders are locked.</p>
                <?php endif; ?>
                <div class="sx-field">
                    <label class="sx-label" for="ao-status">Status</label>
                    <select id="ao-status" name="status" class="sx-select" <?= $isCompleted ? 'disabled' : '' ?>>
                        <?php foreach ($choices as $s): ?>
                            <option value="<?= h($s) ?>" <?= $s === $currentChoice ? 'selected' : '' ?>><?= h(sx_order_statuses()[$s][0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sx-hint">Cancelling returns the items to stock. The customer gets an email when the status changes.</span>
                </div>
                <div class="sx-field">
                    <label class="sx-label" for="ao-date">Delivery date</label>
                    <!-- Walang min (dati min=ngayon kaya hindi na ma-save ang lumang order) -->
                    <input type="date" id="ao-date" name="delivery_date" class="sx-input" value="<?= h($delivery['delivery_date'] ?? '') ?>"
                           <?= $poDue ? 'max="' . h($poDue) . '"' : '' ?> <?= $isCompleted ? 'disabled' : '' ?>>
                    <?php if ($poDue): ?><span class="sx-hint">PO due <?= date('M j, Y', strtotime($poDue)) ?> (<?= h($order['payment_terms']) ?>) — latest delivery date.</span><?php endif; ?>
                </div>
                <button type="submit" class="sx-btn sx-btn-primary sx-btn-block" <?= $isCompleted ? 'disabled' : '' ?>>
                    <i class="fa-solid fa-truck-fast"></i> Update order
                </button>
            </form>

            <section class="sx-card">
                <h2 class="pf-title">Payment</h2>
                <dl class="ao-info">
                    <div><dt>Method</dt><dd><?= h($order['payment_method'] === 'COD' ? 'Cash on delivery' : ($order['payment_method'] ?: '—')) ?></dd></div>
                    <div><dt>Payment status</dt><dd><?= h(ucfirst($order['payment_status'] ?? '—')) ?></dd></div>
                    <?php if ($order['payment_terms']): ?>
                        <div><dt>Terms</dt><dd><?= h($order['payment_terms']) ?><?= $poDue ? ' · due ' . date('M j, Y', strtotime($poDue)) : '' ?></dd></div>
                    <?php endif; ?>
                </dl>
            </section>

            <a href="<?= h($backUrl) ?>" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to <?= h(strtolower($backLabel)) ?></a>
        </aside>
    </div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
