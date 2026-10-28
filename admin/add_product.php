<?php
// admin/add_product.php — bagong produkto (iisang add page; tinanggal ang dobleng form sa products.php)
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_products.php';

$values = ['name' => '', 'sku' => '', 'category' => '', 'price' => '', 'description' => '',
           'supplier_id' => '', 'cost_price' => '', 'reorder_point' => '10', 'reorder_qty' => ''];
$errors = [];

// Processing bago ang header (dati: pagkatapos, kaya hindi gumagana ang redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$values, $errors] = sx_product_input($pdo);
    if (!sx_csrf_ok()) $errors[] = 'Your session expired. Please try again.';

    if (!$errors) {
        if ($values['sku'] === '') {
            $values['sku'] = sx_generate_sku($pdo, $values['name']) ?? '';
            if ($values['sku'] === '') $errors[] = 'Could not generate a SKU. Please enter one.';
        } else {
            $dup = $pdo->prepare("SELECT 1 FROM products WHERE sku = ?");
            $dup->execute([$values['sku']]);
            if ($dup->fetch()) $errors[] = 'That SKU is already used by another product.';
        }
    }

    $image = ['path' => null];
    if (!$errors) {
        $image = sx_save_product_image($_FILES['image'] ?? []);
        if (isset($image['error'])) $errors[] = $image['error'];
    }

    if (!$errors) {
        try {
            $pdo->prepare("
                INSERT INTO products (sku, name, description, price, category, image_path, stock, supplier_id, cost_price, reorder_point, reorder_qty)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)
            ")->execute([$values['sku'], $values['name'], $values['description'], (float)$values['price'], $values['category'], $image['path'],
                         ...sx_product_supply_params($values)]);
            $newId = (int)$pdo->lastInsertId();
            sx_flash(['success', "“{$values['name']}” was added. Add stock so customers can order it."]);
            header("Location: edit_product.php?id={$newId}");
            exit;
        } catch (PDOException $e) {
            error_log("Product insertion error: " . $e->getMessage());
            sx_delete_upload($image['path']);
            $errors[] = 'Could not save the product. Please try again.';
        }
    }
}

$categories = sx_product_categories($pdo);
$suppliers  = sx_product_suppliers($pdo);
$csrf = sx_csrf_token();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="pf sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/admin/products.php">Products</a> <span aria-hidden="true">/</span> <span>New product</span>
    </nav>
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Add product</h1>
            <p>New products start with 0 stock. Use Stock in to receive inventory.</p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('h', $errors)) ?></div>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="pf-layout">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <section class="sx-card">
            <h2 class="pf-title">Details</h2>
            <?php sx_product_fields($values, $categories, $suppliers); ?>
        </section>

        <aside class="pf-side">
            <section class="sx-card">
                <h2 class="pf-title">Image</h2>
                <label class="pf-drop" for="pf-image">
                    <img id="pf-preview" alt="" hidden>
                    <span id="pf-drop-text"><i class="fa-solid fa-image"></i> Choose an image<br><small>JPG, PNG, GIF or WebP · max 2 MB</small></span>
                </label>
                <input type="file" id="pf-image" name="image" accept="image/jpeg,image/png,image/gif,image/webp" class="pf-file">
            </section>
            <div class="pf-actions">
                <a href="/sweepxpress/admin/products.php" class="sx-btn sx-btn-ghost">Cancel</a>
                <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid fa-plus"></i> Add product</button>
            </div>
        </aside>
    </form>
</div>

<script>
// Preview ng larawan bago i-upload
document.getElementById('pf-image').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { sxToast('Image must be 2 MB or smaller', 'error'); this.value = ''; return; }
    const img = document.getElementById('pf-preview');
    img.src = URL.createObjectURL(file);
    img.hidden = false;
    document.getElementById('pf-drop-text').hidden = true;
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
