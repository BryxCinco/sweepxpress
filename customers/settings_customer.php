<?php
// /customer/settings_customer.php

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php'; 

// Redirect if not logged in
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    header('Location: /sweepxpress/login.php');
    exit;
}

$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dbUser) {
    die("User record not found.");
}

// Update session
$_SESSION['user'] = array_merge([
    'id' => '',
    'username' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'gender' => '',
    'birthdate' => '',
    'profile_image' => '/sweepxpress/uploads/profiles/default-avatar.png',
    'streetaddress' => '',
    'city' => '',
    'zipcode' => ''
], $dbUser);

$user = $_SESSION['user'];

// Update profile
if (isset($_POST['update_profile'])) {
    $username = trim($_POST['username']);
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $gender = $_POST['gender'];
    $birthdate = $_POST['birthdate'];
    $streetAddress = trim($_POST['streetaddress']);
    $city = trim($_POST['city']);
    $zipCode = trim($_POST['zipcode']);

    $profileImage = $user['profile_image'];
    $validationError = false;

    // SERVER-SIDE VALIDATION FIXES
    // Phone: Philippine format 09 followed by 9 digits (total 11 digits)
    if ($phone !== '') {
        if (!preg_match('/^09\d{9}$/', $phone)) {
            echo "<script>
                const toast = document.createElement('div');
                toast.className = 'error-toast';
                toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Phone must be 11 digits starting with 09 (e.g., 09564321020)';
                document.body.appendChild(toast);
                setTimeout(() => toast.classList.add('show'), 100);
                setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 4000);
            </script>";
            $validationError = true;
        }
    }

    // Zip code: digits only
    if ($zipCode !== '' && !preg_match('/^\d+$/', $zipCode)) {
        echo "<script>
            const toast = document.createElement('div');
            toast.className = 'error-toast';
            toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Invalid zip code';
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
        </script>";
        $validationError = true;
    }

    // Birthdate: cannot be in the future
    if (!empty($birthdate) && strtotime($birthdate) > strtotime('today')) {
        echo "<script>
            const toast = document.createElement('div');
            toast.className = 'error-toast';
            toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Birthdate cannot be in the future';
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
        </script>";
        $validationError = true;
    }

    // Username: hindi puwedeng blangko o kapareho ng ibang user
    // (gumagana kahit wala pang UNIQUE index sa DB; ang index ang huling proteksyon)
    if (!$validationError) {
        $usernameError = '';
        if ($username === '') {
            $usernameError = 'Username is required';
        } else {
            $dup = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $dup->execute([$username, $userId]);
            if ($dup->fetch()) {
                $usernameError = 'Username is already taken';
            }
        }

        if ($usernameError !== '') {
            echo "<script>
                const toast = document.createElement('div');
                toast.className = 'error-toast';
                toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> {$usernameError}';
                document.body.appendChild(toast);
                setTimeout(() => toast.classList.add('show'), 100);
                setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
            </script>";
            $validationError = true;
        }
    }

    // Handle profile image upload
    if (!$validationError && !empty($_FILES['profile_image']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $originalFilename = basename($_FILES['profile_image']['name']);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png'];

        if (in_array($extension, $allowed)) {
            $newFile = time() . '_' . md5(uniqid()) . '.' . $extension;
            $uploadFile = $uploadDir . $newFile;

            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadFile)) {
                $profileImage = "/sweepxpress/uploads/profiles/" . $newFile;
            }
        }
    }

    // Only update if no validation errors
    if (!$validationError) {
        $sql = "UPDATE users SET username = ?, name = ?, phone = ?, gender = ?, birthdate = ?, profile_image = ?, streetaddress = ?, city = ?, zipcode = ? WHERE id = ?";
        $params = [$username, $name, $phone, $gender, $birthdate, $profileImage, $streetAddress, $city, $zipCode, $userId];

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $newData = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $newData->execute([$userId]);
            $_SESSION['user'] = array_merge($_SESSION['user'], $newData->fetch(PDO::FETCH_ASSOC));
            $user = $_SESSION['user'];

            echo "<script>
                const toast = document.createElement('div');
                toast.className = 'success-toast';
                toast.innerHTML = '<i class=\"bi bi-check-circle-fill\"></i> Profile updated successfully!';
                document.body.appendChild(toast);
                setTimeout(() => toast.classList.add('show'), 100);
                setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
                setTimeout(() => window.location.href='settings_customer.php?tab=general', 1500);
            </script>";
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                echo "<script>
                    const toast = document.createElement('div');
                    toast.className = 'error-toast';
                    toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Username is already taken';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.classList.add('show'), 100);
                    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
                </script>";
            } else {
                error_log("Profile update failed for user {$userId}: " . $e->getMessage());
                echo "<script>
                    const toast = document.createElement('div');
                    toast.className = 'error-toast';
                    toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Could not save your profile. Please try again.';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.classList.add('show'), 100);
                    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
                </script>";
            }
        }
    }
}

