<?php
// admin/orders_cancellation.php — mga request ng customer na i-cancel ang order (approve / reject)

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/../includes/order_ops.php';
require_once __DIR__ . '/../vendor/autoload.php'; 
if (session_status() === PHP_SESSION_NONE) session_start();

use PHPMailer\PHPMailer\PHPMailer; 
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// Mail credentials galing sa includes/email_config.php (.env) — nilo-load sa loob ng send_cancellation_email().

function send_cancellation_email($pdo, $recipientEmail, $recipientName, $orderId, $action, $adminNote) {
    $mail = null;

    try {
        require_once __DIR__ . '/../includes/email_config.php';
        $mail = make_mailer();
        $mail->setFrom(MAIL_USERNAME, 'SweepXpress Admin Team');
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addBCC(MAIL_USERNAME, 'Admin Team'); // admin copy = mail account

        $mail->isHTML(true);
        $orderLink = rtrim(env('APP_URL', 'http://localhost/sweepxpress'), '/')
            . "/customers/order_details.php?id=" . (int)$orderId;
        
        if ($action === 'approved') {
            $mail->Subject = 'Order Cancellation Confirmed (Order #' . $orderId . ')';
            $body = "
                <h2>✅ Cancellation Approved for Order #{$orderId}</h2>
                <p>Dear " . h($recipientName) . ",</p>
                <p>This is to confirm that your cancellation request for Order #{$orderId} has been APPROVED by the administration.</p>
                <p>The order status is now Cancelled.</p>
                <p><strong>Admin Note:</strong> " . (empty($adminNote) ? 'N/A' : h($adminNote)) . "</p>
                <p>You can view your order details here: <a href='" . h($orderLink) . "'>View Order #{$orderId}</a></p>
                <p>Thank you for choosing SweepXpress.</p>
            ";
        } else {
            $mail->Subject = 'Update on Cancellation Request (Order #' . $orderId . ')';
            $body = "
                <h2>❌ Cancellation Request Rejected for Order #{$orderId}</h2>
                <p>Dear " . h($recipientName) . ",</p>
                <p>We regret to inform you that your cancellation request for Order #{$orderId} has been REJECTED by the administration.</p>
                <p>The order status has been reset to Pending.</p>
                <p><strong>Admin Note:</strong> " . (empty($adminNote) ? 'No note provided.' : h($adminNote)) . "</p>
                <p>If you have any questions, please reply to this email.</p>
                <p>You can view your order details here: <a href='" . h($orderLink) . "'>View Order #{$orderId}</a></p>
            ";
        }

        $mail->Body = $body;
        $mail->AltBody = strip_tags($body); 

        $mail->send();
        return true;

    } catch (Throwable $e) {
        $error = $mail ? $mail->ErrorInfo : $e->getMessage();
        error_log("Email Error for Order #{$orderId} ({$action}): {$error}");
        return true; 
    }
}

require_once __DIR__ . '/../includes/admin_products.php'; // sx_flash, sx_csrf_*

// =================================================================================
// APPROVE / REJECT (bago ang header; PRG — dati SweetAlert + exit sa gitna ng page)
// Pareho nang dumadaan sa includes/order_ops.php: approve = ibinabalik ang stock.
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancellation_action'])) {
    $order_id   = (int)($_POST['order_id'] ?? 0);
    $action     = $_POST['cancellation_action'] === 'approve' ? 'approve' : 'reject';
    $admin_note = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 500);

    if (!sx_csrf_ok()) {
        sx_flash(['error', 'Your session expired. Please try again.']);
    } else {
        $stmt_user = $pdo->prepare("SELECT u.name, u.email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
        $stmt_user->execute([$order_id]);
        $user_info = $stmt_user->fetch(PDO::FETCH_ASSOC) ?: [];

        try {
            $res = sx_update_order_status($pdo, $order_id, $action === 'approve' ? 'cancelled' : 'pending', null,
                $_SESSION['user']['name'] ?? 'Admin', [
                    'admin_note'          => $admin_note,
                    'cancellation_reason' => null,
                    'require_status'      => 'cancellation_requested',
                ]);

            if (!empty($user_info['email'])) {
                send_cancellation_email($pdo, $user_info['email'], $user_info['name'] ?? 'Customer', $order_id, $action === 'approve' ? 'approved' : 'rejected', $admin_note);
            }
            sx_flash(['success', $action === 'approve'
                ? "Order #{$order_id} cancelled." . ($res['stock'] ? " {$res['stock']} unit(s) returned to stock." : '')
                : "Request rejected. Order #{$order_id} is back to Pending."]);
        } catch (RuntimeException $e) {
            sx_flash(['error', $e->getMessage()]);
        } catch (Throwable $e) {
            error_log("Cancellation {$action} error for Order #{$order_id}: " . $e->getMessage());
            sx_flash(['error', 'Could not update the order because of a database error. Please try again.']);
        }
    }
    header('Location: orders_cancellation.php');
    exit;
}

