<?php
// ... (Your existing PHP code remains the same)

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/log_helper.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';

// Role badge styles
function get_role_badge_class($role) {
    return match ($role) {
        'admin'     => 'role-admin',
        'customer'  => 'role-customer',
        'business'  => 'role-business',
        default     => 'role-other',
    };
}

function get_user_status($suspendedUntil, $lastLoginAt) {
    $now            = new DateTime();
    $status         = 'Active';
    $badgeClass     = 'st-active';

    if (!empty($suspendedUntil)) {
        $suspensionExpiry = new DateTime($suspendedUntil);
        if ($now < $suspensionExpiry) {
            return ['status' => 'Suspended', 'class' => 'st-suspended'];
        }
    }

    $inactivityThreshold = new DateTime('-30 days');

    if ($lastLoginAt) {
        $lastLogin = new DateTime($lastLoginAt);
        if ($lastLogin < $inactivityThreshold) {
            $status     = 'Inactive';
            $badgeClass = 'st-inactive';
        }
    } else {
        $status     = 'Inactive';
        $badgeClass = 'st-inactive';
    }

    return ['status' => $status, 'class' => $badgeClass];
}

try {
    // NOTE: $pdo is assumed to be available from config.php
    // In a real scenario, you must ensure $pdo is properly instantiated and connected.
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.role,
            u.created_at,
            u.suspended_until,
            u.profile_image,  /* <--- ADDED: Fetch the profile image column */
            (
                SELECT MAX(ul.created_at)
                FROM user_logs ul
                WHERE ul.user_id = u.id
                  AND ul.action_type = 'LOGIN_SUCCESS'
            ) AS last_login_at
        FROM users u
        ORDER BY u.created_at DESC
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // ... (Your existing error handling remains the same)
    die(
        "<div class='container p-5'>
            <div class='alert alert-danger'>
                Database error: Could not fetch users. " . htmlspecialchars($e->getMessage()) . "
            </div>
        </div>"
    );
}
?>

<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

body {
    background: radial-gradient(circle at top,#e0f2fe 0,#f4f5fb 50%,#f9fafb 100%);
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

/* SHELL */
.user-shell {
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 24px 40px;
}

/* HEADER BAR */
.user-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 18px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.user-title-block h1 {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin-bottom: 4px;
}

.user-title-block p {
    font-size: 0.9rem;
    color: #64748b;
}

.user-search-wrap {
    max-width: 320px;
    width: 100%;
}

.user-search-input {
    width: 100%;
    padding: 10px 14px 10px 38px;
    border-radius: 999px;
    border: 1px solid #d4dbe8;
    background: #ffffff;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.user-search-input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
}

.user-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}

/* STATS STRIP */
.user-stats {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.user-stat-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    font-size: 0.8rem;
    color: #475569;
}

/* CARD TABLE */
.user-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 30px rgba(15,23,42,0.08);
    overflow: hidden;
}

.user-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.user-card-header h5 {
    margin: 0;
    font-size: 1.02rem;
    font-weight: 700;
}

.user-card-sub {
    font-size: 0.8rem;
    color: #6b7280;
}

/* TABLE */
.user-table-wrap {
    overflow-x: auto;
}

.user-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.user-table thead th {
    padding: 10px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: #9ca3af;
    text-align: left;
}

.user-table tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.user-table tbody tr:last-child td {
    border-bottom: none;
}

.user-table tbody tr {
    transition: background 0.16s ease;
}

.user-table tbody tr:hover {
    background: #f1f5f9;
}

/* CELL STYLES */
.cell-id {
    font-weight: 600;
    color: #9ca3af;
}

.cell-name {
    font-weight: 600;
    color: #111827;
}

.cell-email a {
    color: #111827;
    text-decoration: none;
}

.cell-email a:hover {
    text-decoration: underline;
}

/* ROLE BADGES */
.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
}

.role-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
}

