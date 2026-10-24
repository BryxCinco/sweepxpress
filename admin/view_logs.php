<?php
// /admin/view_logs.php
// Displays the activity logs for a specific user.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';

// --- Input Validation and User Fetching ---
$userId = $_GET['user_id'] ?? null;

if (!$userId || !is_numeric($userId)) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'Invalid user ID provided for viewing logs.'];
    header("Location: customer_management.php");
    exit;
}

// Fetch the name of the user whose logs we are viewing
try {
    $userStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'User not found.'];
        header("Location: customer_management.php");
        exit;
    }
    $userName = htmlspecialchars($user['name']);

    // Fetch all logs for this user, ordered by most recent
    $logsStmt = $pdo->prepare("
        SELECT 
            ul.action_type, ul.description, ul.created_at, ul.ip_address,
            a.name AS admin_name
        FROM user_logs ul
        LEFT JOIN users a ON ul.action_by_id = a.id
        WHERE ul.user_id = ?
        ORDER BY ul.created_at DESC
    ");
    $logsStmt->execute([$userId]);
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate stats
    $totalLogs = count($logs);
    $criticalLogs = count(array_filter($logs, fn($l) => in_array($l['action_type'], ['ACCOUNT_SUSPENDED', 'ACCOUNT_DELETED', 'LOGIN_FAILURE'])));
    $successLogs = count(array_filter($logs, fn($l) => in_array($l['action_type'], ['LOGIN_SUCCESS', 'ROLE_CHANGE', 'UNSUSPENDED'])));
    $recentLogs = count(array_filter($logs, fn($l) => strtotime($l['created_at']) > strtotime('-24 hours')));

} catch (PDOException $e) {
    die("<div class='container p-5'><div class='alert alert-danger'>Database error: Could not fetch logs. " . htmlspecialchars($e->getMessage()) . "</div></div>");
}

// --- Helper for styling log types ---
function get_log_color($type) {
    return match($type) {
        'ACCOUNT_SUSPENDED', 'ACCOUNT_DELETED' => 'danger',
        'LOGIN_FAILURE', 'PASSWORD_RESET' => 'warning',
        'ROLE_CHANGE', 'UNSUSPENDED' => 'success',
        'LOGIN_SUCCESS', 'PROFILE_UPDATE' => 'info',
        default => 'secondary'
    };
}

function get_log_icon($type) {
    return match($type) {
        'ACCOUNT_SUSPENDED' => 'fa-ban',
        'ACCOUNT_DELETED' => 'fa-trash-alt',
        'LOGIN_FAILURE' => 'fa-exclamation-triangle',
        'PASSWORD_RESET' => 'fa-key',
        'ROLE_CHANGE' => 'fa-user-tag',
        'UNSUSPENDED' => 'fa-unlock',
        'LOGIN_SUCCESS' => 'fa-sign-in-alt',
        'PROFILE_UPDATE' => 'fa-user-edit',
        default => 'fa-info-circle'
    };
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap');

.logs-page {
    max-width: 1600px;
    margin: 0 auto;
    padding: 40px 24px 80px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    min-height: 100vh;
}

/* HEADER */
.logs-page .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.logs-page .header-left {
    flex: 1;
}

.logs-page .back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #ffffff;
    border: none;
    border-radius: 12px;
    color: #64748b;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-bottom: 16px;
}

.logs-page .back-btn:hover {
    background: #f8fafc;
    color: #0f172a;
    transform: translateX(-4px);
}

.logs-page .page-title {
    font-size: 2.5rem;
    font-weight: 900;
    color: #0f172a;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 16px;
}

.logs-page .page-title i {
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    border-radius: 16px;
    color: #ffffff;
    font-size: 1.5rem;
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
}

.logs-page .user-name {
    font-size: 1.1rem;
    color: #64748b;
    font-weight: 500;
    margin-top: 8px;
}

/* STATS GRID */
.logs-page .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 32px;
}

.logs-page .stat-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    transition: all 0.3s;
    position: relative;
    overflow: hidden;
}

