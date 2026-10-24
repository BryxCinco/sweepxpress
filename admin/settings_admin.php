<?php
// /admin/settings_admin.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';

if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

if (!is_admin()) {
    header("Location: /sweepxpress/index.php");
    exit;
}

// Get admin data
$adminId = $_SESSION['user']['id'] ?? null;
if (!$adminId) {
    header("Location: /sweepxpress/login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    die("Admin record not found.");
}

// Update profile
if (isset($_POST['update_profile'])) {
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);

    $profile_image = $admin['profile_image'];

    if (!empty($_FILES['profile_image']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/admin/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $newFile    = time() . '_' . basename($_FILES['profile_image']['name']);
        $uploadFile = $uploadDir . $newFile;

        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadFile)) {
            $profile_image = "/sweepxpress/uploads/admin/" . $newFile;
        }
    }

    $sql    = "UPDATE users SET name = ?, email = ?, phone = ?, profile_image = ? WHERE id = ?";
    $params = [$name, $email, $phone, $profile_image, $adminId];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $newData = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $newData->execute([$adminId]);
    $_SESSION['user'] = $newData->fetch(PDO::FETCH_ASSOC);

    echo "<script>window.location.href='settings_admin.php?tab=general';</script>";
    exit;
}

// Change password
if (isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $newpass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $admin['password'])) {
        echo "<script>alert('Incorrect current password.');</script>";
    } elseif ($newpass !== $confirm) {
        echo "<script>alert('Passwords do not match.');</script>";
    } else {
        $hash = password_hash($newpass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->execute([$hash, $adminId]);
        echo "<script>alert('Password updated successfully!');location.href='settings_admin.php?tab=general';</script>";
    }
}

$displayImagePath = htmlspecialchars($admin['profile_image'] ?? '/sweepxpress/assets/profile-icon.jpg');
$tab              = $_GET['tab'] ?? 'general';
?>

<style>
:root {
    --bg-page:#f5f7fb;
    --bg-shell:#ffffff;
    --bg-soft:#f9fafb;
    --border-soft:#e5e7eb;
    --blue-600:#2563eb;
    --blue-500:#3b82f6;
    --blue-100:#dbeafe;
    --text-main:#0f172a;
    --text-muted:#6b7280;
    --text-soft:#9ca3af;
    --radius-xl:24px;
    --radius-md:16px;
    --radius-pill:999px;
    --shadow-shell:0 20px 60px rgba(15,23,42,0.12);
    --shadow-soft:0 10px 30px rgba(15,23,42,0.08);
    --transition-fast:.18s cubic-bezier(.4,0,.2,1);
}