// Change password
if (isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $newpass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $dbUser['password_hash'] ?? '')) {
        echo "<script>
            const toast = document.createElement('div');
            toast.className = 'error-toast';
            toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Incorrect current password';
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
        </script>";
    } elseif ($newpass !== $confirm) {
        echo "<script>
            const toast = document.createElement('div');
            toast.className = 'error-toast';
            toast.innerHTML = '<i class=\"bi bi-exclamation-circle-fill\"></i> Passwords do not match';
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
        </script>";
    } else {
        $hash = password_hash($newpass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->execute([$hash, $userId]);
        echo "<script>
            const toast = document.createElement('div');
            toast.className = 'success-toast';
            toast.innerHTML = '<i class=\"bi bi-check-circle-fill\"></i> Password updated successfully!';
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 100);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
            setTimeout(() => window.location.href='settings_customer.php?tab=general', 1500);
        </script>";
    }
}

$displayImagePath = htmlspecialchars($user['profile_image'] ?? '/sweepxpress/assets/default-avatar.png');
if (!empty($user['profile_image'])) {
    $displayImagePath .= '?' . time();
}

$tab = $_GET['tab'] ?? 'general';
?>

<style>
/* ============================================
   2025 ENTERPRISE SETTINGS UI - SWEEPXPRESS
   Mobile-First | Trust-Focused | Pixel Perfect
   ============================================ */

:root {
    /* Brand Colors - Trust & Professional */
    --primary-600: #2563eb;
    --primary-700: #1d4ed8;
    --primary-50: #eff6ff;
    --primary-100: #dbeafe;
    
    --success-600: #059669;
    --success-50: #ecfdf5;
    
    --error-600: #dc2626;
    --error-50: #fef2f2;
    
    /* Neutrals - Modern Palette */
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    
    /* Semantic Colors */
    --bg-page: #f8fafc;
    --bg-card: #ffffff;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;
    --border-color: #e2e8f0;
    
    /* Typography - Fluid Scaling */
    --text-xs: clamp(0.75rem, 0.7rem + 0.2vw, 0.8125rem);
    --text-sm: clamp(0.875rem, 0.85rem + 0.125vw, 0.9375rem);
    --text-base: clamp(1rem, 0.975rem + 0.125vw, 1.0625rem);
    --text-lg: clamp(1.125rem, 1.075rem + 0.25vw, 1.25rem);
    --text-xl: clamp(1.25rem, 1.175rem + 0.375vw, 1.5rem);
    --text-2xl: clamp(1.5rem, 1.375rem + 0.625vw, 1.875rem);
    
    /* Spacing - 4px Base Grid */
    --space-1: 0.25rem;
    --space-2: 0.5rem;
    --space-3: 0.75rem;
    --space-4: 1rem;
    --space-5: 1.25rem;
    --space-6: 1.5rem;
    --space-8: 2rem;
    --space-10: 2.5rem;
    --space-12: 3rem;
    --space-16: 4rem;
    
    /* Border Radius */
    --radius-sm: 0.5rem;
    --radius-md: 0.75rem;
    --radius-lg: 1rem;
    --radius-xl: 1.5rem;
    --radius-full: 9999px;
    
    /* Shadows - Layered Depth */
    --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    
    /* Transitions */
    --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
    --transition-base: 200ms cubic-bezier(0.4, 0, 0.2, 1);
    
    /* Font Stack */
    --font-sans: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

/* Base Reset */
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: var(--font-sans);
    font-size: var(--text-base);
    line-height: 1.6;
    color: var(--text-primary);
    background: linear-gradient(135deg, #f0f9ff 0%, #f8fafc 50%, #fafafa 100%);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Main Container */
.settings-page {
    min-height: calc(100vh - 80px);
    padding: var(--space-6) var(--space-4);
    display: flex;
    justify-content: center;
    align-items: flex-start;
}

.settings-wrapper {
    width: 100%;
    max-width: 75rem;
    background: var(--bg-card);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    border: 1px solid var(--border-color);
    overflow: hidden;
    display: flex;
    min-height: calc(100vh - 200px);
}

/* Sidebar Navigation */
.settings-sidebar {
    width: 17rem;
    background: linear-gradient(180deg, var(--gray-50) 0%, var(--primary-50) 100%);
    border-right: 1px solid var(--border-color);
    padding: var(--space-8) var(--space-6);
    flex-shrink: 0;
}

.settings-sidebar h2 {
    font-size: var(--text-xs);
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: var(--space-6);
}

.settings-nav-link {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-3) var(--space-4);
    margin-bottom: var(--space-2);
    border-radius: var(--radius-md);
    text-decoration: none;
    color: var(--text-primary);
    font-weight: 500;
    font-size: var(--text-sm);
    transition: all var(--transition-fast);
    position: relative;
}

.settings-nav-link::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 0;
    background: var(--primary-600);
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
    transition: height var(--transition-fast);
}

