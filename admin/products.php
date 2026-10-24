<?php 
ob_start();
session_start();
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/../includes/auth.php';

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_logged_in() || $_SESSION['user']['role'] !== 'admin') {
    header("Location: /sweepxpress/login.php");
    exit();
}

$message = '';
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// HANDLE DELETE
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $page_redirect = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $deleted_name = 'Product';

    if ($id > 0) {
        try {
            $pdo->beginTransaction();
            $stmt_order = $pdo->prepare("DELETE FROM order_items WHERE product_id = ?");
            $stmt_order->execute([$id]);

            $stmt = $pdo->prepare("SELECT name, image_path FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            $deleted_name = $product['name'] ?? 'Product';

            if ($product && !empty($product['image_path'])) {
                $relativePath = str_replace('/sweepxpress', '', $product['image_path']); 
                $imageFilePath = __DIR__ . '/..' . $relativePath; 
                if (file_exists($imageFilePath) && is_file($imageFilePath)) {
                    @unlink($imageFilePath); 
                }
            }

            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $pdo->commit();

            header('Location: products.php?page=' . $page_redirect . '&success=Product_Deleted&name=' . urlencode($deleted_name));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['alert_message'] = '<div class="alert alert-danger">❌ Database error: ' . h($e->getMessage()) . '</div>';
        }
    }
    header('Location: products.php?page=' . $page_redirect);
    exit();
}

// HANDLE CREATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sku = trim($_POST['sku'] ?? ''); 
    $name = trim($_POST['name'] ?? '');
    $price = filter_var($_POST['price'] ?? 0, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
    $desc = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $errors = [];

    if ($name === '' || $price === false || $desc === '' || $category === '') {
        $errors[] = "All fields are required.";
    }

    if (empty($errors)) {
        if ($sku === '') {
            $words = explode(' ', $name);
            $initials = '';
            foreach ($words as $word) {
                if (!empty($word)) {
                    $initials .= strtoupper($word[0]);
                }
            }
            $prefix = substr($initials, 0, 4); 
            $length = 4;
            $digits = '0123456789';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
            $attempts = 0;
            $sku_is_unique = false;
            while (!$sku_is_unique && $attempts < 10) {
                $random_part = '';
                for ($i = 0; $i < $length; $i++) {
                    $random_part .= $digits[random_int(0, strlen($digits) - 1)];
                }
                $sku = $prefix . '-' . $random_part;
                $stmt->execute([$sku]);
                if ($stmt->fetchColumn() == 0) {
                    $sku_is_unique = true;
                }
                $attempts++;
            }
            if (!$sku_is_unique) {
                $errors[] = 'Failed to generate unique SKU.';
            }
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
            $stmt->execute([$sku]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'SKU already exists.';
            }
        }
    }

    if (empty($errors)) { 
        $imgPath = null;
        if (!empty($_FILES['image']['name'])) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedType = finfo_file($fileInfo, $_FILES['image']['tmp_name']);
            finfo_close($fileInfo);

            if (in_array($detectedType, $allowedTypes) && $_FILES['image']['size'] < 2000000) {
                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($_FILES['image']['name']));
                $uploadDir = realpath(__DIR__ . '/../uploads/');
                if ($uploadDir !== false) {
                    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                        $imgPath = '/sweepxpress/uploads/' . $filename;
                    } else {
                        $errors[] = 'Error uploading image.';
                    }
                } else {
                    $errors[] = 'Upload directory not accessible.';
                }
            } else {
                $errors[] = 'Invalid file type or size.';
            }
        }

        if ($action === 'create' && empty($errors)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, description, price, category, image_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $desc, $price, $category, $imgPath]);
                header('Location: products.php?success=Product_Added&name=' . urlencode($name));
                exit();
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . h($e->getMessage());
            }
        }
    }

    if (!empty($errors)) {
        $_SESSION['form_data'] = $_POST; 
        $_SESSION['alert_message'] = '<div class="alert-modern alert-error"><i class="fas fa-exclamation-circle"></i> ' . implode('<br>', $errors) . '</div>';
    }
}

