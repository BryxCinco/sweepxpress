<?php
// Core System Includes
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';

// --- Input Validation and User Fetching ---
$userId = $_GET['id'] ?? null;

if (!$userId || !is_numeric($userId)) {
    header("Location: customer_management.php");
    exit;
}

// Fetch main user data
try {
    $stmt = $pdo->prepare("
        SELECT id, name, email, role, created_at, profile_image, suspended_until
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: customer_management.php");
        exit;
    }

    // --- Fetch Correlated Order History ---
    $orders = $pdo->prepare("
        SELECT id, status, total, created_at
        FROM orders
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");
    $orders->execute([$userId]);
    $orderHistory = $orders->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("<div class='container p-5'><div class='alert alert-danger'>Database error: " . htmlspecialchars($e->getMessage()) . "</div></div>");
}

// --- HELPER FUNCTIONS ---
function get_gravatar_url($email, $s = 150, $d = 'identicon', $r = 'g') {
    $hash = md5(strtolower(trim($email)));
    return "https://www.gravatar.com/avatar/$hash?s=$s&d=$d&r=$r";
}

function get_role_badge_class_actions($role) {
    return match($role) {
        'admin' => 'bg-danger',
        'customer' => 'bg-primary',
        'business' => 'bg-success',
        default => 'bg-secondary'
    };
}

function get_status_badge_class($status) {
    $status = strtolower($status);
    return match($status) {
        'delivered', 'completed' => 'bg-success text-white',
        'preparing', 'shipped' => 'bg-info text-white',
        'pending' => 'bg-warning text-dark',
        'cancelled' => 'bg-danger text-white',
        default => 'bg-secondary text-white'
    };
}

$uploadedImagePath = $user['profile_image'] ?? null; 
$gravatarUrl = get_gravatar_url($user['email'], 120); 
$displayImagePath = htmlspecialchars($uploadedImagePath ?: $gravatarUrl);

// Determine suspension status
$isSuspended = false;
$suspensionExpiry = null;
if (!empty($user['suspended_until'])) {
    $suspensionExpiry = new DateTime($user['suspended_until']);
    if ($suspensionExpiry > new DateTime()) {
        $isSuspended = true;
    }
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap');

/* ========== IMPORTANT: SCOPE ALL STYLES TO .user-page ONLY ========== */
/* DO NOT affect navbar, header, or any global elements */

.user-page {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 24px 80px;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

/* BACK BUTTON */
.user-page .back-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 28px;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    margin-bottom: 32px;
}

.user-page .back-btn:hover {
    background: #f8fafc;
    border-color: #3b82f6;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(59, 130, 246, 0.15);
    color: #0f172a;
}

/* SESSION MESSAGE */
.user-page .session-alert {
    background: #d1fae5;
    border: 2px solid #10b981;
    border-radius: 16px;
    padding: 18px 24px;
    margin-bottom: 24px;
    color: #065f46;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    animation: slideDown 0.3s ease;
}

.user-page .session-alert.error {
    background: #fee2e2;
    border-color: #ef4444;
    color: #991b1b;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* PAGE TITLE */
.user-page .page-title {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 32px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-page .page-title i {
    color: #3b82f6;
}

/* GRID LAYOUT */
.user-page .user-grid {
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

/* PROFILE CARD - SCOPED TO USER PAGE ONLY */
.user-page .profile-card {
    background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
    border-radius: 24px;
    padding: 40px;
    box-shadow: 0 20px 50px rgba(59, 130, 246, 0.25);
    position: relative;
    overflow: hidden;
    text-align: center;
    color: #ffffff;
    max-width: 400px;
}

.user-page .profile-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
    pointer-events: none;
}

.user-page .profile-content {
    position: relative;
    z-index: 1;
}

/* PROFILE IMAGE - ONLY IN USER PAGE CARD */
.user-page .profile-img-wrapper {
    width: 140px;
    height: 140px;
    margin: 0 auto 20px;
    overflow: hidden;
    border-radius: 50%;
}

.user-page .profile-img {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.user-page .profile-name {
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 8px;
    word-wrap: break-word;
}

.user-page .profile-id {
    font-size: 0.85rem;
    opacity: 0.85;
    margin-bottom: 24px;
}

.user-page .profile-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.2);
    margin: 24px 0;
}

.user-page .profile-info {
    text-align: left;
}

.user-page .profile-info-item {
    margin-bottom: 16px;
    word-wrap: break-word;
}

.user-page .profile-info-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.85;
    margin-bottom: 6px;
}

.user-page .profile-info-value {
    font-size: 1rem;
    font-weight: 600;
}

.user-page .profile-info-value a {
    color: #ffffff;
    text-decoration: none;
}

.user-page .profile-info-value a:hover {
    text-decoration: underline;
}

.user-page .role-badge {
    display: inline-block;
    padding: 8px 20px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 999px;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.85rem;
}

/* SUSPENDED ALERT */
.user-page .suspended-alert {
    background: #fee2e2;
    border: 2px solid #ef4444;
    border-radius: 16px;
    padding: 16px 20px;
    color: #991b1b;
    font-weight: 700;
    margin-bottom: 20px;
    text-align: center;
}

/* WHITE CARDS */
.user-page .white-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 24px;
    padding: 32px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    transition: all 0.3s;
}

.user-page .white-card:hover {
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.user-page .card-header-custom {
    font-size: 1.3rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-page .card-header-custom i {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
    border-radius: 12px;
    font-size: 1rem;
    color: #ffffff;
}

/* ROLE UPDATE FORM */
.user-page .role-form {
    display: flex;
    gap: 16px;
    align-items: end;
}

.user-page .form-group-custom {
    flex: 1;
}

.user-page .form-group-custom label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 10px;
}

.user-page .form-group-custom select {
    width: 100%;
    padding: 14px 18px;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    color: #0f172a;
    font-size: 0.95rem;
    font-weight: 500;
    transition: all 0.3s;
}

.user-page .form-group-custom select:focus {
    outline: none;
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
}

.user-page .btn-save {
    padding: 14px 32px;
    background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
    color: #ffffff;
    border: none;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.user-page .btn-save:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
}

.user-page .btn-save:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* TABLE CARD */
.user-page .table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
}

.user-page .table-header {
    padding: 28px 32px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 1.3rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-page .table-header i {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    border-radius: 12px;
    font-size: 1rem;
    color: #ffffff;
}

.user-page .modern-table {
    width: 100%;
    border-collapse: collapse;
}

.user-page .modern-table thead th {
    padding: 20px 32px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #64748b;
    background: #f8fafc;
}

.user-page .modern-table tbody td {
    padding: 20px 32px;
    color: #0f172a;
    border-top: 1px solid #f1f5f9;
    font-size: 0.95rem;
}

.user-page .modern-table tbody tr:hover {
    background: #f8fafc;
}

.user-page .order-badge {
    display: inline-block;
    padding: 6px 14px;
    background: #0f172a;
    color: #ffffff;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
}

.user-page .status-badge-custom {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 0.8rem;
    text-transform: capitalize;
}

.user-page .status-badge-custom.delivered { background: #d1fae5; color: #065f46; }
.user-page .status-badge-custom.preparing { background: #dbeafe; color: #1e40af; }
.user-page .status-badge-custom.pending { background: #fef3c7; color: #92400e; }
.user-page .status-badge-custom.cancelled { background: #fee2e2; color: #991b1b; }
.user-page .status-badge-custom.completed { background: #e0e7ff; color: #3730a3; }

.user-page .btn-view {
    padding: 8px 20px;
    background: #ffffff;
    color: #3b82f6;
    border: 2px solid #3b82f6;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.user-page .btn-view:hover {
    background: #3b82f6;
    color: #ffffff;
}

/* EMPTY STATE */
.user-page .empty-state {
    text-align: center;
    padding: 80px;
    color: #94a3b8;
}

.user-page .empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
}

/* ACTION BUTTONS CARD */
.user-page .actions-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 24px;
    padding: 32px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
}

.user-page .actions-header {
    font-size: 1.3rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-page .actions-header i {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%);
    border-radius: 12px;
    font-size: 1rem;
    color: #ffffff;
}

.user-page .actions-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.user-page .action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    border-radius: 16px;
    text-decoration: none;
    font-weight: 700;
    transition: all 0.3s;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.user-page .action-btn i {
    font-size: 2.5rem;
    margin-bottom: 12px;
}

.user-page .action-btn.delete {
    background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    color: #ffffff;
}

.user-page .action-btn.suspend {
    background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
    color: #ffffff;
}

.user-page .action-btn.unsuspend {
    background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
    color: #ffffff;
}

.user-page .action-btn.logs {
    background: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);
    color: #ffffff;
}

.user-page .action-btn:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.2);
}

/* RESPONSIVE */
@media (max-width: 992px) {
    .user-page .user-grid {
        grid-template-columns: 1fr;
    }
    
    .user-page .actions-grid {
        grid-template-columns: 1fr;
    }
    
    .user-page .profile-card {
        max-width: 100%;
    }
}

@media (max-width: 768px) {
    .user-page {
        padding: 20px 16px 60px;
    }
    
    .user-page .role-form {
        flex-direction: column;
    }
    
    .user-page .btn-save {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="user-page">
    <!-- BACK BUTTON -->
    <a href="customer_management.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
        Back to User List
    </a>

    <!-- SESSION MESSAGE -->
    <?php if (isset($_SESSION['message'])): ?>
        <div class="session-alert <?= $_SESSION['message']['type'] === 'danger' ? 'error' : '' ?>">
            <?= htmlspecialchars($_SESSION['message']['text']) ?>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- PAGE TITLE -->
    <h1 class="page-title">
        <i class="fas fa-user-circle"></i>
        Manage User: <?= htmlspecialchars($user['name']) ?>
    </h1>

    <!-- USER GRID -->
    <div class="user-grid">
        <!-- PROFILE CARD -->
        <div class="profile-card">
            <div class="profile-content">
                <?php if ($isSuspended): ?>
                    <div class="suspended-alert">
                        <i class="fas fa-lock"></i> ACCOUNT SUSPENDED
                        <br><small>Until: <?= $suspensionExpiry->format('M d, Y h:i A') ?></small>
                    </div>
                <?php endif; ?>

                <div class="profile-img-wrapper">
                    <img src="<?= $displayImagePath ?>" alt="Profile" class="profile-img">
                </div>
                
                <div class="profile-name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="profile-id">User ID: #<?= $user['id'] ?></div>

                <div class="profile-divider"></div>

                <div class="profile-info">
                    <div class="profile-info-item">
                        <div class="profile-info-label">Email Address</div>
                        <div class="profile-info-value">
                            <a href="mailto:<?= htmlspecialchars($user['email']) ?>">
                                <?= htmlspecialchars($user['email']) ?>
                            </a>
                        </div>
                    </div>

                    <div class="profile-info-item">
                        <div class="profile-info-label">Current Role</div>
                        <div class="profile-info-value">
                            <span id="currentRoleBadge" class="role-badge">
                                <?= ucfirst($user['role']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="profile-info-item">
                        <div class="profile-info-label">Member Since</div>
                        <div class="profile-info-value">
                            <?= date("F j, Y", strtotime($user['created_at'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROLE UPDATE CARD -->
        <div class="white-card">
            <div class="card-header-custom">
                <i class="fas fa-user-tag"></i>
                Update User Role
            </div>
            
            <form id="roleUpdateForm" class="role-form">
                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                <div class="form-group-custom">
                    <label>Select New Role</label>
                    <select id="userRole" name="role">
                        <option value="customer" <?= ($user['role'] == 'customer') ? 'selected' : '' ?>>Customer</option>
                        <option value="business" <?= ($user['role'] == 'business') ? 'selected' : '' ?>>Business</option>
                        <option value="admin" <?= ($user['role'] == 'admin') ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <button type="submit" id="saveRoleBtn" class="btn-save">
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>
            </form>
            <div id="roleMessage" class="mt-3" style="display: none;"></div>
        </div>
    </div>

    <!-- ORDER HISTORY TABLE -->
    <div class="table-card">
        <div class="table-header">
            <i class="fas fa-history"></i>
            Order History
        </div>
        <?php if (count($orderHistory) > 0): ?>
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderHistory as $order): ?>
                        <tr>
                            <td><span class="order-badge">#<?= $order['id'] ?></span></td>
                            <td><?= date("M d, Y", strtotime($order['created_at'])) ?></td>
                            <td style="font-weight: 700; color: #10b981;">₱<?= number_format($order['total'], 2) ?></td>
                            <td>
                                <span class="status-badge-custom <?= strtolower($order['status']) ?>">
                                    <?= ucfirst($order['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="order_details.php?id=<?= $order['id'] ?>" class="btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <div>No orders found for this user</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ACTION BUTTONS -->
    <div class="actions-card">
        <div class="actions-header">
            <i class="fas fa-cogs"></i>
            Account Actions
        </div>
        <div class="actions-grid">
            <a href="#" class="action-btn delete" onclick="confirmDelete(<?= $user['id'] ?>); return false;">
                <i class="fas fa-trash-alt"></i>
                Delete User
            </a>
            
            <?php if ($isSuspended): ?>
                <a href="#" class="action-btn unsuspend" onclick="confirmUnsuspend(<?= $user['id'] ?>); return false;">
                    <i class="fas fa-undo"></i>
                    Unsuspend
                </a>
            <?php else: ?>
                <a href="#" class="action-btn suspend" onclick="confirmSuspend(<?= $user['id'] ?>); return false;">
                    <i class="fas fa-hand-paper"></i>
                    Suspend
                </a>
            <?php endif; ?>
            
            <a href="view_logs.php?user_id=<?= $user['id'] ?>" class="action-btn logs">
                <i class="fas fa-clipboard-list"></i>
                View Logs
            </a>
        </div>
    </div>
</div>

<script>
// Role Update Form
document.getElementById('roleUpdateForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const formData = new URLSearchParams(new FormData(form)).toString();
    const messageBox = document.getElementById('roleMessage');
    const saveBtn = document.getElementById('saveRoleBtn');
    const currentRoleBadge = document.getElementById('currentRoleBadge');
    const newRole = document.getElementById('userRole').value;

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

    fetch('edit_role.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.includes('Success')) {
            Swal.fire({
                icon: 'success',
                title: 'Role Updated!',
                text: 'User role has been successfully updated',
                confirmButtonColor: '#10b981'
            });
            currentRoleBadge.textContent = newRole.charAt(0).toUpperCase() + newRole.slice(1);
        } else {
            Swal.fire({
                icon: 'check',
                title: 'Update Successful',
                text: data,
                confirmButtonColor: '#00ff15ff'
            });
        }
    })
    .catch(error => {
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'An error occurred during the update',
            confirmButtonColor: '#ef4444'
        });
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
    });
});

// Delete Confirmation
function confirmDelete(userId) {
    Swal.fire({
        title: 'Delete User?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'delete.php?user_id=' + userId;
        }
    });
}

// Suspend Confirmation
function confirmSuspend(userId) {
    Swal.fire({
        title: 'Suspend Account?',
        text: 'This will suspend the account for 30 days',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, suspend',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'suspend.php?user_id=' + userId;
        }
    });
}

// Unsuspend Confirmation
function confirmUnsuspend(userId) {
    Swal.fire({
        title: 'Unsuspend Account?',
        text: 'This will restore account access',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, unsuspend',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'unsuspend.php?user_id=' + userId;
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
