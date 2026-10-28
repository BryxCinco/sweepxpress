<?php
// customers/settings_customer.php — profile at password ng customer / business account.
// Ginagamit din ng admin/settings_admin.php (iisang settings page para sa lahat ng role).

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

// Redirect bago ang header (dati pagkatapos, kaya hindi gumagana)
$userId = $_SESSION['user']['id'] ?? null;
if (!$userId) {
    header('Location: /sweepxpress/login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
$csrf_token = $_SESSION['csrf_token'];

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dbUser) {
    die("User record not found.");
}

// Update session
$_SESSION['user'] = array_merge($_SESSION['user'], $dbUser);
$user = $_SESSION['user'];

$tab    = ($_GET['tab'] ?? '') === 'security' ? 'security' : 'profile';
$self   = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/sweepxpress/admin/') ? '/sweepxpress/admin/settings_admin.php' : '/sweepxpress/customers/settings_customer.php';
$errors = [];   // mga mensahe ng validation para sa kasalukuyang form
$old    = [];   // mga tinype ng user kapag may error, para hindi mawala

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Your session expired. Please try again.';
    }

    // ---------------- Update profile ----------------
    if (isset($_POST['update_profile'])) {
        $tab = 'profile';
        $old = $_POST;
        $username      = trim($_POST['username'] ?? '');
        $name          = trim($_POST['name'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $gender        = $_POST['gender'] ?? '';
        $birthdate     = $_POST['birthdate'] ?? '';
        $streetAddress = trim($_POST['streetaddress'] ?? '');
        $city          = trim($_POST['city'] ?? '');
        $zipCode       = trim($_POST['zipcode'] ?? '');
        $profileImage  = $user['profile_image'];

        if ($name === '') $errors[] = 'Full name is required.';
        // Phone: Philippine format 09 followed by 9 digits (total 11 digits)
        if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
            $errors[] = 'Phone must be 11 digits starting with 09 (e.g., 09564321020).';
        }
        if ($zipCode !== '' && !preg_match('/^\d{4}$/', $zipCode)) {
            $errors[] = 'Zip code must be 4 digits.';
        }
        if (!in_array($gender, ['', 'Male', 'Female', 'Other'], true)) {
            $errors[] = 'Please choose a valid gender.';
        }
        if ($birthdate !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $birthdate);
            if (!$d || $d->format('Y-m-d') !== $birthdate) {
                $errors[] = 'Please enter a valid birthdate.';
            } elseif ($d > new DateTime('today')) {
                $errors[] = 'Birthdate cannot be in the future.';
            }
        }

        // Username: hindi puwedeng blangko o kapareho ng ibang user
        // (gumagana kahit wala pang UNIQUE index sa DB; ang index ang huling proteksyon)
        if ($username === '') {
            $errors[] = 'Username is required.';
        } else {
            $dup = $pdo->prepare("SELECT 1 FROM users WHERE username = ? AND id <> ? LIMIT 1");
            $dup->execute([$username, $userId]);
            if ($dup->fetch()) $errors[] = 'Username is already taken.';
        }

        // Profile photo: JPG/PNG, hanggang 2 MB, at dapat totoong larawan
        if (!$errors && !empty($_FILES['profile_image']['name'])) {
            $file = $_FILES['profile_image'];
            $info = $file['error'] === UPLOAD_ERR_OK ? @getimagesize($file['tmp_name']) : false;
            $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];

            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Photo must be 2 MB or smaller.';
            } elseif (!$info || !isset($types[$info[2]])) {
                $errors[] = 'Photo must be a JPG or PNG image.';
            } else {
                $uploadDir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $newFile = time() . '_' . md5(uniqid('', true)) . '.' . $types[$info[2]];
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFile)) {
                    $profileImage = "/sweepxpress/uploads/profiles/" . $newFile;
                } else {
                    $errors[] = 'Could not upload your photo. Please try again.';
                }
            }
        }

        if (!$errors) {
            try {
                $pdo->prepare("
                    UPDATE users
                    SET username = ?, name = ?, phone = ?, gender = ?, birthdate = ?, profile_image = ?, streetaddress = ?, city = ?, zipcode = ?
                    WHERE id = ?
                ")->execute([
                    $username, $name, $phone ?: null, $gender ?: null, $birthdate ?: null, $profileImage,
                    $streetAddress ?: null, $city ?: null, $zipCode ?: null, $userId,
                ]);

                $_SESSION['sx_flash'] = ['success', 'Profile updated.'];
                header('Location: ' . $self);
                exit;
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $errors[] = 'Username is already taken.';
                } else {
                    error_log("Profile update failed for user {$userId}: " . $e->getMessage());
                    $errors[] = 'Could not save your profile. Please try again.';
                }
            }
        }
    }

    // ---------------- Change password ----------------
    if (isset($_POST['change_password'])) {
        $tab = 'security';
        $current = $_POST['current_password'] ?? '';
        $newpass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$errors) {
            if (!password_verify($current, $dbUser['password_hash'] ?? '')) {
                $errors[] = 'Incorrect current password.';
            } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)(?=.*[\W_]).{10,}$/', $newpass)) {
                // Kapareho ng register.php / reset_password.php
                $errors[] = 'New password needs 10+ characters with a letter, a number and a special character.';
            } elseif ($newpass !== $confirm) {
                $errors[] = 'Passwords do not match.';
            } elseif (password_verify($newpass, $dbUser['password_hash'])) {
                $errors[] = 'New password must be different from your current one.';
            }
        }

        if (!$errors) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($newpass, PASSWORD_DEFAULT), $userId]);
            $_SESSION['sx_flash'] = ['success', 'Password updated.'];
            header('Location: ' . $self . '?tab=security');
            exit;
        }
    }
}

