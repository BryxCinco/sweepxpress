<?php
// admin/user_actions.php — isang account: profile, role, suspend, delete, orders at activity.
// Lahat ng aksyon ay POST + CSRF dito (dati: GET links papunta sa delete.php / suspend.php / unsuspend.php
// at fetch sa edit_role.php — redirect na lang ang mga iyon ngayon).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php'; // sx_flash, sx_csrf_*
require_once __DIR__ . '/../includes/log_helper.php';
require_once __DIR__ . '/../includes/order_ops.php';     // sx_order_status_pill

$userId  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$adminId = (int)($_SESSION['user']['id'] ?? 0);
$isSelf  = $userId === $adminId;

$load = function () use ($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, name, username, email, role, phone, created_at, profile_image, suspended_until, streetaddress, city FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$user = $userId > 0 ? $load() : null;
if (!$user) {
    sx_flash(['error', 'Account not found.']);
    header("Location: customer_management.php");
    exit;
}

// =================================================================================
// ACTIONS
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = "user_actions.php?id={$userId}";
    $name = $user['name'];

    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $isLastAdmin = $user['role'] === 'admin' && $adminCount <= 1;

    try {
        if (!sx_csrf_ok()) {
            throw new RuntimeException('Your session expired. Please try again.');
        }

        if ($action === 'role') {
            $newRole = $_POST['role'] ?? '';
            if (!in_array($newRole, ['customer', 'business', 'admin'], true)) throw new RuntimeException('Choose a valid role.');
            if ($newRole === $user['role']) throw new RuntimeException("{$name} is already a {$newRole}.");
            if ($isSelf) throw new RuntimeException("You can't change your own role.");
            if ($isLastAdmin) throw new RuntimeException("This is the only admin account, so its role can't be changed.");
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);
            log_user_action($pdo, $userId, 'ROLE_CHANGED', "Role changed from {$user['role']} to {$newRole}", $adminId);
            sx_flash(['success', "{$name} is now a {$newRole}."]);

        } elseif ($action === 'suspend') {
            $days = (int)($_POST['days'] ?? 30);
            if (!in_array($days, [1, 7, 30, 90, 365], true)) throw new RuntimeException('Choose a valid suspension length.');
            if ($isSelf) throw new RuntimeException("You can't suspend your own account.");
            if ($isLastAdmin) throw new RuntimeException("The only admin account can't be suspended.");
            $pdo->prepare("UPDATE users SET suspended_until = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id = ?")->execute([$days, $userId]);
            log_user_action($pdo, $userId, 'ACCOUNT_SUSPENDED', "Suspended for {$days} day(s)", $adminId);
            sx_flash(['success', "{$name} is suspended for {$days} day" . ($days === 1 ? '' : 's') . ". They can't sign in until then."]);

        } elseif ($action === 'unsuspend') {
            $pdo->prepare("UPDATE users SET suspended_until = NULL WHERE id = ?")->execute([$userId]);
            log_user_action($pdo, $userId, 'ACCOUNT_UNSUSPENDED', 'Suspension lifted', $adminId);
            sx_flash(['success', "{$name} can sign in again."]);

        } elseif ($action === 'delete') {
            if ($isSelf) throw new RuntimeException("You can't delete your own account.");
            if ($isLastAdmin) throw new RuntimeException("The only admin account can't be deleted.");
            if (trim($_POST['confirm_email'] ?? '') !== $user['email']) throw new RuntimeException('Type the account email exactly to confirm deletion.');
            // Dati: binubura muna ang lahat ng orders ng user (nawawala ang sales history).
            // orders.user_id ay ON DELETE SET NULL, kaya nananatili ang mga order bilang guest order.
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            log_user_action($pdo, $userId, 'ACCOUNT_DELETED', "Account {$user['email']} deleted; orders kept", $adminId);
            sx_flash(['success', "{$name}'s account was deleted. Their orders were kept."]);
            header("Location: customer_management.php");
            exit;
        }
    } catch (RuntimeException $e) {
        sx_flash(['error', $e->getMessage()]);
    } catch (PDOException $e) {
        error_log("User action {$action} failed for #{$userId}: " . $e->getMessage());
        sx_flash(['error', 'Could not save the change. Please try again.']);
    }
    header("Location: {$back}");
    exit;
}

// =================================================================================
// DISPLAY
// =================================================================================
$orders = $pdo->prepare("SELECT id, status, total, created_at, payment_terms FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$orders->execute([$userId]);
$orderHistory = $orders->fetchAll(PDO::FETCH_ASSOC);

$stats = $pdo->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN total END), 0) AS spent,
           SUM(status = 'cancelled') AS cancelled,
           MAX(created_at) AS last_order
    FROM orders WHERE user_id = ?
");
$stats->execute([$userId]);
$stats = $stats->fetch(PDO::FETCH_ASSOC);

