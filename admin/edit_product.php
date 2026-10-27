<?php
// admin/edit_product.php — details, pangunahing larawan at gallery ng isang produkto.
// Ang stock ay nasa inventory (Stock in / Adjust), hindi na dito binabago.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_products.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$errors = [];
$values = null; // tinype ng admin kapag may error sa details

$load = function () use ($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$product = $id > 0 ? $load() : null;

// ==========================================================
// POST actions (PRG: redirect pabalik na may flash message)
// ==========================================================
if ($product && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $done = null;

    if (!sx_csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'update_details') {
        [$values, $errors] = sx_product_input();
        if (!$errors) {
            if ($values['sku'] === '') {
                $values['sku'] = sx_generate_sku($pdo, $values['name']) ?? '';
            } else {
                $dup = $pdo->prepare("SELECT 1 FROM products WHERE sku = ? AND id <> ?");
                $dup->execute([$values['sku'], $id]);
                if ($dup->fetch()) $errors[] = 'That SKU is already used by another product.';
            }
        }
        if (!$errors) {
            try {
                $pdo->prepare("UPDATE products SET name = ?, sku = ?, description = ?, price = ?, category = ? WHERE id = ?")
                    ->execute([$values['name'], $values['sku'], $values['description'], (float)$values['price'], $values['category'], $id]);
                $done = 'Details saved.';
            } catch (PDOException $e) {
                error_log("Product update error: " . $e->getMessage());
                $errors[] = 'Could not save the details. Please try again.';
            }
        }

    } elseif ($action === 'update_primary_image') {
        $img = sx_save_product_image($_FILES['primary_image'] ?? []);
        if (isset($img['error'])) {
            $errors[] = $img['error'];
        } elseif ($img['path'] === null) {
            $errors[] = 'Choose an image first.';
        } else {
            $pdo->prepare("UPDATE products SET image_path = ? WHERE id = ?")->execute([$img['path'], $id]);
            sx_delete_upload($product['image_path']);
            $done = 'Main image updated.';
        }

    } elseif ($action === 'add_image') {
        $files = $_FILES['new_image'] ?? null;
        $added = 0;
        foreach (array_keys($files['name'] ?? []) as $k) {
            $img = sx_save_product_image([
                'name' => $files['name'][$k], 'tmp_name' => $files['tmp_name'][$k],
                'error' => $files['error'][$k], 'size' => $files['size'][$k],
            ]);
            if (isset($img['error'])) {
                $errors[] = $files['name'][$k] . ': ' . $img['error'];
            } elseif ($img['path']) {
                $pdo->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)")->execute([$id, $img['path']]);
                $added++;
            }
        }
        if ($added) $done = $added . ' image' . ($added === 1 ? '' : 's') . ' added to the gallery.';
        elseif (!$errors) $errors[] = 'Choose at least one image.';

    } elseif ($action === 'delete_image') {
        $stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE id = ? AND product_id = ?");
        $stmt->execute([(int)($_POST['image_id'] ?? 0), $id]);
        $path = $stmt->fetchColumn();
        if ($path !== false) {
            $pdo->prepare("DELETE FROM product_images WHERE id = ? AND product_id = ?")->execute([(int)$_POST['image_id'], $id]);
            sx_delete_upload($path);
            $done = 'Image removed from the gallery.';
        } else {
            $errors[] = 'Image not found.';
        }
    }

    if ($done) {
        // Kapag may bahagyang error sa gallery upload, ipakita pa rin pagkatapos ng redirect
        sx_flash($errors ? ['warning', $done . ' ' . implode(' ', $errors)] : ['success', $done]);
        header("Location: edit_product.php?id={$id}");
        exit;
    }
}

if ($product) {
    $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = $pdo->prepare("
        SELECT
            COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id = ?), 0) AS stock,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.product_id = ? AND o.status <> 'cancelled'), 0) AS sold,
            (SELECT MAX(created_at) FROM stock_movements WHERE product_id = ?) AS last_move
    ");
    $stats->execute([$id, $id, $id]);
    $stats = $stats->fetch(PDO::FETCH_ASSOC);
}

$values     = $values ?? ($product ? array_intersect_key($product, array_flip(['name', 'sku', 'category', 'price', 'description'])) : []);
$categories = sx_product_categories($pdo);
$flash      = sx_flash();
$csrf       = sx_csrf_token();
$placeholder = '/sweepxpress/assets/placeholder.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="pf sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/admin/products.php">Products</a> <span aria-hidden="true">/</span>
        <span><?= $product ? h($product['name']) : 'Not found' ?></span>
    </nav>