$flash = $_SESSION['sx_flash'] ?? null;
unset($_SESSION['sx_flash']);

// Halaga ng field: tinype ng user (kapag may error) o galing sa DB
$val = fn(string $key) => h($old[$key] ?? ($user[$key] ?? ''));

$avatar = !empty($user['profile_image'])
    ? $user['profile_image'] . '?' . time()
    : '/sweepxpress/assets/profile-icon.jpg';
$roleLabel = ['business' => 'Business account', 'admin' => 'Administrator'][$user['role']] ?? 'Customer';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   SETTINGS (Phase 9d) — tokens + sx-field / sx-pass / sx-rules mula sa assets/style.css
   ============================================================ */
.st { max-width: 1100px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.st-head { margin-bottom: var(--sx-space-5); }
.st-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.st-layout { display: grid; gap: var(--sx-space-5); align-items: start; }

/* Account card + tabs */
.st-side { display: grid; gap: var(--sx-space-4); }
.st-account { display: flex; align-items: center; gap: var(--sx-space-3); }
.st-account img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; background: var(--sx-surface-alt); }
.st-account strong { display: block; color: var(--sx-dark); }
.st-account span { font-size: var(--sx-text-sm); color: var(--sx-muted); overflow-wrap: anywhere; }
.st-nav { display: flex; gap: var(--sx-space-2); }
.st-nav a {
    display: flex;
    flex: 1;
    align-items: center;
    gap: var(--sx-space-2);
    padding: 10px var(--sx-space-4);
    border-radius: var(--sx-radius-md);
    font-weight: var(--sx-weight-semibold);
    color: var(--sx-muted);
    text-decoration: none;
}
.st-nav a:hover { background: var(--sx-surface-alt); color: var(--sx-dark); }
.st-nav a.active { background: var(--sx-primary-soft); color: var(--sx-primary); }
.st-links { display: grid; gap: var(--sx-space-1); padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); }
.st-links a { font-size: var(--sx-text-sm); color: var(--sx-muted); text-decoration: none; }
.st-links a:hover { color: var(--sx-primary); }
.st-links i { width: 20px; }

/* Form card */
.st-card h2 { margin: 0 0 4px; font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.st-card > p { margin: 0 0 var(--sx-space-5); color: var(--sx-muted); font-size: var(--sx-text-sm); }
.st-section { margin: var(--sx-space-5) 0 var(--sx-space-3); padding-top: var(--sx-space-4); border-top: 1px solid var(--sx-border-light); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); letter-spacing: 0.04em; text-transform: uppercase; color: var(--sx-muted); }
.st-grid { display: grid; gap: 0 var(--sx-space-4); }
.st-photo { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-4); margin-bottom: var(--sx-space-4); }
.st-photo img { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; background: var(--sx-surface-alt); box-shadow: 0 0 0 4px var(--sx-surface), 0 0 0 5px var(--sx-border-light); }
.st-photo input { display: none; }
.st-photo .sx-hint { display: block; margin-top: 6px; }
.st-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: var(--sx-space-2); margin-top: var(--sx-space-2); }
.st-readonly { display: flex; align-items: center; gap: var(--sx-space-2); }
.st-readonly .sx-input { background: var(--sx-surface-alt); color: var(--sx-muted); }

