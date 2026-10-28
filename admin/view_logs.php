<?php
// admin/view_logs.php — activity log (logins, password resets, admin actions).
// Lahat ng account, o isang account lang kapag may ?user_id=
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);
$type   = preg_replace('/[^A-Z_]/', '', $_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');
$limit  = 30;
$page   = max(1, (int)($_GET['page'] ?? 1));

// Redirect bago ang header (dati pagkatapos — "headers already sent")
$user = null;
if ($userId) {
    $u = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $u->execute([$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header("Location: view_logs.php");
        exit;
    }
}

$where = [];
$params = [];
if ($userId) { $where[] = 'ul.user_id = ?'; $params[] = $userId; }
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR u.email LIKE ? OR ul.description LIKE ? OR ul.ip_address LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
}
$baseWhere = $where ? implode(' AND ', $where) : '1=1';
$from = "FROM user_logs ul LEFT JOIN users u ON u.id = ul.user_id LEFT JOIN users a ON a.id = ul.action_by_id";

// Bilang bawat uri (para sa chips)
$tc = $pdo->prepare("SELECT ul.action_type, COUNT(*) {$from} WHERE {$baseWhere} GROUP BY ul.action_type ORDER BY 2 DESC");
$tc->execute($params);
$typeCounts = $tc->fetchAll(PDO::FETCH_KEY_PAIR);
if ($type !== '' && !isset($typeCounts[$type])) $type = '';

$listWhere = $baseWhere . ($type !== '' ? ' AND ul.action_type = ?' : '');
$listParams = $type !== '' ? array_merge($params, [$type]) : $params;
$total = $type !== '' ? (int)$typeCounts[$type] : array_sum($typeCounts);
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT ul.user_id, ul.action_type, ul.description, ul.ip_address, ul.created_at,
           u.name AS user_name, u.email, a.name AS admin_name
    {$from}
    WHERE {$listWhere}
    ORDER BY ul.created_at DESC, ul.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($listParams);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Uri → [icon, tono]
function sx_log_style(string $type): array
{
    return match ($type) {
        'LOGIN_SUCCESS'                          => ['fa-right-to-bracket', 'ok'],
        'LOGIN_FAILURE', 'LOGIN_BLOCKED'         => ['fa-triangle-exclamation', 'warn'],
        'PASSWORD_RESET_REQUESTED', 'PASSWORD_RESET' => ['fa-key', 'info'],
        'ROLE_CHANGED', 'ROLE_CHANGE'            => ['fa-user-tag', 'info'],
        'ACCOUNT_SUSPENDED', 'ACCOUNT_DELETED'   => ['fa-ban', 'bad'],
        'ACCOUNT_UNSUSPENDED', 'UNSUSPENDED'     => ['fa-unlock', 'ok'],
        default                                  => ['fa-circle-info', 'info'],
    };
}
$label = fn(string $t) => ucwords(strtolower(str_replace('_', ' ', $t)));

$url = function (array $changes = []) use ($userId, $type, $search, $page) {
    $q = array_filter(array_merge(['user_id' => $userId ?: null, 'type' => $type, 'q' => $search, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'view_logs.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ACTIVITY LOGS (Phase 9g) — tokens + sx-table / sx-chip mula sa assets/style.css
   ============================================================ */
.vl { max-width: 1300px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.vl-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); padding: var(--sx-space-4); }
.vl-tools form { display: flex; gap: var(--sx-space-2); }
.vl-tools .sx-input { flex: 1; min-width: 0; }
.vl-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.vl-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }
.vl-type { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: var(--sx-radius-pill); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); white-space: nowrap; }
.vl-type.ok   { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.vl-type.warn { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.vl-type.bad  { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.vl-type.info { background: var(--sx-primary-soft); color: var(--sx-primary); }
.vl-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); }
.vl-table .who a { color: var(--sx-dark); font-weight: var(--sx-weight-semibold); text-decoration: none; }
.vl-table .who a:hover { color: var(--sx-primary); }
.vl-table .who small { display: block; color: var(--sx-muted); }
@media (max-width: 767.98px) { .vl-table .hide-sm { display: none; } }
</style>

<div class="vl sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="customer_management.php">Customers</a> <span aria-hidden="true">/</span>
        <?php if ($user): ?>
            <a href="user_actions.php?id=<?= (int)$user['id'] ?>"><?= h($user['name']) ?></a> <span aria-hidden="true">/</span>
        <?php endif; ?>
        <span>Activity</span>
    </nav>
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg"><?= $user ? 'Activity · ' . h($user['name']) : 'Activity logs' ?></h1>
            <p>Sign-ins, password resets and admin actions on accounts.</p>
        </div>
        <?php if ($user): ?><a href="view_logs.php" class="sx-btn sx-btn-outline sx-btn-sm">All accounts</a><?php endif; ?>
    </div>

    <div class="sx-card vl-tools">
        <form method="get" role="search">
            <?php if ($userId): ?><input type="hidden" name="user_id" value="<?= $userId ?>"><?php endif; ?>
            <?php if ($type): ?><input type="hidden" name="type" value="<?= h($type) ?>"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="Name, email, description or IP…" value="<?= h($search) ?>" aria-label="Search logs">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
        <div class="vl-chips">
            <a href="<?= h($url(['type' => null, 'page' => null])) ?>" class="sx-chip<?= $type === '' ? ' active' : '' ?>">All <span><?= array_sum($typeCounts) ?></span></a>
            <?php foreach ($typeCounts as $t => $n): ?>
                <a href="<?= h($url(['type' => $t, 'page' => null])) ?>" class="sx-chip<?= $type === $t ? ' active' : '' ?>"><?= h($label($t)) ?> <span><?= (int)$n ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($logs): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table vl-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Event</th>
                            <?php if (!$user): ?><th>Account</th><?php endif; ?>
                            <th>Details</th>
                            <th class="hide-sm">By</th>
                            <th class="hide-sm">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): [$icon, $tone] = sx_log_style($l['action_type']); ?>
                            <tr>
                                <td class="muted" style="white-space: nowrap"><?= date('M j, Y · g:i A', strtotime($l['created_at'])) ?></td>
                                <td><span class="vl-type <?= $tone ?>"><i class="fa-solid <?= $icon ?>"></i> <?= h($label($l['action_type'])) ?></span></td>
                                <?php if (!$user): ?>
                                    <td class="who">
                                        <?php if ($l['user_name'] !== null): ?>
                                            <a href="user_actions.php?id=<?= (int)$l['user_id'] ?>"><?= h($l['user_name']) ?></a><small><?= h($l['email']) ?></small>
                                        <?php else: ?>
                                            <span class="muted">Deleted account #<?= (int)$l['user_id'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="muted"><?= h($l['description'] ?: '—') ?></td>
                                <td class="muted hide-sm"><?= h($l['admin_name'] ?: 'Self') ?></td>
                                <td class="muted hide-sm"><?= h($l['ip_address'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($logs) ?> of <?= $total ?></span>
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
            <i class="fa-solid fa-clock-rotate-left"></i>
            <h3>No activity found</h3>
            <p>Try another filter.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