.settings-nav-link:hover {
    background: var(--gray-100);
    transform: translateX(4px);
}

.settings-nav-link:hover::before {
    height: 1.5rem;
}

.settings-nav-link.active {
    background: linear-gradient(135deg, var(--primary-600), #3b82f6);
    color: white;
    font-weight: 600;
    box-shadow: var(--shadow-md);
}

.settings-nav-link.active::before {
    height: 2rem;
}

.settings-nav-link.active i {
    color: white;
}

.settings-nav-link i {
    font-size: 1.125rem;
    color: var(--text-secondary);
    transition: color var(--transition-fast);
}

/* Content Area */
.settings-content {
    flex: 1;
    padding: var(--space-8);
    overflow-y: auto;
}

/* Profile Header Card */
.profile-header-strip {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: var(--radius-lg);
    padding: var(--space-8);
    display: flex;
    align-items: center;
    gap: var(--space-6);
    margin-bottom: var(--space-8);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
    position: relative;
    overflow: hidden;
}

.profile-header-strip::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-600), #3b82f6, #60a5fa);
}

.profile-header-strip img {
    width: 5.5rem;
    height: 5.5rem;
    border-radius: var(--radius-full);
    object-fit: cover;
    border: 3px solid white;
    box-shadow: var(--shadow-lg);
    flex-shrink: 0;
}

.profile-header-strip > div {
    flex: 1;
    min-width: 0;
}

.profile-header-strip .fw-semibold {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: var(--space-1);
}

.profile-header-strip .user-email {
    font-size: var(--text-sm);
    color: var(--text-secondary);
    margin-bottom: var(--space-3);
}

.profile-header-strip .change-link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--primary-600);
    cursor: pointer;
    transition: all var(--transition-fast);
    padding: var(--space-2) var(--space-4);
    border-radius: var(--radius-full);
    background: var(--primary-50);
}

.profile-header-strip .change-link:hover {
    background: var(--primary-100);
    transform: translateY(-1px);
}

/* Info Rows - Card Style */
.info-row {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: var(--space-5) var(--space-6);
    margin-bottom: var(--space-4);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: var(--space-4);
    transition: all var(--transition-fast);
}

.info-row:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
    border-color: var(--gray-300);
}

.info-row > div:first-child {
    flex: 1;
    min-width: 0;
}

.info-label {
    font-size: var(--text-xs);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    margin-bottom: var(--space-2);
}

.info-value {
    font-size: var(--text-base);
    font-weight: 500;
    color: var(--text-primary);
    word-break: break-word;
}

.info-right {
    flex-shrink: 0;
}