@media (min-width: 640px) {
    .st-grid { grid-template-columns: 1fr 1fr; }
    .st-grid .full { grid-column: 1 / -1; }
}
@media (min-width: 992px) {
    .st-layout { grid-template-columns: 280px 1fr; }
    .st-side { position: sticky; top: calc(var(--sx-nav-h, 70px) + var(--sx-space-4)); }
    .st-nav { flex-direction: column; }
}
</style>

<div class="st sx-container">
    <div class="st-head">
        <span class="sx-eyebrow">Account</span>
        <h1 class="sx-heading-lg">Settings</h1>
        <p>Manage your profile, delivery address and password.</p>
    </div>

    <div class="st-layout">
        <aside class="sx-card st-side">
            <div class="st-account">
                <img src="<?= h($avatar) ?>" alt="" id="sideAvatar" onerror="this.src='/sweepxpress/assets/profile-icon.jpg'">
                <div>
                    <strong><?= h($user['name']) ?></strong>
                    <span><?= h($roleLabel) ?> · since <?= date('M Y', strtotime($user['created_at'])) ?></span>
                </div>
            </div>
            <nav class="st-nav" aria-label="Settings sections">
                <a href="?tab=profile" class="<?= $tab === 'profile' ? 'active' : '' ?>"><i class="fa-solid fa-user"></i> Profile</a>
                <a href="?tab=security" class="<?= $tab === 'security' ? 'active' : '' ?>"><i class="fa-solid fa-lock"></i> Password</a>
            </nav>
            <div class="st-links">
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                    <a href="/sweepxpress/admin/dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                    <a href="/sweepxpress/admin/view_logs.php?user_id=<?= (int)$userId ?>"><i class="fa-solid fa-clock-rotate-left"></i> My activity</a>
                <?php else: ?>
                    <a href="/sweepxpress/customers/my_orders.php"><i class="fa-solid fa-receipt"></i> My Orders</a>
                    <a href="/sweepxpress/cart.php"><i class="fa-solid fa-cart-shopping"></i> Cart</a>
                <?php endif; ?>
            </div>
        </aside>

        <div>
            <?php if ($flash): ?>
                <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
                    <i class="fa-solid fa-circle-check"></i><div><?= h($flash[1]) ?></div>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><?= implode('<br>', array_map('h', $errors)) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'profile'): ?>
                <form method="POST" enctype="multipart/form-data" class="sx-card st-card">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="update_profile" value="1">
                    <h2>Profile</h2>
                    <p>This is used on your orders and invoices.</p>

                    <div class="st-photo">
                        <img src="<?= h($avatar) ?>" alt="" id="photoPreview" onerror="this.src='/sweepxpress/assets/profile-icon.jpg'">
                        <div>
                            <label for="profileImgInput" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-camera"></i> Change photo</label>
                            <input type="file" name="profile_image" id="profileImgInput" accept="image/jpeg,image/png">
                            <span class="sx-hint">JPG or PNG, up to 2 MB.</span>
                        </div>
                    </div>

                    <div class="st-grid">
                        <div class="sx-field">
                            <label class="sx-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="sx-input" value="<?= $val('username') ?>" required maxlength="50" autocomplete="username">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="name">Full name</label>
                            <input type="text" id="name" name="name" class="sx-input" value="<?= $val('name') ?>" required maxlength="120" autocomplete="name">
                        </div>
                        <div class="sx-field full">
                            <label class="sx-label" for="email">Email</label>
                            <div class="st-readonly">
                                <input type="email" id="email" class="sx-input" value="<?= h($user['email']) ?>" readonly aria-describedby="emailHint">
                            </div>
                            <span class="sx-hint" id="emailHint">Your email can't be changed.</span>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="phone">Phone <span class="sx-hint">(optional)</span></label>
                            <input type="tel" id="phone" name="phone" class="sx-input" value="<?= $val('phone') ?>"
                                   placeholder="09XXXXXXXXX" maxlength="11" pattern="09\d{9}" inputmode="numeric"
                                   title="11 digits starting with 09" autocomplete="tel">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="birthdateInput">Birthdate <span class="sx-hint">(optional)</span></label>
                            <input type="date" id="birthdateInput" name="birthdate" class="sx-input" value="<?= $val('birthdate') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="gender">Gender <span class="sx-hint">(optional)</span></label>
                            <?php $g = $old['gender'] ?? ($user['gender'] ?? ''); ?>
                            <select id="gender" name="gender" class="sx-select">
                                <option value="">Prefer not to say</option>
                                <?php foreach (['Male', 'Female', 'Other'] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $g === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="st-section">Delivery address</div>
                    <div class="st-grid">
                        <div class="sx-field full">
                            <label class="sx-label" for="streetaddress">Street address</label>
                            <input type="text" id="streetaddress" name="streetaddress" class="sx-input" value="<?= $val('streetaddress') ?>"
                                   maxlength="255" placeholder="House no., street, barangay" autocomplete="street-address">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="city">City</label>
                            <input type="text" id="city" name="city" class="sx-input" value="<?= $val('city') ?>" maxlength="100" autocomplete="address-level2">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="zipcode">Zip code</label>
                            <input type="text" id="zipcode" name="zipcode" class="sx-input" value="<?= $val('zipcode') ?>"
                                   maxlength="4" pattern="\d{4}" inputmode="numeric" title="4 digits" autocomplete="postal-code">
                        </div>
                    </div>

                    <div class="st-actions">
                        <a href="?tab=profile" class="sx-btn sx-btn-ghost">Reset</a>
                        <button type="submit" class="sx-btn sx-btn-primary">Save changes</button>
                    </div>
                </form>

            <?php else: ?>
                <form method="POST" class="sx-card st-card" style="max-width: 560px">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="change_password" value="1">
                    <h2>Password</h2>
                    <p>
                        Use a password you don't use anywhere else.
                        <?php if (!empty($user['google_id'])): ?>
                            Signed up with Google? <a href="/sweepxpress/forgot_password.php">Set a password by email</a>.
                        <?php endif; ?>
                    </p>

                    <div class="sx-field">
                        <label class="sx-label" for="current_password">Current password</label>
                        <div class="sx-pass">
                            <input type="password" id="current_password" name="current_password" class="sx-input" required autocomplete="current-password">
                            <button type="button" data-sx-toggle="current_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="sx-field">
                        <label class="sx-label" for="new_password">New password</label>
                        <div class="sx-pass">
                            <input type="password" id="new_password" name="new_password" class="sx-input" required minlength="10"
                                   autocomplete="new-password" aria-describedby="passRules">
                            <button type="button" data-sx-toggle="new_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                        <ul class="sx-rules" id="passRules">
                            <li data-rule="length"><i class="fa-regular fa-circle"></i> 10+ characters</li>
                            <li data-rule="letter"><i class="fa-regular fa-circle"></i> A letter</li>
                            <li data-rule="number"><i class="fa-regular fa-circle"></i> A number</li>
                            <li data-rule="special"><i class="fa-regular fa-circle"></i> A special character</li>
                        </ul>
                    </div>
                    <div class="sx-field">
                        <label class="sx-label" for="confirm_password">Confirm new password</label>
                        <div class="sx-pass">
                            <input type="password" id="confirm_password" name="confirm_password" class="sx-input" required autocomplete="new-password">
                            <button type="button" data-sx-toggle="confirm_password" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>

                    <div class="st-actions">
                        <button type="submit" class="sx-btn sx-btn-primary">Update password</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Preview ng bagong photo bago i-save
    const photo = document.getElementById('profileImgInput');
    if (photo) {
        photo.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                sxToast('Photo must be 2 MB or smaller', 'error');
                this.value = '';
                return;
            }
            const url = URL.createObjectURL(file);
            document.getElementById('photoPreview').src = url;
            document.getElementById('sideAvatar').src = url;
        });
    }

    sxPasswordToggles();
    sxPasswordRules(document.getElementById('new_password'), document.getElementById('passRules'));

    // Confirm password: browser message kapag hindi tugma
    const np = document.getElementById('new_password');
    const cp = document.getElementById('confirm_password');
    if (np && cp) {
        const check = () => cp.setCustomValidity(cp.value && cp.value !== np.value ? 'Passwords do not match' : '');
        np.addEventListener('input', check);
        cp.addEventListener('input', check);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