$cancellation_requests = $pdo->query("
    SELECT o.id, o.total, o.created_at, o.cancellation_reason, o.user_id, o.order_type, o.payment_terms,
           u.name AS full_name, u.email,
           (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', COALESCE(p.name, 'Deleted product')) ORDER BY oi.id SEPARATOR ', ')
              FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = o.id) AS items
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.status = 'cancellation_requested'
    ORDER BY o.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$recent = $pdo->query("
    SELECT o.id, o.admin_note, u.name AS full_name
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.status = 'cancelled' AND o.admin_note IS NOT NULL AND o.admin_note <> ''
    ORDER BY o.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$flash = sx_flash();
$csrf  = sx_csrf_token();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   CANCELLATION REQUESTS (Phase 9f) — tokens + sx-card / co-dialog mula sa assets/style.css
   ============================================================ */
.cr { max-width: 1100px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.cr-list { display: grid; gap: var(--sx-space-4); }
.cr-card { display: grid; gap: var(--sx-space-3); }
.cr-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-2); }
.cr-top a { font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); text-decoration: none; }
.cr-top a:hover { color: var(--sx-primary); }
.cr-top small { color: var(--sx-muted); }
.cr-reason { margin: 0; padding: var(--sx-space-3) var(--sx-space-4); border-left: 3px solid var(--sx-status-pending); border-radius: var(--sx-radius-md); background: var(--sx-status-pending-bg); color: var(--sx-dark); }
.cr-meta { display: flex; flex-wrap: wrap; gap: var(--sx-space-2) var(--sx-space-5); color: var(--sx-muted); font-size: var(--sx-text-sm); }
.cr-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--sx-space-2); padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); }
.cr-recent ul { margin: 0; padding: 0; list-style: none; }
.cr-recent li { display: flex; justify-content: space-between; gap: var(--sx-space-3); padding: 8px 0; border-bottom: 1px solid var(--sx-border-light); font-size: var(--sx-text-sm); }
.cr-recent li:last-child { border-bottom: none; }
</style>

<div class="cr sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="allorders.php">Orders</a> <span aria-hidden="true">/</span> <span>Cancellation requests</span>
    </nav>
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Cancellation requests</h1>
            <p>Approving cancels the order and returns its items to stock. The customer is emailed either way.</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($cancellation_requests): ?>
        <div class="cr-list">
            <?php foreach ($cancellation_requests as $r): ?>
                <article class="sx-card cr-card">
                    <div class="cr-top">
                        <div>
                            <a href="order_details.php?id=<?= (int)$r['id'] ?>&from=cancellation">Order #<?= (int)$r['id'] ?></a>
                            <small>· <?= h($r['full_name'] ?: 'Guest') ?><?= $r['email'] ? ' · ' . h($r['email']) : '' ?></small>
                        </div>
                        <strong>₱<?= number_format((float)$r['total'], 2) ?></strong>
                    </div>
                    <p class="cr-reason"><i class="fa-solid fa-quote-left" aria-hidden="true"></i> <?= h($r['cancellation_reason'] ?: 'No reason given') ?></p>
                    <div class="cr-meta">
                        <span><i class="fa-regular fa-calendar"></i> Ordered <?= date('M j, Y', strtotime($r['created_at'])) ?></span>
                        <?php if ($r['payment_terms']): ?><span><i class="fa-solid fa-file-invoice"></i> <?= h($r['payment_terms']) ?></span><?php endif; ?>
                        <span><i class="fa-solid fa-box"></i> <?= h($r['items'] ?: 'No items') ?></span>
                    </div>
                    <div class="cr-actions">
                        <button type="button" class="sx-btn sx-btn-outline sx-btn-sm" data-cr="reject" data-id="<?= (int)$r['id'] ?>">Reject</button>
                        <button type="button" class="sx-btn sx-btn-danger sx-btn-sm" data-cr="approve" data-id="<?= (int)$r['id'] ?>">Approve cancellation</button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-circle-check"></i>
            <h3>No pending requests</h3>
            <p>Customer cancellation requests will show up here.</p>
        </div>
    <?php endif; ?>

    <?php if ($recent): ?>
        <section class="sx-card cr-recent" style="margin-top: var(--sx-space-5)">
            <h2 class="pf-title">Recently cancelled</h2>
            <ul>
                <?php foreach ($recent as $c): ?>
                    <li>
                        <a href="order_details.php?id=<?= (int)$c['id'] ?>&from=cancellation">#<?= (int)$c['id'] ?> · <?= h($c['full_name'] ?: 'Guest') ?></a>
                        <span class="sx-hint"><?= h(mb_strimwidth($c['admin_note'], 0, 60, '…')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
</div>

<dialog class="co-dialog" id="crDialog" aria-labelledby="crTitle">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="order_id" id="crOrder">
        <input type="hidden" name="cancellation_action" id="crAction">
        <div class="co-dialog-head">
            <h3 id="crTitle"></h3>
            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="co-dialog-body">
            <div class="sx-field" style="margin-bottom: 0">
                <label class="sx-label" for="crNote">Note to the customer <span class="sx-hint">(optional)</span></label>
                <textarea id="crNote" name="admin_note" class="sx-textarea" rows="3" maxlength="500"
                          placeholder="e.g. Refund processed / Order already out for delivery"></textarea>
            </div>
        </div>
        <div class="co-dialog-foot">
            <button type="button" class="sx-btn sx-btn-ghost" data-close>Cancel</button>
            <button type="submit" class="sx-btn" id="crSubmit"></button>
        </div>
    </form>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('crDialog');
    document.querySelectorAll('[data-cr]').forEach((btn) => btn.addEventListener('click', () => {
        const approve = btn.dataset.cr === 'approve';
        document.getElementById('crOrder').value = btn.dataset.id;
        document.getElementById('crAction').value = btn.dataset.cr;
        document.getElementById('crTitle').textContent = (approve ? 'Approve cancellation of #' : 'Reject cancellation of #') + btn.dataset.id;
        const submit = document.getElementById('crSubmit');
        submit.textContent = approve ? 'Approve & return stock' : 'Reject request';
        submit.className = 'sx-btn ' + (approve ? 'sx-btn-danger' : 'sx-btn-primary');
        dialog.showModal();
    }));
    dialog.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