.role-admin {
    background: rgba(239,68,68,0.08);
    color: #b91c1c;
}
.role-admin .role-dot { background:#ef4444; }

.role-customer {
    background: rgba(59,130,246,0.08);
    color: #1d4ed8;
}
.role-customer .role-dot { background:#3b82f6; }

.role-business {
    background: rgba(16,185,129,0.08);
    color: #047857;
}
.role-business .role-dot { background:#10b981; }

.role-other {
    background: rgba(148,163,184,0.15);
    color: #374151;
}
.role-other .role-dot { background:#6b7280; }

/* STATUS BADGES */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #ffffff;
}

.st-active {
    background:#16a34a;
}
.st-inactive {
    background:#facc15;
    color:#854d0e;
}
.st-suspended {
    background:#ef4444;
}

/* MANAGE BUTTON */
.manage-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background:#ffffff;
    color:#2563eb;
    font-size:0.82rem;
    font-weight:600;
    text-decoration:none;
    transition:all 0.18s;
}

.manage-btn:hover {
    background:#2563eb;
    color:#ffffff;
}

/* SEARCH ICON WRAP */
.search-wrap {
    position: relative;
    width: 100%;
}

@media (max-width: 768px) {
    .user-shell {
        padding: 24px 16px 32px;
    }
    .user-header {
        align-items: flex-start;
    }
    
    /* ----------------------------------- */
    /* MOBILE CARD VIEW STYLES (Fixed)     */
    /* ----------------------------------- */
    
    /* Hide table header on mobile */
    .user-table thead {
        display: none;
    }
    
    /* Make table rows act as blocks/cards */
    .user-table, .user-table tbody, .user-table tr, .user-table td {
        display: block;
        width: 100%; /* Important for card structure */
    }
    
    .user-table tr {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        margin-bottom: 12px; /* Space between cards */
        padding: 15px;
        background: #ffffff;
        box-shadow: 0 4px 12px rgba(15,23,42,0.05);
        transition: none; /* Remove hover effect for rows as they are now cards */
        display: flex; /* Retain flex for ordering */
        flex-direction: column;
    }
    
    .user-table tbody tr:hover {
        background: #ffffff; /* Keep background white on hover */
    }
    
    /* Remove default cell padding and bottom border */
    .user-table tbody td {
        padding: 8px 0;
        border-bottom: none;
        text-align: right; /* Align value to the right (default for data) */
        position: relative;
        font-size: 0.9rem;
    }
    
    /* Add a pseudo-element to show the column header as a label */
    .user-table tbody td:before {
        content: attr(data-label);
        float: left;
        font-weight: 500;
        color: #64748b;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.05em;
    }

    /* Specific styling for 'Manage' button to be full width */
    .user-table tbody td:last-child {
        text-align: center;
        margin-top: 10px;
        padding-top: 15px;
        border-top: 1px dashed #e2e8f0;
    }
    
    .manage-btn {
        width: 100%;
        justify-content: center;
    }
    
    /* --- FIXES START HERE --- */
    
    /* ID number fix */
    .cell-id {
        /* Reset positioning for ID number on mobile */
        position: static; 
        padding-top: 0;
        margin-bottom: 5px; 
        font-size: 0.85rem; 
        color: #9ca3af;
    }
    .cell-id:before {
        /* Show ID label */
        content: 'ID'; 
        float: none; 
        margin-right: 5px; 
        font-weight: 600; 
        color: #9ca3af; 
    }
    
    /* Special formatting for Name/Email which are the primary info */
    .cell-name {
        /* Adjusted for profile image container. Must allow the flex child to dictate height. */
        padding-left: 0; 
        padding-right: 0;
        margin-bottom: 5px;
    }
    .cell-name:before {
        content: none; /* Name/Profile should not have a label */
    }
    .cell-email:before {
        content: 'Email'; 
    }
    
    /* Move status/role to top right of card header */
    .user-table tbody tr td:nth-child(4), /* Role */
    .user-table tbody tr td:nth-child(5) { /* Status */
        display: inline-block;
        width: auto;
        padding: 0 0 5px 0; 
        float: right; 
        clear: none; 
        margin-left: 8px; 
    }
    
    .user-table tbody tr td:nth-child(5) { /* Status */
        float: right; 
        order: -1; /* Move it to the very top */
    }
    
    .user-table tbody tr td:nth-child(4) { /* Role */
        float: right; 
        order: -1; /* Move it to the very top */
    }

    /* --- CORRECTED ORDERING LOGIC --- */

    /* 1. Name cell (Profile/Name) must be order 0 to be the first element after the floating badges (-1) */
    .user-table tbody tr td:nth-child(2) { /* Name */
        order: 0; 
        text-align: left; /* Ensures it is aligned to the left (Top-Left) */
    }
    
    /* 2. ID cell (Cell 1) moves slightly down */
    .user-table tbody tr td:nth-child(1) {
        order: 0.5; 
        text-align: left; /* Ensure ID is aligned with Name */
    }

    /* 3. Email and Joined move to order 1 */
    .user-table tbody tr td:nth-child(3), /* Email */
    .user-table tbody tr td:nth-child(6) { /* Joined */
        order: 1; 
    }
    
    /* Role and Status badges should not have pseudo-elements (labels) */
    .user-table tbody tr td:nth-child(4):before,
    .user-table tbody tr td:nth-child(5):before {
        content: none;
    }
    
    /* 4. Ensure action button is last */
    .user-table tbody tr td:last-child {
        order: 2;
    }
    
    .user-table tbody tr {
        display: flex;
        flex-direction: column;
    }
}
</style>

<div class="user-shell">
    <div class="user-header">
        <div class="user-title-block">
            <h1>User management</h1>
            <p>Review roles, status, and activity across your user base.</p>
        </div>
        <div class="user-search-wrap">
            <div class="search-wrap">
                <span class="user-search-icon">
                    <i class="fas fa-search"></i>
                </span>
                <input type="text"
                       id="searchCustomer"
                       class="user-search-input"
                       placeholder="Search by name, email, or role">
            </div>
        </div>
    </div>

    <div class="user-stats">
        <div class="user-stat-pill">
            <i class="fas fa-users"></i>
            <?php echo count($customers); ?> total users
        </div>
        <div class="user-stat-pill">
            <i class="fas fa-user-shield"></i>
            Admins:
            <?php echo count(array_filter($customers, fn($u) => $u['role'] === 'admin')); ?>
        </div>
        <div class="user-stat-pill">
            <i class="fas fa-user"></i>
            Customers:
            <?php echo count(array_filter($customers, fn($u) => $u['role'] === 'customer')); ?>
        </div>
        <div class="user-stat-pill">
            <i class="fas fa-building"></i>
            Business:
            <?php echo count(array_filter($customers, fn($u) => $u['role'] === 'business')); ?>
        </div>
    </div>

    <div class="user-card">
        <div class="user-card-header">
            <div>
                <h5>All registered users</h5>
                <div class="user-card-sub">Search, inspect, and manage account access.</div>
            </div>
        </div>
        <div class="user-table-wrap">
            <table class="user-table" id="customerTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customers as $c): ?>
                    <?php $statusData = get_user_status($c['suspended_until'], $c['last_login_at']); ?>
                    <tr>
                        <td class="cell-id" data-label="ID">#<?php echo (int)$c['id']; ?></td>
                        <td class="cell-name" data-label="Name">
                            <div style="display: flex; align-items: flex-start; gap: 10px; min-height: 40px; width: 100%;">
                                <?php
                                    $profileImage = $c['profile_image'] ?? '';
                                    if (!empty($profileImage)):
                                ?>
                                    <img src="<?php echo htmlspecialchars($profileImage); ?>" 
                                         alt="<?php echo htmlspecialchars($c['name']); ?>'s profile image"
                                         style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 1px solid #e2e8f0; min-width: 38px;">
                                <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background-color: #e5e7eb; color: #9ca3af; font-size: 1.2rem; border: 1px solid #e2e8f0; min-width: 38px;">
                                        <i class="fas fa-user-circle"></i>
                                    </span>
                                <?php endif; ?>
                                <span style="display: block; font-weight: inherit; font-size: 1.1rem; line-height: 1.2; padding-top: 8px;"><?php echo htmlspecialchars($c['name']); ?></span>
                            </div>
                        </td>
                        <td class="cell-email" data-label="Email">
                            <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>">
                                <?php echo htmlspecialchars($c['email']); ?>
                            </a>
                        </td>
                        <td data-label="Role">
                            <span class="role-badge <?php echo get_role_badge_class($c['role']); ?>">
                                <span class="role-dot"></span>
                                <?php echo ucfirst($c['role']); ?>
                            </span>
                        </td>
                        <td data-label="Status">
                            <span class="status-badge <?php echo $statusData['class']; ?>">
                                <?php echo $statusData['status']; ?>
                            </span>
                        </td>
                        <td class="text-muted" data-label="Joined">
                            <?php echo date("M d, Y", strtotime($c['created_at'])); ?>
                        </td>
                        <td style="text-align:right;" data-label="Actions">
                            <a href="user_actions.php?id=<?php echo (int)$c['id']; ?>" class="manage-btn">
                                <i class="fas fa-sliders"></i>
                                Manage
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Live Search
document.getElementById('searchCustomer').addEventListener('keyup', function () {
    const filter = this.value.toLowerCase();
    const rows   = document.querySelectorAll("#customerTable tbody tr");

    rows.forEach(row => {
        // Search against all text in the row
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? "" : "none";
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>