$totalResults = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalPages = ceil($totalResults / $limit);

if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$stmt = $pdo->prepare("SELECT * FROM products ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

require_once __DIR__ . '/../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

:root {
    --primary: #3b82f6;
    --primary-dark: #2563eb;
    --success: #10b981;
    --danger: #ef4444;
    --warning: #f59e0b;
    
    --bg-page: #f8f9fb;
    --bg-card: #ffffff;
    --bg-soft: #f9fafb;
    
    --text-primary: #0f172a;
    --text-secondary: #64748b;
    --text-tertiary: #94a3b8;
    
    --border-main: #e5e7eb;
    
    --radius-xl: 20px;
    --radius-lg: 16px;
    --radius-md: 12px;
    --radius-sm: 8px;
    
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
    --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
    --shadow-lg: 0 10px 32px rgba(0,0,0,0.12);
    
    --transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--bg-page);
    color: var(--text-primary);
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

.container-clean {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.header-clean {
    margin-bottom: 40px;
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-clean {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.02em;
}

.stats-row {
    display: flex;
    gap: 24px;
    padding: 20px;
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-main);
    box-shadow: var(--shadow-sm);
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--primary);
    line-height: 1;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
    margin-top: 6px;
}

/* ALERTS */
.alert-modern {
    padding: 16px 20px;
    border-radius: var(--radius-md);
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.95rem;
}

.alert-error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
}

/* CARD */
.card-clean {
    background: var(--bg-card);
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-main);
    box-shadow: var(--shadow-md);
    margin-bottom: 32px;
}

.card-header-clean {
    padding: 24px 28px;
    border-bottom: 1px solid var(--border-main);
}

.card-title-clean {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 4px;
}

.card-title-clean i {
    color: var(--primary);
}

.card-subtitle-clean {
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.card-body-clean {
    padding: 28px;
}

/* FORM */
.form-group-clean {
    margin-bottom: 20px;
}

.label-clean {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.label-required::after {
    content: '*';
    color: var(--danger);
    margin-left: 4px;
}

.input-clean,
.select-clean,
.textarea-clean {
    width: 100%;
    padding: 12px 14px;
    font-size: 0.95rem;
    color: var(--text-primary);
    background: var(--bg-card);
    border: 1px solid var(--border-main);
    border-radius: var(--radius-md);
    transition: all var(--transition);
    font-family: inherit;
}

.input-clean:focus,
.select-clean:focus,
.textarea-clean:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.hint-clean {
    font-size: 0.8rem;
    color: var(--text-tertiary);
    margin-top: 6px;
}

/* BUTTONS */
.btn-clean {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    font-size: 0.95rem;
    font-weight: 600;
    border-radius: var(--radius-md);
    border: none;
    cursor: pointer;
    transition: all var(--transition);
    text-decoration: none;
}

.btn-primary {
    background: var(--primary);
    color: #ffffff;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    color: #ffffff;
}

.btn-success {
    background: var(--success);
    color: #ffffff;
}

.btn-success:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    color: #ffffff;
}

.btn-sm {
    padding: 6px 14px;
    font-size: 0.85rem;
    border-radius: var(--radius-sm);
}

.btn-outline {
    background: transparent;
    border: 1.5px solid;
}

.btn-outline.edit {
    border-color: var(--primary);
    color: var(--primary);
}

.btn-outline.edit:hover {
    background: var(--primary);
    color: #ffffff;
}

.btn-outline.delete {
    border-color: var(--danger);
    color: var(--danger);
}

.btn-outline.delete:hover {
    background: var(--danger);
    color: #ffffff;
}

/* TABLE - DESKTOP VIEW */
.table-wrapper-clean {
    background: var(--bg-card);
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-main);
    box-shadow: var(--shadow-md);
    overflow: hidden;
}

.table-clean {
    width: 100%;
    border-collapse: collapse;
}

.table-clean thead {
    background: var(--bg-soft);
}

.table-clean thead th {
    padding: 16px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-secondary);
    border-bottom: 1px solid var(--border-main);
}