$logs = $pdo->prepare("SELECT action_type, description, ip_address, created_at FROM user_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
$logs->execute([$userId]);
$recentLogs = $logs->fetchAll(PDO::FETCH_ASSOC);

$isSuspended = !empty($user['suspended_until']) && strtotime($user['suspended_until']) > time();
$adminCount  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$locked      = $isSelf || ($user['role'] === 'admin' && $adminCount <= 1);
$flash = sx_flash();
$csrf  = sx_csrf_token();
$avatar = $user['profile_image'] ?: '/sweepxpress/assets/profile-icon.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ACCOUNT (Phase 9g) — tokens + sx-card / sx-table / co-dialog mula sa assets/style.css
   ============================================================ */
.ua { max-width: 1200px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ua-profile { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-4); margin-bottom: var(--sx-space-5); }
.ua-profile img { width: 80px; height: 80px; border-radius: 50%; background: var(--sx-surface-alt); object-fit: cover; box-shadow: 0 0 0 4px var(--sx-surface), 0 0 0 5px var(--sx-border-light); }
.ua-profile h1 { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); margin: 0; }
.ua-profile p { margin: 4px 0 0; color: var(--sx-muted); }
.ua-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ua-kpi { padding: var(--sx-space-4); }
.ua-kpi span { display: block; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.ua-kpi strong { display: block; margin-top: 4px; font-size: 1.3rem; font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ua-layout { display: grid; gap: var(--sx-space-5); align-items: start; }
.ua-main, .ua-side { display: grid; gap: var(--sx-space-5); min-width: 0; }
.ua-table .num { text-align: right; white-space: nowrap; }
.ua-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); white-space: nowrap; }
.ua-log { margin: 0; padding: 0; list-style: none; }
.ua-log li { padding: 10px 0; border-bottom: 1px solid var(--sx-border-light); font-size: var(--sx-text-sm); }
.ua-log li:last-child { border-bottom: none; }
.ua-log strong { color: var(--sx-dark); }
.ua-log small { display: block; color: var(--sx-muted); }
.ua-row { display: flex; gap: var(--sx-space-2); }
.ua-row .sx-select { flex: 1; }
.ua-danger { border: 1px solid var(--sx-status-cancelled-bg); }
.ua-note { margin: 0 0 var(--sx-space-3); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.ua-info { display: grid; gap: var(--sx-space-3); margin: 0; }
.ua-info div { display: grid; gap: 2px; }
.ua-info dt { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); letter-spacing: 0.04em; text-transform: uppercase; color: var(--sx-muted); }
.ua-info dd { margin: 0; color: var(--sx-dark); overflow-wrap: anywhere; }
@media (min-width: 768px) { .ua-kpis { grid-template-columns: repeat(4, 1fr); } }
@media (min-width: 992px) { .ua-layout { grid-template-columns: 1fr 340px; } }
</style>