body {
    background: radial-gradient(circle at top,#e5edff 0,#f5f7fb 40%,#f9fafb 100%);
    font-family: Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

/* PAGE */
.settings-page {
    min-height: calc(100vh - 90px);
    padding: 32px 32px 40px;
    display:flex;
    justify-content:center;
    align-items:flex-start;
}

/* SHELL */
.settings-wrapper {
    width:100%;
    max-width:1050px;
    background:var(--bg-shell);
    border-radius:var(--radius-xl);
    box-shadow:var(--shadow-shell);
    border:1px solid rgba(226,232,240,0.9);
    display:flex;
    overflow:hidden;
}

/* SIDEBAR */
.settings-sidebar {
    width:230px;
    background:linear-gradient(180deg,#f9fafb 0,#edf2ff 45%,#e5e9f5 100%);
    padding:22px 18px 20px;
    border-right:1px solid rgba(226,232,240,0.9);
}

.settings-sidebar h2 {
    font-size:0.78rem;
    font-weight:700;
    letter-spacing:.18em;
    text-transform:uppercase;
    color:var(--text-soft);
    margin-bottom:18px;
}

.settings-nav-link {
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    margin-bottom:8px;
    text-decoration:none;
    border-radius:var(--radius-md);
    font-size:0.9rem;
    font-weight:500;
    color:var(--text-main);
    transition:background var(--transition-fast),color var(--transition-fast),box-shadow var(--transition-fast),transform var(--transition-fast);
}

.settings-nav-link i {
    font-size:1rem;
    color:var(--text-soft);
}

.settings-nav-link:hover {
    background:var(--bg-soft);
    box-shadow:0 3px 10px rgba(148,163,184,0.35);
    transform:translateX(2px);
}

.settings-nav-link.active {
    background:linear-gradient(135deg,#2563eb,#60a5fa);
    color:#ffffff;
    box-shadow:0 10px 25px rgba(37,99,235,0.55);
}

.settings-nav-link.active i {
    color:#e0ebff;
}

/* CONTENT */
.settings-content {
    flex:1;
    padding:26px 30px 26px;
}

/* HEADER STRIP */
.profile-header-strip {
    background: linear-gradient(135deg,#ffffff 0,#eef2ff 45%,#e0f2fe 100%);
    border-radius:18px;
    padding:18px 20px;
    display:flex;
    align-items:center;
    gap:18px;
    margin-bottom:24px;
    box-shadow:var(--shadow-soft);
    border:1px solid rgba(226,232,240,0.9);
}

.profile-header-strip img {
    width:78px;
    height:78px;
    border-radius:50%;
    object-fit:cover;
    border:3px solid #ffffff;
    box-shadow:0 10px 24px rgba(15,23,42,0.35);
}

.profile-header-strip .admin-email{
    font-size:0.88rem;
    color:var(--text-muted);
}

.profile-header-strip .change-link{
    font-size:0.83rem;
    font-weight:500;
    color:var(--blue-600);
    cursor:pointer;
}

/* INFO ROWS */
.info-row {
    padding:18px 4px;
    border-bottom:1px solid var(--border-soft);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
}

.info-label {
    font-size:0.78rem;
    text-transform:uppercase;
    letter-spacing:.15em;
    color:var(--text-soft);
    margin-bottom:3px;
}

.info-value {
    font-size:0.96rem;
    font-weight:500;
    color:var(--text-main);
}

.info-right button{
    font-size:0.82rem;
    padding:6px 18px;
    border-radius:var(--radius-pill)!important;
}

/* Outline pill buttons */
.btn-outline-primary.rounded-pill {
    border-radius:var(--radius-pill)!important;
    border-color:rgba(37,99,235,0.6);
    color:var(--blue-600);
    background:transparent;
    transition:background var(--transition-fast),color var(--transition-fast),box-shadow var(--transition-fast),transform var(--transition-fast);
}
.btn-outline-primary.rounded-pill:hover {
    background:linear-gradient(135deg,#2563eb,#60a5fa);
    color:#ffffff;
    box-shadow:0 8px 22px rgba(37,99,235,0.55);
    transform:translateY(-1px);
}

/* MODALS */
.settings-modal {
    display:none;
    position:fixed;
    inset:0;
    z-index:1100;
    background:rgba(15,23,42,0.65);
    justify-content:center;
    align-items:center;
}

.settings-modal-panel {
    background:#ffffff;
    width:380px;
    max-width:92%;
    border-radius:20px;
    padding:22px 22px 18px;
    box-shadow:0 28px 70px rgba(15,23,42,0.85);
    border:1px solid rgba(226,232,240,0.9);
}

.settings-modal-panel h3{
    font-size:1.02rem;
    font-weight:650;
    color:var(--text-main);
    margin-bottom:16px;
}

.settings-modal-panel input{
    width:100%;
    padding:9px 11px;
    margin-bottom:11px;
    border-radius:12px;
    border:1px solid #d1d5db;
    font-size:0.9rem;
    background:#f9fafb;
    transition:border-color var(--transition-fast),box-shadow var(--transition-fast),background var(--transition-fast);
}

.settings-modal-panel input:focus{
    outline:none;
    border-color:var(--blue-500);
    box-shadow:0 0 0 1px rgba(59,130,246,0.45);
    background:#ffffff;
}

.settings-modal-panel input[type="file"]{
    background:transparent;
    border:none;
    padding:4px 0 8px;
}

/* Pill buttons in modal */
.btn-pill-primary{
    background:linear-gradient(135deg,#2563eb,#60a5fa);
    color:#ffffff;
    border:none;
    border-radius:var(--radius-pill);
    padding:7px 18px;
    font-size:0.86rem;
    font-weight:600;
    box-shadow:0 10px 24px rgba(37,99,235,0.6);
    transition:transform var(--transition-fast),box-shadow var(--transition-fast);
}
.btn-pill-primary:hover{
    transform:translateY(-1px);
    box-shadow:0 14px 30px rgba(37,99,235,0.75);
}
.btn-pill-secondary{
    background:#e5e7eb;
    color:var(--text-main);
    border:none;
    border-radius:var(--radius-pill);
    padding:7px 16px;
    font-size:0.86rem;
    margin-left:8px;
    transition:background var(--transition-fast),color var(--transition-fast);
}
.btn-pill-secondary:hover{
    background:#d1d5db;
}

/* RESPONSIVE */
@media (max-width:900px){
    .settings-wrapper{flex-direction:column;}
    .settings-sidebar{
        width:100%;
        display:flex;
        align-items:center;
        justify-content:space-between;
        border-right:none;
        border-bottom:1px solid var(--border-soft);
    }
    .settings-content{padding:22px 20px 24px;}
}
@media (max-width:640px){
    .settings-page{padding:22px 14px 26px;}
}
</style>

<div class="settings-page">
    <div class="settings-wrapper">
        <!-- Sidebar -->
        <aside class="settings-sidebar">
            <h2>SETTINGS</h2>
            <a href="?tab=general"
               class="settings-nav-link <?= $tab === 'general' ? 'active' : ''; ?>">
                <i class="bi bi-person-gear"></i>
                <span>General</span>
            </a>
        </aside>

        <!-- Content -->
        <section class="settings-content">
            <?php if ($tab === 'general'): ?>
                <div class="profile-header-strip">
                    <img src="<?= $displayImagePath; ?>" alt="Profile">
                    <div>
                        <div class="fw-semibold mb-1"><?= htmlspecialchars($admin['name']); ?></div>
                        <div class="admin-email mb-1"><?= htmlspecialchars($admin['email']); ?></div>
                        <span class="change-link" onclick="openProfileModal()">
                            <i class="bi bi-camera me-1"></i>Change profile
                        </span>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= htmlspecialchars($admin['name']); ?></div>
                    </div>
                    <div class="info-right">
                        <button class="btn btn-outline-primary btn-sm rounded-pill"
                                onclick="openProfileModal()">Edit</button>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($admin['email']); ?></div>
                    </div>
                    <div class="info-right">
                        <button class="btn btn-outline-primary btn-sm rounded-pill"
                                onclick="openProfileModal()">Edit</button>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Phone</div>
                        <div class="info-value">
                            <?= htmlspecialchars($admin['phone'] ?? 'No number saved'); ?>
                        </div>
                    </div>
                    <div class="info-right">
                        <button class="btn btn-outline-primary btn-sm rounded-pill"
                                onclick="openProfileModal()">Edit</button>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Password</div>
                        <div class="info-value">********</div>
                    </div>
                    <div class="info-right">
                        <button class="btn btn-outline-primary btn-sm rounded-pill"
                                onclick="openPasswordModal()">Change</button>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- PROFILE MODAL -->
<div id="profileModal" class="settings-modal">
    <div class="settings-modal-panel">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            <h3>Edit profile</h3>

            <input type="text" name="name"
                   value="<?= htmlspecialchars($admin['name']); ?>"
                   placeholder="Username" required>
            <input type="email" name="email"
                   value="<?= htmlspecialchars($admin['email']); ?>"
                   placeholder="Email" required>
            <input type="text" name="phone"
                   value="<?= htmlspecialchars($admin['phone'] ?? ''); ?>"
                   placeholder="Phone">
            <input type="file" name="profile_image" accept="image/*">

            <div class="mt-2 d-flex justify-content-end">
                <button type="submit" class="btn-pill-primary">Save</button>
                <button type="button" class="btn-pill-secondary"
                        onclick="closeProfileModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- PASSWORD MODAL -->
<div id="passwordModal" class="settings-modal">
    <div class="settings-modal-panel">
        <form method="POST">
            <input type="hidden" name="change_password" value="1">
            <h3>Change password</h3>

            <input type="password" name="current_password"
                   placeholder="Current password" required>
            <input type="password" name="new_password"
                   placeholder="New password" required>
            <input type="password" name="confirm_password"
                   placeholder="Confirm new password" required>

            <div class="mt-2 d-flex justify-content-end">
                <button type="submit" class="btn-pill-primary">Update</button>
                <button type="button" class="btn-pill-secondary"
                        onclick="closePasswordModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProfileModal() {
    document.getElementById('profileModal').style.display = 'flex';
}
function closeProfileModal() {
    document.getElementById('profileModal').style.display = 'none';
}
function openPasswordModal() {
    document.getElementById('passwordModal').style.display = 'flex';
}
function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