.table-clean tbody td {
    padding: 16px 20px;
    font-size: 0.95rem;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-main);
    vertical-align: middle;
}

.table-clean tbody tr:hover {
    background: var(--bg-soft);
}

.table-clean tbody tr:last-child td {
    border-bottom: none;
}

.product-img {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: var(--radius-md);
    border: 1px solid var(--border-main);
}

.badge-clean {
    display: inline-flex;
    padding: 4px 12px;
    font-size: 0.8rem;
    font-weight: 600;
    border-radius: 999px;
}

.badge-sku {
    background: #eff6ff;
    color: #1e40af;
}

.badge-price {
    background: #d1fae5;
    color: #065f46;
}

/* ACTIONS LAYOUT - DESKTOP */
.product-actions-desktop {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 12px; /* space between Edit and Delete */
}

/* PAGINATION */
.pagination-clean {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 32px;
    list-style: none;
}

.pagination-clean li a,
.pagination-clean li span {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
    background: var(--bg-card);
    border: 1px solid var(--border-main);
    border-radius: var(--radius-md);
    text-decoration: none;
    transition: all var(--transition);
}

.pagination-clean li a:hover {
    background: var(--primary);
    color: #ffffff;
    border-color: var(--primary);
}

.pagination-clean li.active a {
    background: var(--primary);
    color: #ffffff;
    border-color: var(--primary);
}

.pagination-clean li.disabled span {
    color: var(--text-tertiary);
    cursor: not-allowed;
    opacity: 0.5;
}

.empty-state {
    text-align: center;
    padding: 80px 32px;
    background: var(--bg-card);
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-main);
}

.empty-state i {
    font-size: 4rem;
    color: var(--text-tertiary);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 8px;
}

.empty-state p {
    font-size: 0.95rem;
    color: var(--text-tertiary);
}

/* MOBILE CARD VIEW */
.product-card-mobile-list {
    display: none; /* hidden on desktop */
}

.product-card {
    border-radius: var(--radius-xl);
    border: 1px solid var(--border-main);
    box-shadow: var(--shadow-md);
    background: var(--bg-card);
    padding: 16px;
    margin-bottom: 16px;
}

.product-card-main {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.product-card-image .product-img {
    width: 72px;
    height: 72px;
    border-radius: var(--radius-md);
}

.product-card-info {
    flex: 1;
}

.product-card-title {
    font-size: 1rem;
    margin-bottom: 4px;
}

.product-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 4px;
}

.product-card-category,
.product-card-id {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.product-card-actions {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 10px;
}

.product-card-actions .btn-clean {
    flex: 1;
    justify-content: center;
}

/* RESPONSIVE SWITCH */
@media (max-width: 768px) {
    .container-clean {
        padding: 24px 16px 40px;
    }
    
    .header-top {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .stats-row {
        flex-direction: column;
        gap: 16px;
    }
    
    .card-body-clean {
        padding: 20px;
    }

    /* hide desktop table, show cards */
    .table-wrapper-clean {
        display: none;
    }

    .product-card-mobile-list {
        display: block;
    }
}
</style>

<?php 
if (isset($_SESSION['alert_message'])) {
    echo $_SESSION['alert_message'];
    unset($_SESSION['alert_message']); 
}

if (isset($_GET['success'])) {
    $action = h($_GET['success']);
    $product_name = h(urldecode($_GET['name'] ?? 'Product'));
    $title = '';
    $text = '';

    switch($action) {
        case 'Product_Added':
            $title = 'Success!';
            $text = "Product <strong>{$product_name}</strong> has been added to your inventory.";
            break;
        case 'Product_Deleted':
            $title = 'Deleted!';
            $text = "Product <strong>{$product_name}</strong> was removed from your inventory.";
            break;
        default:
            $title = 'Success!';
            $text = 'Operation completed successfully.';
    }

    echo "<script>
    document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({
            icon: 'success',
            title: '{$title}',
            html: '{$text}',
            confirmButtonColor: '#3b82f6',
            confirmButtonText: 'Got it'
        }).then(() => {
            if (history.replaceState) {
                history.replaceState(null, null, window.location.pathname + window.location.search.replace(/[\\?&]success=[^&]*(&name=[^&]*)?/, ''));
            }
        });
    });
    </script>";
}
?>