<div class="ua sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="customer_management.php">Customers</a> <span aria-hidden="true">/</span> <span><?= h($user['name']) ?></span>
    </nav>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="ua-profile">
        <img src="<?= h($avatar) ?>" alt="" onerror="this.src='/sweepxpress/assets/profile-icon.jpg'">
        <div>
            <h1 class="sx-heading-lg">
                <?= h($user['name']) ?>
                <?php if ($isSuspended): ?><span class="sx-pill sx-pill-cancelled">Suspended</span><?php endif; ?>
                <?php if ($isSelf): ?><span class="sx-pill sx-pill-preparing">You</span><?php endif; ?>
            </h1>
            <p><?= h(ucfirst($user['role'])) ?> · <a href="mailto:<?= h($user['email']) ?>"><?= h($user['email']) ?></a> · joined <?= date('M j, Y', strtotime($user['created_at'])) ?></p>
        </div>
    </div>

    <div class="ua-kpis">
        <div class="sx-card ua-kpi"><span>Orders</span><strong><?= (int)$stats['orders'] ?></strong></div>
        <div class="sx-card ua-kpi"><span>Spent (delivered)</span><strong>₱<?= number_format((float)$stats['spent'], 2) ?></strong></div>
        <div class="sx-card ua-kpi"><span>Cancelled</span><strong><?= (int)$stats['cancelled'] ?></strong></div>
        <div class="sx-card ua-kpi"><span>Last order</span><strong style="font-size: 1rem"><?= $stats['last_order'] ? date('M j, Y', strtotime($stats['last_order'])) : '—' ?></strong></div>
    </div>

    <div class="ua-layout">
        <div class="ua-main">
            <section class="sx-card" style="padding: 0; overflow: hidden">
                <h2 class="pf-title" style="padding: var(--sx-space-5) var(--sx-space-5) 0">Recent orders</h2>
                <?php if ($orderHistory): ?>
                    <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                        <table class="sx-table ua-table">
                            <thead><tr><th>Order</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($orderHistory as $o): ?>
                                    <tr>
                                        <td><a href="order_details.php?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td>
                                        <td class="muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                                        <td class="num">₱<?= number_format((float)$o['total'], 2) ?></td>
                                        <td><?= sx_order_status_pill($o['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ((int)$stats['orders'] > count($orderHistory)): ?>
                        <p style="padding: 0 var(--sx-space-5) var(--sx-space-4); margin: var(--sx-space-3) 0 0">
                            <a href="allorders.php?q=<?= urlencode($user['email']) ?>">See all <?= (int)$stats['orders'] ?> orders →</a>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="sx-empty"><i class="fa-solid fa-receipt"></i><h3>No orders yet</h3></div>
                <?php endif; ?>
            </section>

            <section class="sx-card">
                <div style="display: flex; justify-content: space-between; align-items: baseline">
                    <h2 class="pf-title">Recent activity</h2>
                    <a href="view_logs.php?user_id=<?= $userId ?>" class="sx-hint">All activity →</a>
                </div>
                <?php if ($recentLogs): ?>
                    <ul class="ua-log">
                        <?php foreach ($recentLogs as $l): ?>
                            <li>
                                <strong><?= h(ucwords(strtolower(str_replace('_', ' ', $l['action_type'])))) ?></strong>
                                <?= $l['description'] ? ' · ' . h($l['description']) : '' ?>
                                <small><?= date('M j, Y g:i A', strtotime($l['created_at'])) ?><?= $l['ip_address'] ? ' · ' . h($l['ip_address']) : '' ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="ua-note" style="margin: 0">No activity recorded.</p>
                <?php endif; ?>
            </section>
        </div>

        <aside class="ua-side">
            <section class="sx-card">
                <h2 class="pf-title">Details</h2>
                <dl class="ua-info">
                    <div><dt>Username</dt><dd><?= h($user['username'] ?: '—') ?></dd></div>
                    <div><dt>Phone</dt><dd><?= h($user['phone'] ?: '—') ?></dd></div>
                    <div><dt>Address</dt><dd><?= h(trim(($user['streetaddress'] ?? '') . ', ' . ($user['city'] ?? ''), ', ') ?: '—') ?></dd></div>
                </dl>
            </section>

            <form method="post" class="sx-card">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="role">
                <h2 class="pf-title">Role</h2>
                <?php if ($locked): ?><p class="ua-note"><?= $isSelf ? "You can't change your own role." : "This is the only admin, so its role is locked." ?></p><?php endif; ?>
                <div class="ua-row">
                    <select name="role" class="sx-select" aria-label="Role" <?= $locked ? 'disabled' : '' ?>>
                        <?php foreach (['customer' => 'Customer', 'business' => 'Business', 'admin' => 'Admin'] as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $user['role'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="sx-btn sx-btn-primary sx-btn-sm" <?= $locked ? 'disabled' : '' ?>>Save</button>
                </div>
            </form>

            <form method="post" class="sx-card">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <h2 class="pf-title">Access</h2>
                <?php if ($isSuspended): ?>
                    <input type="hidden" name="action" value="unsuspend">
                    <p class="ua-note">Suspended until <strong><?= date('M j, Y g:i A', strtotime($user['suspended_until'])) ?></strong>.</p>
                    <button type="submit" class="sx-btn sx-btn-outline sx-btn-block">Lift suspension</button>
                <?php else: ?>
                    <input type="hidden" name="action" value="suspend">
                    <p class="ua-note">A suspended account can't sign in.</p>
                    <div class="ua-row">
                        <select name="days" class="sx-select" aria-label="Suspension length" <?= $locked ? 'disabled' : '' ?>>
                            <?php foreach ([1 => '1 day', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label): ?>
                                <option value="<?= $d ?>" <?= $d === 30 ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="sx-btn sx-btn-outline sx-btn-sm" <?= $locked ? 'disabled' : '' ?>>Suspend</button>
                    </div>
                <?php endif; ?>
            </form>

            <section class="sx-card ua-danger">
                <h2 class="pf-title">Delete account</h2>
                <p class="ua-note">Removes the account. Their orders stay in the system as guest orders.</p>
                <button type="button" class="sx-btn sx-btn-danger sx-btn-block" id="openDelete" <?= $locked ? 'disabled' : '' ?>>Delete account…</button>
            </section>
        </aside>
    </div>
</div>

<?php if (!$locked): ?>
<dialog class="co-dialog" id="deleteDialog" aria-labelledby="delTitle">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <div class="co-dialog-head">
            <h3 id="delTitle">Delete <?= h($user['name']) ?>?</h3>
            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="co-dialog-body">
            <p style="margin-top: 0">This can't be undone. Type <strong><?= h($user['email']) ?></strong> to confirm.</p>
            <input type="email" name="confirm_email" class="sx-input" required autocomplete="off" aria-label="Account email">
        </div>
        <div class="co-dialog-foot">
            <button type="button" class="sx-btn sx-btn-ghost" data-close>Cancel</button>
            <button type="submit" class="sx-btn sx-btn-danger">Delete account</button>
        </div>
    </form>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('deleteDialog');
    document.getElementById('openDelete').addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
