<?php
// admin/customer_management.php — lahat ng account (customer, business, admin), may filter at bilang ng order
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php'; // sx_flash

$roles    = ['all' => 'All roles', 'customer' => 'Customers', 'business' => 'Business', 'admin' => 'Admins'];
$statuses = ['all' => 'Any status', 'active' => 'Active', 'inactive' => 'Inactive 30d+', 'suspended' => 'Suspended'];
$role   = isset($roles[$_GET['role'] ?? '']) ? $_GET['role'] : 'all';
$status = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : 'all';
$search = trim($_GET['q'] ?? '');
$limit  = 20;
$page   = max(1, (int)($_GET['page'] ?? 1));

// Huling login mula sa user_logs; suspended kapag suspended_until ay nasa hinaharap
$base = "
    SELECT u.id, u.name, u.email, u.role, u.created_at, u.suspended_until, u.profile_image,
           (SELECT MAX(created_at) FROM user_logs WHERE user_id = u.id AND action_type = 'LOGIN_SUCCESS') AS last_login_at,
           (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS orders,
           (SELECT COALESCE(SUM(total), 0) FROM orders WHERE user_id = u.id AND status IN ('delivered', 'completed')) AS spent
    FROM users u
";
$where = [];
$params = [];
if ($role !== 'all') { $where[] = 'u.role = ?'; $params[] = $role; }
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
$having = match ($status) {
    'suspended' => 'HAVING suspended_until > NOW()',
    'inactive'  => 'HAVING (suspended_until IS NULL OR suspended_until <= NOW()) AND (last_login_at IS NULL OR last_login_at < NOW() - INTERVAL 30 DAY)',
    'active'    => 'HAVING (suspended_until IS NULL OR suspended_until <= NOW()) AND last_login_at >= NOW() - INTERVAL 30 DAY',
    default     => '',
};
$sql = $base . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " {$having}";

$count = $pdo->prepare("SELECT COUNT(*) FROM ({$sql}) t");
$count->execute($params);
$total = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("{$sql} ORDER BY u.created_at DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roleCounts = $pdo->query("SELECT role, COUNT(*) FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
$flash = sx_flash();

$url = function (array $changes = []) use ($role, $status, $search, $page) {
    $q = array_filter(array_merge(['role' => $role === 'all' ? null : $role, 'status' => $status === 'all' ? null : $status,
        'q' => $search, 'page' => $page > 1 ? $page : null], $changes), fn($v) => $v !== null && $v !== '');
    return 'customer_management.php' . ($q ? '?' . http_build_query($q) : '');
};

// Status ng account para sa badge
function sx_account_state(array $u): array
{
    if (!empty($u['suspended_until']) && strtotime($u['suspended_until']) > time()) return ['Suspended', 'cancelled'];
    if (!$u['last_login_at'] || strtotime($u['last_login_at']) < strtotime('-30 days')) return ['Inactive', 'pending'];
    return ['Active', 'delivered'];
}
$roleLabels = ['admin' => 'Admin', 'business' => 'Business', 'customer' => 'Customer'];
$defaultAvatar = '/sweepxpress/assets/profile-icon.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   CUSTOMERS (Phase 9g) — tokens + sx-table / sx-chip / sx-pill mula sa assets/style.css
   ============================================================ */
.cm { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.cm-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.cm-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.cm-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); padding: var(--sx-space-4); }
.cm-tools form { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.cm-tools .sx-input { flex: 1 1 240px; min-width: 0; }
.cm-tools .sx-select { width: auto; }
.cm-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.cm-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }
.cm-table tbody tr { cursor: pointer; }
.cm-table .num { text-align: right; white-space: nowrap; }
.cm-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); white-space: nowrap; }
.cm-user { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 220px; }
.cm-user img { flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; background: var(--sx-surface-alt); object-fit: cover; }
.cm-user strong { display: block; color: var(--sx-dark); }
.cm-user small { color: var(--sx-muted); }
.cm-role { padding: 2px 10px; border-radius: var(--sx-radius-pill); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); background: var(--sx-surface-alt); color: var(--sx-muted); }
.cm-role.admin { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.cm-role.business { background: var(--sx-primary-soft); color: var(--sx-primary); }
@media (max-width: 767.98px) { .cm-table .hide-sm { display: none; } }
</style>

<div class="cm sx-container">
    <div class="cm-head">
        <div>
            <span class="sx-eyebrow">People</span>
            <h1 class="sx-heading-lg">Customers</h1>
            <p><?= array_sum($roleCounts) ?> accounts · <?= (int)($roleCounts['customer'] ?? 0) ?> customers · <?= (int)($roleCounts['business'] ?? 0) ?> business · <?= (int)($roleCounts['admin'] ?? 0) ?> admins</p>
        </div>
        <a href="view_logs.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-list"></i> Activity logs</a>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-check"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="sx-card cm-tools">
        <div class="cm-chips" aria-label="Filter by role">
            <?php foreach ($roles as $k => $label): ?>
                <a href="<?= h($url(['role' => $k === 'all' ? null : $k, 'page' => null])) ?>" class="sx-chip<?= $role === $k ? ' active' : '' ?>">
                    <?= $label ?> <span><?= $k === 'all' ? array_sum($roleCounts) : (int)($roleCounts[$k] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="get" role="search">
            <?php if ($role !== 'all'): ?><input type="hidden" name="role" value="<?= h($role) ?>"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="Name, username or email…" value="<?= h($search) ?>" aria-label="Search accounts">
            <select name="status" class="sx-select" aria-label="Account status">
                <?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            <?php if ($search || $status !== 'all'): ?>
                <a href="<?= h($url(['q' => null, 'status' => null, 'page' => null])) ?>" class="sx-btn sx-btn-ghost sx-btn-sm">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($users): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table cm-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="num hide-sm">Orders</th>
                            <th class="num hide-sm">Spent</th>
                            <th class="hide-sm">Last login</th>
                            <th class="hide-sm">Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            [$stateLabel, $statePill] = sx_account_state($u);
                            $link = 'user_actions.php?id=' . (int)$u['id'];
                        ?>
                            <tr data-href="<?= $link ?>">
                                <td>
                                    <div class="cm-user">
                                        <img src="<?= h($u['profile_image'] ?: $defaultAvatar) ?>" alt="" loading="lazy" onerror="this.src='<?= $defaultAvatar ?>'">
                                        <div><a href="<?= $link ?>" style="text-decoration: none"><strong><?= h($u['name']) ?></strong></a><small><?= h($u['email']) ?></small></div>
                                    </div>
                                </td>
                                <td><span class="cm-role <?= h($u['role']) ?>"><?= h($roleLabels[$u['role']] ?? ucfirst($u['role'])) ?></span></td>
                                <td><span class="sx-pill sx-pill-<?= $statePill ?>"><?= $stateLabel ?></span></td>
                                <td class="num hide-sm"><?= (int)$u['orders'] ?></td>
                                <td class="num hide-sm">₱<?= number_format((float)$u['spent'], 2) ?></td>
                                <td class="muted hide-sm"><?= $u['last_login_at'] ? date('M j, Y', strtotime($u['last_login_at'])) : 'Never' ?></td>
                                <td class="muted hide-sm"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($users) ?> of <?= $total ?></span>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Pages">
                    <?php if ($page > 1): ?><a href="<?= h($url(['page' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span>
                        <?php else: ?><a href="<?= h($url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="<?= h($url(['page' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-users"></i>
            <h3>No accounts found</h3>
            <p>Try a different search or filter.</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.cm-table tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
    if (!e.target.closest('a')) window.location.href = tr.dataset.href;
}));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