<?php if (!$product): ?>
    <div class="sx-card sx-empty">
        <i class="fa-solid fa-box-open"></i>
        <h3>Product not found</h3>
        <p>It may have been deleted.</p>
        <a href="/sweepxpress/admin/products.php" class="sx-btn sx-btn-primary sx-btn-sm" style="margin-top: var(--sx-space-3)">Back to products</a>
    </div>
<?php else:
    $stock = (int)$stats['stock'];
    $stockCls = $stock === 0 ? 'out' : ($stock <= 10 ? 'low' : 'in');
?>
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg"><?= h($product['name']) ?></h1>
            <p><?= h($product['sku'] ?: 'No SKU') ?> · #<?= (int)$product['id'] ?></p>
        </div>
        <a href="/sweepxpress/product.php?id=<?= (int)$product['id'] ?>" target="_blank" class="sx-btn sx-btn-outline sx-btn-sm">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> View in store
        </a>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-check"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('h', $errors)) ?></div>
        </div>
    <?php endif; ?>

    <div class="pf-layout">
        <div class="pf-main">
            <!-- Details -->
            <form method="post" class="sx-card">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update_details">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <h2 class="pf-title">Details</h2>
                <?php sx_product_fields($values, $categories); ?>
                <div class="pf-actions" style="justify-content: flex-end">
                    <button type="submit" class="sx-btn sx-btn-primary">Save details</button>
                </div>
            </form>

            <!-- Gallery -->
            <section class="sx-card">
                <h2 class="pf-title">Gallery <span class="sx-hint">(<?= count($images) ?>)</span></h2>
                <div class="pf-gallery">
                    <?php foreach ($images as $img): ?>
                        <figure>
                            <img src="<?= h($img['image_path']) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                            <form method="post" onsubmit="return confirm('Remove this image from the gallery?')">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="action" value="delete_image">
                                <input type="hidden" name="id" value="<?= (int)$id ?>">
                                <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
                                <button type="submit" aria-label="Remove image"><i class="fa-solid fa-xmark"></i></button>
                            </form>
                        </figure>
                    <?php endforeach; ?>
                    <form method="post" enctype="multipart/form-data" class="pf-gallery-add">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="add_image">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <label>
                            <i class="fa-solid fa-plus"></i><span>Add images</span>
                            <input type="file" name="new_image[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple class="pf-file" onchange="this.form.submit()">
                        </label>
                    </form>
                </div>
                <p class="sx-hint" style="margin: var(--sx-space-3) 0 0">Shown on the product page after the main image. Max 2 MB each.</p>
            </section>
        </div>

        <aside class="pf-side">
            <!-- Main image -->
            <form method="post" enctype="multipart/form-data" class="sx-card">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update_primary_image">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <h2 class="pf-title">Main image</h2>
                <label class="pf-drop has-img" for="pf-image">
                    <img id="pf-preview" src="<?= h($product['image_path'] ?: $placeholder) ?>" alt="" onerror="this.src='<?= $placeholder ?>'">
                </label>
                <input type="file" id="pf-image" name="primary_image" accept="image/jpeg,image/png,image/gif,image/webp" class="pf-file">
                <div class="pf-actions">
                    <label for="pf-image" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-image"></i> Choose</label>
                    <button type="submit" class="sx-btn sx-btn-primary sx-btn-sm" id="pf-image-save" disabled>Replace</button>
                </div>
            </form>

            <!-- Stock (galing sa inventory) -->
            <section class="sx-card">
                <h2 class="pf-title">Stock</h2>
                <div class="pf-stock">
                    <span class="ap-stock <?= $stockCls ?>"><?= $stock === 0 ? 'Out of stock' : number_format($stock) . ' on hand' ?></span>
                    <span class="sx-hint"><?= number_format((int)$stats['sold']) ?> sold<?= $stats['last_move'] ? ' · last movement ' . date('M j, Y', strtotime($stats['last_move'])) : '' ?></span>
                </div>
                <div class="pf-stock-links">
                    <a href="/sweepxpress/admin/stock_in.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-truck-ramp-box"></i> Stock in</a>
                    <a href="/sweepxpress/admin/stock_adjust.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-sliders"></i> Adjust</a>
                    <a href="/sweepxpress/admin/product_history.php?product_id=<?= (int)$id ?>" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
                </div>
            </section>
        </aside>
    </div>
<?php endif; ?>
</div>

<script>
// Preview + paganahin ang "Replace" kapag may napiling larawan
const pfImage = document.getElementById('pf-image');
if (pfImage) {
    pfImage.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) { sxToast('Image must be 2 MB or smaller', 'error'); this.value = ''; return; }
        document.getElementById('pf-preview').src = URL.createObjectURL(file);
        document.getElementById('pf-image-save').disabled = false;
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
