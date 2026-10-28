<?php
// includes/admin_products.php — shared helpers para sa admin/products.php, add_product.php at edit_product.php

if (!function_exists('sx_product_categories')) {
    // Mga category na nasa DB + mga default (dati: hardcoded na listahang hindi tugma sa DB)
    function sx_product_categories(PDO $pdo): array
    {
        $defaults = ['Cleaning Supplies', 'Equipment', 'Floor & Surface Care', 'Floor Mats', 'Tapes & Adhesives', 'Tools & Accessories'];
        $fromDb = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $all = array_unique(array_merge($defaults, $fromDb));
        sort($all, SORT_NATURAL | SORT_FLAG_CASE);
        return $all;
    }

    // SKU mula sa initials ng pangalan + 4 na digit, hal. "3M Duct Tapes" → "3DT-4821"
    function sx_generate_sku(PDO $pdo, string $name): ?string
    {
        $initials = '';
        foreach (preg_split('/\s+/', trim($name)) as $word) {
            if ($word !== '') $initials .= strtoupper($word[0]);
        }
        $prefix = substr(preg_replace('/[^A-Z0-9]/', '', $initials), 0, 4) ?: 'SKU';
        $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
        for ($i = 0; $i < 10; $i++) {
            $sku = $prefix . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $check->execute([$sku]);
            if (!$check->fetchColumn()) return $sku;
        }
        return null;
    }

    // Isang na-upload na larawan → /sweepxpress/uploads/...
    // Totoong image check (getimagesize) at extension mula sa uri, hindi sa pangalan ng file
    // (dati: puwedeng ".php" ang extension basta image ang MIME).
    function sx_save_product_image(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['path' => null];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
            return ['error' => 'Image must be 2 MB or smaller.'];
        }
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset($types[$info[2]])) {
            return ['error' => 'Image must be a JPG, PNG, GIF or WebP file.'];
        }
        $dir = dirname(__DIR__) . '/uploads/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return ['error' => 'Upload folder is not available.'];
        }
        $name = time() . '_' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
            return ['error' => 'Could not save the image. Please try again.'];
        }
        return ['path' => '/sweepxpress/uploads/' . $name];
    }

    // Burahin ang file ng isang upload — sa loob lang ng /uploads
    function sx_delete_upload(?string $webPath): void
    {
        if (!$webPath || !str_starts_with($webPath, '/sweepxpress/uploads/')) return;
        $file = realpath(dirname(__DIR__) . '/' . substr($webPath, strlen('/sweepxpress/')));
        $root = realpath(dirname(__DIR__) . '/uploads');
        if ($file && $root && str_starts_with($file, $root) && is_file($file)) @unlink($file);
    }

    // Isang beses na mensahe pagkatapos ng redirect
    function sx_flash(?array $set = null): ?array
    {
        if ($set !== null) {
            $_SESSION['sx_flash'] = $set;
            return null;
        }
        $msg = $_SESSION['sx_flash'] ?? null;
        unset($_SESSION['sx_flash']);
        return $msg;
    }

    // Basahin at i-validate ang details form (add at edit) → [values, errors]
    function sx_product_input(PDO $pdo): array
    {
        $v = [
            'name'          => trim($_POST['name'] ?? ''),
            'sku'           => strtoupper(trim($_POST['sku'] ?? '')),
            'category'      => trim($_POST['category'] ?? ''),
            'price'         => trim($_POST['price'] ?? ''),
            'description'   => trim($_POST['description'] ?? ''),
            'supplier_id'   => trim($_POST['supplier_id'] ?? ''),
            'cost_price'    => trim($_POST['cost_price'] ?? ''),
            'reorder_point' => trim($_POST['reorder_point'] ?? '10'),
            'reorder_qty'   => trim($_POST['reorder_qty'] ?? ''),
        ];
        $errors = [];
        if ($v['name'] === '') $errors[] = 'Name is required.';
        if (mb_strlen($v['name']) > 255) $errors[] = 'Name is too long.';
        if ($v['sku'] !== '' && !preg_match('/^[A-Z0-9-]{2,20}$/', $v['sku'])) $errors[] = 'SKU can only use letters, numbers and dashes (max 20).';
        if ($v['category'] === '') $errors[] = 'Category is required.';
        if (!is_numeric($v['price']) || (float)$v['price'] < 0) $errors[] = 'Price must be 0 or more.';
        if ($v['description'] === '') $errors[] = 'Description is required.';

        // Supply (Phase 7c)
        if ($v['supplier_id'] !== '') {
            $s = $pdo->prepare("SELECT 1 FROM suppliers WHERE id = ?");
            $s->execute([(int)$v['supplier_id']]);
            if (!ctype_digit($v['supplier_id']) || !$s->fetchColumn()) $errors[] = 'Choose a supplier from the list.';
        }
        if ($v['cost_price'] !== '' && (!is_numeric($v['cost_price']) || (float)$v['cost_price'] < 0 || (float)$v['cost_price'] > 10000000)) {
            $errors[] = 'Cost price must be 0 or more.';
        }
        if (!ctype_digit($v['reorder_point']) || (int)$v['reorder_point'] > 100000) $errors[] = 'Reorder point must be a whole number from 0.';
        if ($v['reorder_qty'] !== '' && (!ctype_digit($v['reorder_qty']) || (int)$v['reorder_qty'] < 1 || (int)$v['reorder_qty'] > 100000)) {
            $errors[] = 'Reorder quantity must be a whole number from 1.';
        }
        return [$v, $errors];
    }

    // supplier_id, cost_price, reorder_point, reorder_qty para sa INSERT/UPDATE (blangko → NULL)
    function sx_product_supply_params(array $v): array
    {
        return [
            $v['supplier_id'] === '' ? null : (int)$v['supplier_id'],
            $v['cost_price'] === '' ? null : round((float)$v['cost_price'], 2),
            (int)$v['reorder_point'],
            $v['reorder_qty'] === '' ? null : (int)$v['reorder_qty'],
        ];
    }

    // Mga supplier para sa dropdown: aktibo, at ang kasalukuyan kahit inactive na
    function sx_product_suppliers(PDO $pdo, $currentId = null): array
    {
        $s = $pdo->prepare("SELECT id, name, is_active FROM suppliers WHERE is_active = 1 OR id = ? ORDER BY name");
        $s->execute([(int)$currentId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    // Mga field ng product details (iisa para sa add at edit)
    function sx_product_fields(array $v, array $categories, array $suppliers = []): void
    {
        $e = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="pf-grid">
            <div class="sx-field pf-full">
                <label class="sx-label" for="pf-name">Product name</label>
                <input type="text" id="pf-name" name="name" class="sx-input" required maxlength="255" value="<?= $e($v['name'] ?? '') ?>">
            </div>
            <div class="sx-field">
                <label class="sx-label" for="pf-sku">SKU <span class="sx-hint">(optional)</span></label>
                <input type="text" id="pf-sku" name="sku" class="sx-input" maxlength="20" pattern="[A-Za-z0-9-]{2,20}" placeholder="Auto-generated" value="<?= $e($v['sku'] ?? '') ?>">
            </div>
            <div class="sx-field">
                <label class="sx-label" for="pf-category">Category</label>
                <input type="text" id="pf-category" name="category" class="sx-input" required list="pf-categories" maxlength="100"
                       placeholder="Choose or type a new one" value="<?= $e($v['category'] ?? '') ?>">
                <datalist id="pf-categories">
                    <?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="pf-price">Price (₱)</label>
                <input type="number" id="pf-price" name="price" class="sx-input" required min="0" step="0.01" inputmode="decimal" value="<?= $e($v['price'] ?? '') ?>">
            </div>
            <div class="sx-field pf-full">
                <label class="sx-label" for="pf-desc">Description</label>
                <textarea id="pf-desc" name="description" class="sx-textarea" rows="4" required><?= $e($v['description'] ?? '') ?></textarea>
            </div>
        </div>

        <h3 class="pf-subtitle">Supply &amp; reorder</h3>
        <div class="pf-grid">
            <div class="sx-field pf-full">
                <label class="sx-label" for="pf-supplier">Supplier <span class="sx-hint">(optional)</span></label>
                <select id="pf-supplier" name="supplier_id" class="sx-select">
                    <option value="">No supplier</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (string)($v['supplier_id'] ?? '') === (string)$s['id'] ? 'selected' : '' ?>>
                            <?= $e($s['name']) ?><?= $s['is_active'] ? '' : ' (inactive)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$suppliers): ?><span class="sx-hint">No suppliers yet — <a href="/sweepxpress/admin/supplier.php">add one</a>.</span><?php endif; ?>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="pf-cost">Cost price (₱) <span class="sx-hint">(optional)</span></label>
                <input type="number" id="pf-cost" name="cost_price" class="sx-input" min="0" step="0.01" inputmode="decimal" value="<?= $e($v['cost_price'] ?? '') ?>">
                <span class="sx-hint" id="pf-margin" aria-live="polite"></span>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="pf-reorder">Reorder point</label>
                <input type="number" id="pf-reorder" name="reorder_point" class="sx-input" required min="0" step="1" value="<?= $e($v['reorder_point'] ?? '10') ?>">
                <span class="sx-hint">Low stock at or below this many units.</span>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="pf-reorder-qty">Reorder quantity <span class="sx-hint">(optional)</span></label>
                <input type="number" id="pf-reorder-qty" name="reorder_qty" class="sx-input" min="1" step="1" value="<?= $e($v['reorder_qty'] ?? '') ?>">
                <span class="sx-hint">Usual amount to order from the supplier.</span>
            </div>
        </div>
        <script>
        // Margin habang nagta-type (price − cost)
        (function () {
            const price = document.getElementById('pf-price'), cost = document.getElementById('pf-cost'), out = document.getElementById('pf-margin');
            function update() {
                const p = parseFloat(price.value), c = parseFloat(cost.value);
                if (isNaN(p) || isNaN(c) || cost.value === '') { out.textContent = ''; return; }
                const m = p - c, pct = p > 0 ? (m / p * 100) : 0;
                out.textContent = m < 0 ? 'Cost is higher than the price (loss of ₱' + Math.abs(m).toFixed(2) + ' per unit).'
                                        : 'Margin ₱' + m.toFixed(2) + ' per unit (' + pct.toFixed(1) + '%).';
                out.style.color = m < 0 ? 'var(--sx-danger)' : '';
            }
            price.addEventListener('input', update);
            cost.addEventListener('input', update);
            update();
        })();
        </script>
        <?php
    }

    function sx_csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        return $_SESSION['csrf_token'];
    }

    function sx_csrf_ok(): bool
    {
        return hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
    }
}