.info-right button {
    font-size: var(--text-sm);
    font-weight: 600;
    padding: var(--space-2) var(--space-5);
    border-radius: var(--radius-full);
    border: 1px solid var(--primary-600);
    color: var(--primary-600);
    background: transparent;
    cursor: pointer;
    transition: all var(--transition-fast);
    min-height: 2.25rem;
}

.info-right button:hover {
    background: linear-gradient(135deg, var(--primary-600), #3b82f6);
    color: white;
    box-shadow: var(--shadow-md);
    transform: translateY(-1px);
}

.info-right .text-muted {
    font-size: var(--text-sm);
    color: var(--text-muted);
}

/* Modals */
.settings-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    justify-content: center;
    align-items: center;
    padding: var(--space-4);
    overflow-y: auto;
}

.settings-modal-panel {
    background: var(--bg-card);
    width: 100%;
    max-width: 40rem;
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow-xl);
    border: 1px solid var(--border-color);
    max-height: 90vh;
    overflow-y: auto;
}

.settings-modal-panel h3 {
    font-size: var(--text-xl);
    font-weight: 700;
    color: var(--text-primary);
    padding: var(--space-6) var(--space-8);
    border-bottom: 1px solid var(--border-color);
    margin: 0;
}

.settings-modal-panel form {
    padding: var(--space-8);
}

/* Profile Upload Section */
.modal-profile-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: var(--space-6) 0 var(--space-8);
    border-bottom: 1px solid var(--border-color);
    margin-bottom: var(--space-6);
}

.modal-profile-group img {
    width: 7rem;
    height: 7rem;
    border-radius: var(--radius-full);
    object-fit: cover;
    border: 3px solid var(--border-color);
    margin-bottom: var(--space-4);
    box-shadow: var(--shadow-md);
}

/* Form Elements */
.form-group {
    margin-bottom: var(--space-5);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: var(--space-5);
}

.form-grid .full-width {
    grid-column: 1 / -1;
}

.settings-modal-panel label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: var(--space-2);
}

.settings-modal-panel input,
.settings-modal-panel select {
    width: 100%;
    padding: var(--space-3) var(--space-4);
    border-radius: var(--radius-md);
    border: 1px solid var(--border-color);
    font-size: var(--text-base);
    background: var(--gray-50);
    color: var(--text-primary);
    transition: all var(--transition-fast);
}

.settings-modal-panel input:focus,
.settings-modal-panel select:focus {
    outline: none;
    border-color: var(--primary-600);
    box-shadow: 0 0 0 3px var(--primary-50);
    background: white;
}

.settings-modal-panel input[type="file"] {
    background: transparent;
    border: 1px dashed var(--border-color);
    padding: var(--space-4);
    cursor: pointer;
}

.settings-modal-panel input[type="file"]:hover {
    border-color: var(--primary-600);
    background: var(--primary-50);
}

/* Modal Actions */
.mt-4 {
    margin-top: var(--space-6);
    padding-top: var(--space-6);
    border-top: 1px solid var(--border-color);
}

.btn-pill-primary {
    background: linear-gradient(135deg, var(--primary-600), #3b82f6);
    color: white;
    border: none;
    border-radius: var(--radius-full);
    padding: var(--space-3) var(--space-8);
    font-size: var(--text-base);
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    box-shadow: var(--shadow-md);
    min-height: 2.75rem;
}

.btn-pill-primary:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.btn-pill-secondary {
    background: transparent;
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-full);
    padding: var(--space-3) var(--space-6);
    font-size: var(--text-base);
    font-weight: 600;
    cursor: pointer;
    transition: all var(--transition-fast);
    margin-left: var(--space-3);
    min-height: 2.75rem;
}

.btn-pill-secondary:hover {
    background: var(--gray-100);
    border-color: var(--gray-300);
}

/* Toast Notifications */
.success-toast,
.error-toast {
    position: fixed;
    top: var(--space-8);
    right: var(--space-8);
    padding: var(--space-4) var(--space-6);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    font-weight: 600;
    font-size: var(--text-base);
    display: flex;
    align-items: center;
    gap: var(--space-3);
    z-index: 9999;
    transform: translateX(calc(100% + var(--space-8)));
    transition: transform var(--transition-base);
}