.logs-page .stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.logs-page .stat-card.purple::before {
    background: linear-gradient(90deg, #8b5cf6 0%, #a78bfa 100%);
}

.logs-page .stat-card.red::before {
    background: linear-gradient(90deg, #ef4444 0%, #f87171 100%);
}

.logs-page .stat-card.green::before {
    background: linear-gradient(90deg, #10b981 0%, #34d399 100%);
}

.logs-page .stat-card.blue::before {
    background: linear-gradient(90deg, #3b82f6 0%, #60a5fa 100%);
}

.logs-page .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.1);
}

.logs-page .stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    margin-bottom: 16px;
}

.logs-page .stat-card.purple .stat-icon {
    background: rgba(139, 92, 246, 0.1);
    color: #8b5cf6;
}

.logs-page .stat-card.red .stat-icon {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

.logs-page .stat-card.green .stat-icon {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
}

.logs-page .stat-card.blue .stat-icon {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
}

.logs-page .stat-value {
    font-size: 2rem;
    font-weight: 900;
    color: #0f172a;
    margin-bottom: 4px;
}

.logs-page .stat-label {
    font-size: 0.85rem;
    color: #64748b;
    font-weight: 600;
}

/* MAIN TABLE CARD */
.logs-page .table-card {
    background: #ffffff;
    border-radius: 24px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    overflow: hidden;
}

.logs-page .table-header {
    padding: 32px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.logs-page .table-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 12px;
}

.logs-page .search-box {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    min-width: 300px;
}

.logs-page .search-box i {
    color: #94a3b8;
}

.logs-page .search-box input {
    border: none;
    background: transparent;
    outline: none;
    flex: 1;
    font-size: 0.9rem;
    color: #0f172a;
}

.logs-page .search-box input::placeholder {
    color: #94a3b8;
}

/* TABLE */
.logs-page .logs-table {
    width: 100%;
    border-collapse: collapse;
}

.logs-page .logs-table thead {
    background: #f8fafc;
}

.logs-page .logs-table th {
    padding: 20px 32px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #64748b;
}

.logs-page .logs-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: all 0.2s;
}

.logs-page .logs-table tbody tr:hover {
    background: #f8fafc;
}

.logs-page .logs-table td {
    padding: 24px 32px;
}

.logs-page .log-action {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px;
    border-radius: 100px;
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: capitalize;
}

.logs-page .log-action i {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.logs-page .log-action.danger {
    background: #fee2e2;
    color: #991b1b;
}

.logs-page .log-action.danger i {
    background: #ef4444;
    color: #ffffff;
}

.logs-page .log-action.warning {
    background: #fef3c7;
    color: #92400e;
}

.logs-page .log-action.warning i {
    background: #f59e0b;
    color: #ffffff;
}

.logs-page .log-action.success {
    background: #d1fae5;
    color: #065f46;
}

.logs-page .log-action.success i {
    background: #10b981;
    color: #ffffff;
}

.logs-page .log-action.info {
    background: #dbeafe;
    color: #1e40af;
}

.logs-page .log-action.info i {
    background: #3b82f6;
    color: #ffffff;
}

.logs-page .log-action.secondary {
    background: #f1f5f9;
    color: #475569;
}

.logs-page .log-action.secondary i {
    background: #64748b;
    color: #ffffff;
}

.logs-page .log-desc {
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.5;
}

.logs-page .log-time {
    color: #0f172a;
    font-weight: 600;
    font-size: 0.9rem;
}

.logs-page .log-date {
    color: #94a3b8;
    font-size: 0.8rem;
}

.logs-page .log-user {
    display: flex;
    align-items: center;
    gap: 10px;
}

.logs-page .user-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.85rem;
}

.logs-page .user-avatar.admin {
    background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    color: #ffffff;
}

.logs-page .user-avatar.user {
    background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
    color: #ffffff;
}

.logs-page .user-info {
    flex: 1;
}

.logs-page .user-name-cell {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.9rem;
}

.logs-page .user-role {
    font-size: 0.75rem;
    color: #94a3b8;
}

.logs-page .log-ip-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #f1f5f9;
    border-radius: 8px;
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
    color: #64748b;
}