<div class="container-clean">
    <!-- HEADER -->
    <div class="header-clean">
        <div class="header-top">
            <h1 class="page-title-clean">Product Inventory</h1>
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $totalResults; ?></div>
                    <div class="stat-label">Products</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $totalPages; ?></div>
                    <div class="stat-label">Pages</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $page; ?></div>
                    <div class="stat-label">Current</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD PRODUCT FORM -->
    <div class="card-clean">
        <div class="card-header-clean">
            <div class="card-title-clean">
                <i class="fas fa-plus-circle"></i>
                Add New Product
            </div>
            <div class="card-subtitle-clean">Create a new product entry in your inventory</div>
        </div>
        <div class="card-body-clean">
            <form id="addProductForm" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create">

                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="form-group-clean">
                            <label class="label-clean">SKU (Stock Keeping Unit)</label>
                            <input type="text" name="sku" class="input-clean" placeholder="Auto-generated if left blank" value="<?php echo h($formData['sku'] ?? ''); ?>">
                            <div class="hint-clean">Leave blank for automatic generation</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group-clean">
                            <label class="label-clean label-required">Product Name</label>
                            <input type="text" name="name" class="input-clean" required value="<?php echo h($formData['name'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group-clean">
                            <label class="label-clean label-required">Price (PHP)</label>
                            <input type="number" name="price" class="input-clean" step="0.01" min="0" required value="<?php echo h($formData['price'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group-clean">
                            <label class="label-clean label-required">Category</label>
                            <select name="category" class="select-clean" required>
                                <option value="">-- Select Category --</option>
                                <option value="Cleaning Agents" <?php echo ($formData['category'] ?? '') === 'Cleaning Agents' ? 'selected' : ''; ?>>Cleaning Agents</option>
                                <option value="Cleaning Tools" <?php echo ($formData['category'] ?? '') === 'Cleaning Tools' ? 'selected' : ''; ?>>Cleaning Tools</option>
                                <option value="Adhesives & Tapes" <?php echo ($formData['category'] ?? '') === 'Adhesives & Tapes' ? 'selected' : ''; ?>>Adhesives & Tapes</option>
                                <option value="Floor & Surface Care" <?php echo ($formData['category'] ?? '') === 'Floor & Surface Care' ? 'selected' : ''; ?>>Floor & Surface Care</option>
                                <option value="Equipment" <?php echo ($formData['category'] ?? '') === 'Equipment' ? 'selected' : ''; ?>>Equipment</option>
                            </select>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-group-clean">
                            <label class="label-clean label-required">Description</label>
                            <textarea name="description" class="textarea-clean" rows="4" required><?php echo h($formData['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="form-group-clean">
                            <label class="label-clean">Product Image</label>
                            <input type="file" name="image" class="input-clean" accept="image/*">
                            <div class="hint-clean">Max 2MB. Accepted: JPG, PNG, GIF</div>
                        </div>
                    </div>
                </div>

                <button type="button" onclick="confirmAddProduct()" class="btn-clean btn-success mt-4">
                    <i class="fas fa-plus"></i>
                    Add Product
                </button>
            </form>
        </div>
    </div>

    <!-- PRODUCTS LIST -->
    <?php if ($totalResults > 0): ?>

    <!-- DESKTOP TABLE -->
    <div class="table-wrapper-clean">
        <table class="table-clean">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>SKU</th>
                    <th>Image</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th style="text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $product): ?>
                <tr>
                    <td><strong>#<?php echo h((string)$product['id']); ?></strong></td>
                    <td><span class="badge-clean badge-sku"><?php echo h($product['sku'] ?? 'N/A'); ?></span></td>
                    <td>
                        <?php if ($product['image_path']): ?>
                            <img src="<?php echo h($product['image_path']); ?>" alt="<?php echo h($product['name']); ?>" class="product-img">
                        <?php else: ?>
                            <div class="product-img" style="display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#9ca3af;font-size:0.7rem;font-weight:600;">
                                NO IMAGE
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo h($product['name']); ?></strong></td>
                    <td><?php echo h($product['category'] ?? '—'); ?></td>
                    <td><span class="badge-clean badge-price">₱<?php echo h(number_format($product['price'], 2)); ?></span></td>
                    <td style="text-align: center;">
                        <div class="product-actions-desktop">
                            <a href="edit_product.php?id=<?php echo h((string)$product['id']); ?>" class="btn-clean btn-outline edit btn-sm">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="?delete=<?php echo h((string)$product['id']); ?>&page=<?php echo $page; ?>" class="btn-clean btn-outline delete btn-sm" onclick="return confirm('Are you sure you want to delete <?php echo addslashes($product['name']); ?>?')">
                                <i class="fas fa-trash-alt"></i> Delete
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- MOBILE CARDS -->
    <div class="product-card-mobile-list">
        <?php foreach ($items as $product): ?>
        <div class="product-card">
            <div class="product-card-main">
                <div class="product-card-image">
                    <?php if ($product['image_path']): ?>
                        <img src="<?php echo h($product['image_path']); ?>" alt="<?php echo h($product['name']); ?>" class="product-img">
                    <?php else: ?>
                        <div class="product-img" style="display:flex;align-items:center;justify-content:center;background:#f3f4f6;color:#9ca3af;font-size:0.7rem;font-weight:600;">
                            NO IMAGE
                        </div>
                    <?php endif; ?>
                </div>
                <div class="product-card-info">
                    <div class="product-card-title">
                        <strong><?php echo h($product['name']); ?></strong>
                    </div>
                    <div class="product-card-meta">
                        <span class="badge-clean badge-sku"><?php echo h($product['sku'] ?? 'N/A'); ?></span>
                        <span class="badge-clean badge-price">₱<?php echo h(number_format($product['price'], 2)); ?></span>
                    </div>
                    <div class="product-card-category">
                        <?php echo h($product['category'] ?? '—'); ?>
                    </div>
                    <div class="product-card-id">
                        #<?php echo h((string)$product['id']); ?>
                    </div>
                </div>
            </div>
            <div class="product-card-actions">
                <a href="edit_product.php?id=<?php echo h((string)$product['id']); ?>" class="btn-clean btn-outline edit btn-sm">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <a href="?delete=<?php echo h((string)$product['id']); ?>&page=<?php echo $page; ?>" class="btn-clean btn-outline delete btn-sm" onclick="return confirm('Are you sure you want to delete <?php echo addslashes($product['name']); ?>?')">
                    <i class="fas fa-trash-alt"></i> Delete
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- PAGINATION -->
    <ul class="pagination-clean">
        <li class="<?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
            <?php else: ?>
                <span><i class="fas fa-chevron-left"></i> Previous</span>
            <?php endif; ?>
        </li>
        
        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);

        if ($endPage - $startPage < 4) {
            if ($startPage > 1) {
                $startPage = max(1, $endPage - 4);
            } else {
                $endPage = min($totalPages, $startPage + 4);
            }
        }

        if ($startPage > 1): ?>
            <li><a href="?page=1">1</a></li>
            <?php if ($startPage > 2): ?>
                <li class="disabled"><span>...</span></li>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?>
                <li class="disabled"><span>...</span></li>
            <?php endif; ?>
            <li><a href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
        <?php endif; ?>

        <li class="<?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span>Next <i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </li>
    </ul>
    
    <?php else: ?>
    <div class="empty-state">
        <i class="fas fa-box-open"></i>
        <h3>No Products Found</h3>
        <p>Start by adding your first product above</p>
    </div>
    <?php endif; ?>
</div>

<script>
function confirmAddProduct() {
    const form = document.getElementById('addProductForm');
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const productName = form.elements['name'].value;

    Swal.fire({
        title: 'Confirm Addition',
        html: `Add <strong>${productName}</strong> to inventory?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Add Product',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}
</script>

<?php 
require_once __DIR__ . '/../includes/footer.php'; 
ob_end_flush();
?>