.success-toast {
    background: var(--success-50);
    color: var(--success-600);
    border: 1px solid var(--success-600);
}

.error-toast {
    background: var(--error-50);
    color: var(--error-600);
    border: 1px solid var(--error-600);
}

.success-toast.show,
.error-toast.show {
    transform: translateX(0);
}

/* Responsive Design */
@media (max-width: 64rem) {
    .settings-wrapper {
        max-width: 100%;
    }
}

@media (max-width: 56rem) {
    .settings-wrapper {
        flex-direction: column;
    }
    
    .settings-sidebar {
        width: 100%;
        border-right: none;
        border-bottom: 1px solid var(--border-color);
        padding: var(--space-6);
    }
    
    .settings-nav-link {
        justify-content: center;
    }
    
    .profile-header-strip {
        flex-direction: column;
        text-align: center;
        padding: var(--space-6);
    }
    
    .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-3);
    }
    
    .info-right {
        width: 100%;
    }
    
    .info-right button {
        width: 100%;
    }
}

@media (max-width: 40rem) {
    .settings-page {
        padding: var(--space-4) var(--space-2);
    }
    
    .settings-content {
        padding: var(--space-6) var(--space-4);
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-grid .full-width {
        grid-column: 1;
    }
    
    .profile-header-strip img {
        width: 4.5rem;
        height: 4.5rem;
    }
    
    .success-toast,
    .error-toast {
        right: var(--space-4);
        left: var(--space-4);
        top: var(--space-4);
    }
}
</style>

<div class="settings-page">
    <div class="settings-wrapper">
        <aside class="settings-sidebar">
            <h2>Account Settings</h2>
            <a href="?tab=general" class="settings-nav-link <?= $tab === 'general' ? 'active' : ''; ?>">
                <i class="bi bi-person-gear"></i>
                <span>General</span>
            </a>
        </aside>

        <section class="settings-content">
            <?php if ($tab === 'general'): ?>
                <div class="profile-header-strip">
                    <img src="<?= $displayImagePath; ?>" alt="Profile">
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($user['name']); ?></div>
                        <div class="user-email"><?= htmlspecialchars($user['email']); ?></div>
                        <span class="change-link" onclick="openProfileModal()">
                            <i class="bi bi-camera"></i>Edit Profile
                        </span>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= htmlspecialchars($user['username']); ?></div>
                    </div>
                    <div class="info-right">
                        <button onclick="openProfileModal()">Edit</button>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?= htmlspecialchars($user['name']); ?></div>
                    </div>
                    <div class="info-right">
                        <button onclick="openProfileModal()">Edit</button>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Email Address</div>
                        <div class="info-value"><?= htmlspecialchars($user['email']); ?></div>
                    </div>
                    <div class="info-right">
                        <span class="text-muted">Cannot be changed</span>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Phone Number</div>
                        <div class="info-value">
                            <?= htmlspecialchars($user['phone'] ?? 'No phone number saved'); ?>
                        </div>
                    </div>
                    <div class="info-right">
                        <button onclick="openProfileModal()">Edit</button>
                    </div>
                </div>
                
                <div class="info-row">
                    <div>
                        <div class="info-label">Address</div>
                        <div class="info-value">
                            <?php if (!empty($user['streetaddress']) || !empty($user['city']) || !empty($user['zipcode'])): ?>
                                <?= htmlspecialchars($user['streetaddress'] ?? ''); ?><br>
                                <?= htmlspecialchars($user['city'] ?? ''); ?> <?= htmlspecialchars($user['zipcode'] ?? ''); ?>
                            <?php else: ?>
                                No address saved
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-right">
                        <button onclick="openProfileModal()">Edit</button>
                    </div>
                </div>
                
                <div class="info-row">
                    <div>
                        <div class="info-label">Personal Information</div>
                        <div class="info-value">
                            <?= htmlspecialchars($user['gender'] ?? 'Not specified'); ?> • 
                            <?= $user['birthdate'] ? date('M d, Y', strtotime($user['birthdate'])) : 'Not specified' ?>
                        </div>
                    </div>
                    <div class="info-right">
                        <button onclick="openProfileModal()">Edit</button>
                    </div>
                </div>

                <div class="info-row">
                    <div>
                        <div class="info-label">Password</div>
                        <div class="info-value">••••••••</div>
                    </div>
                    <div class="info-right">
                        <button onclick="openPasswordModal()">Change</button>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<div id="profileModal" class="settings-modal">
    <div class="settings-modal-panel">
        <h3>Edit Profile</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="update_profile" value="1">
            
            <div class="modal-profile-group">
                <img id="profileImagePreview" src="<?= $displayImagePath; ?>" alt="Profile">
                <div class="form-group full-width">
                    <label>Profile Photo</label>
                    <input type="file" name="profile_image" id="profileImgInput" accept="image/*">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Username</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']); ?>" required>
                </div>
                
                <div class="form-group full-width">
                    <label>Full Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($user['name']); ?>" required>
                </div>

                <div class="form-group">
                    <label>Phone Number (e.g., 09564321020)</label>
                    <!-- CLIENT-SIDE: 11 digits, starts with 09 -->
                    <input 
                        type="tel" 
                        name="phone" 
                        value="<?= htmlspecialchars($user['phone'] ?? ''); ?>"
                        placeholder="09XXXXXXXXX"
                        maxlength="11"
                        pattern="^09\d{9}$"
                        title="Phone number must be 11 digits starting with 09"
                        inputmode="numeric"
                    >
                </div>
                
                <div class="form-group">
                    <label>Date of Birth</label>
                    <!-- CLIENT-SIDE MAX TODAY -->
                    <input 
                        type="date" 
                        name="birthdate" 
                        id="birthdateInput"
                        value="<?= htmlspecialchars($user['birthdate'] ?? ''); ?>">
                </div>
                
                <div class="form-group full-width">
                    <label>Gender</label>
                    <select name="gender" required>
                        <option value="Male" <?= ($user['gender'] === 'Male' ? 'selected' : ''); ?>>Male</option>
                        <option value="Female" <?= ($user['gender'] === 'Female' ? 'selected' : ''); ?>>Female</option>
                        <option value="Other" <?= ($user['gender'] === 'Other' ? 'selected' : ''); ?>>Other</option>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label>Street Address</label>
                    <input type="text" name="streetaddress" value="<?= htmlspecialchars($user['streetaddress'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" value="<?= htmlspecialchars($user['city'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label>Zip Code</label>
                    <!-- ZIP: DIGITS ONLY -->
                    <input 
                        type="text" 
                        name="zipcode" 
                        value="<?= htmlspecialchars($user['zipcode'] ?? ''); ?>"
                        inputmode="numeric"
                        pattern="\d*"
                    >
                </div>
            </div>

            <div class="mt-4 d-flex justify-content-end">
                <button type="submit" class="btn-pill-primary">Save Changes</button>
                <button type="button" class="btn-pill-secondary" onclick="closeProfileModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="passwordModal" class="settings-modal">
    <div class="settings-modal-panel">
        <h3>Change Password</h3>
        <form method="POST">
            <input type="hidden" name="change_password" value="1">

            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
            </div>

            <div class="mt-4 d-flex justify-content-end">
                <button type="submit" class="btn-pill-primary">Update Password</button>
                <button type="button" class="btn-pill-secondary" onclick="closePasswordModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProfileModal() {
    document.getElementById('profileModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeProfileModal() {
    document.getElementById('profileModal').style.display = 'none';
    document.body.style.overflow = '';
}

function openPasswordModal() {
    document.getElementById('passwordModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePasswordModal() {
    document.getElementById('passwordModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Profile image live preview
document.getElementById('profileImgInput')?.addEventListener('change', function(event) {
    const file = event.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('profileImagePreview').src = e.target.result;
            document.querySelector('.profile-header-strip img').src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
});

// Close modals on overlay click
document.querySelectorAll('.settings-modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
            document.body.style.overflow = '';
        }
    });
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.settings-modal').forEach(modal => {
            modal.style.display = 'none';
        });
        document.body.style.overflow = '';
    }
});

// JS: set max birthdate to today
(function() {
    const birthInput = document.getElementById('birthdateInput');
    if (birthInput) {
        const today = new Date().toISOString().split('T')[0];
        birthInput.setAttribute('max', today);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