/* EMPTY STATE */
.logs-page .empty-state {
    padding: 100px 40px;
    text-align: center;
}

.logs-page .empty-icon {
    width: 120px;
    height: 120px;
    margin: 0 auto 24px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: #cbd5e1;
}

.logs-page .empty-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #64748b;
    margin-bottom: 8px;
}

.logs-page .empty-subtitle {
    font-size: 1rem;
    color: #94a3b8;
}

/* RESPONSIVE */
@media (max-width: 1200px) {
    .logs-page .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .logs-page {
        padding: 20px 16px 60px;
    }
    
    .logs-page .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .logs-page .page-title {
        font-size: 1.8rem;
    }
    
    .logs-page .search-box {
        min-width: 100%;
    }
    
    .logs-page .table-card {
        overflow-x: auto;
    }
    
    .logs-page .logs-table {
        min-width: 800px;
    }
}
</style>

<div class="logs-page">
    <!-- HEADER -->
    <div class="page-header">
        <div class="header-left">
            <a href="user_actions.php?id=<?= $userId ?>" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Back
            </a>
            <h1 class="page-title">
                <i class="fas fa-file-alt"></i>
                Activity Logs
            </h1>
            <div class="user-name">Viewing logs for: <strong><?= $userName ?></strong></div>
        </div>
    </div>

    <!-- STATS GRID -->
    <div class="stats-grid">
        <div class="stat-card purple">
            <div class="stat-icon">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-value"><?= $totalLogs ?></div>
            <div class="stat-label">Total Logs</div>
        </div>

        <div class="stat-card red">
            <div class="stat-icon">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="stat-value"><?= $criticalLogs ?></div>
            <div class="stat-label">Critical Events</div>
        </div>

        <div class="stat-card green">
            <div class="stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-value"><?= $successLogs ?></div>
            <div class="stat-label">Success Events</div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-value"><?= $recentLogs ?></div>
            <div class="stat-label">Last 24 Hours</div>
        </div>
    </div>

    <!-- TABLE CARD -->
    <div class="table-card">
        <div class="table-header">
            <h2 class="table-title">
                <i class="fas fa-history"></i>
                Log History
            </h2>
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search logs..." id="searchInput">
            </div>
        </div>

        <?php if (count($logs) > 0): ?>
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Date & Time</th>
                        <th>Performed By</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): 
                        $color = get_log_color($log['action_type']);
                        $icon = get_log_icon($log['action_type']);
                    ?>
                        <tr>
                            <td>
                                <div class="log-action <?= $color ?>">
                                    <i class="fas <?= $icon ?>"></i>
                                    <?= str_replace('_', ' ', $log['action_type']) ?>
                                </div>
                            </td>
                            <td>
                                <div class="log-desc"><?= htmlspecialchars($log['description']) ?></div>
                            </td>
                            <td>
                                <div class="log-time"><?= date("h:i A", strtotime($log['created_at'])) ?></div>
                                <div class="log-date"><?= date("M d, Y", strtotime($log['created_at'])) ?></div>
                            </td>
                            <td>
                                <div class="log-user">
                                    <?php if ($log['admin_name']): ?>
                                        <div class="user-avatar admin">
                                            <i class="fas fa-shield-alt"></i>
                                        </div>
                                        <div class="user-info">
                                            <div class="user-name-cell"><?= htmlspecialchars($log['admin_name']) ?></div>
                                            <div class="user-role">Admin</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="user-avatar user">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="user-info">
                                            <div class="user-name-cell"><?= $userName ?></div>
                                            <div class="user-role">User</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="log-ip-badge">
                                    <i class="fas fa-network-wired"></i>
                                    <?= htmlspecialchars($log['ip_address'] ?? 'Unknown') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3 class="empty-title">No Activity Found</h3>
                <p class="empty-subtitle">This user has no recorded activity logs yet</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Simple search filter
document.getElementById('searchInput')?.addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('.logs-table tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
