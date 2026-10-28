<?php
// admin/products.php — listahan ng produkto (search, category, stock). Add: add_product.php · Edit: edit_product.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_products.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

// ---------------- Delete (POST + CSRF; dati GET link) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $back = 'products.php' . (!empty($_POST['back']) ? '?' . preg_replace('/[^\w=&%+.-]/', '', $_POST['back']) : '');

    if (!sx_csrf_ok()) {
        sx_flash(['error', 'Your session expired. Please try again.']);
    } elseif ($id > 0) {
        $stmt = $pdo->prepare("SELECT name, image_path, (SELECT COUNT(*) FROM order_items WHERE product_id = p.id) AS orders FROM products p WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            sx_flash(['error', 'Product not found.']);
        } elseif ($p['orders'] > 0) {
            // Dati: binubura muna ang order_items — nasisira ang mga lumang order at invoice
            sx_flash(['error', "“{$p['name']}” is in {$p['orders']} order(s), so it can't be deleted. Set its stock to 0 instead."]);
        } else {
            try {
                $gallery = $pdo->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
                $gallery->execute([$id]);
                $files = array_merge([$p['image_path']], $gallery->fetchAll(PDO::FETCH_COLUMN));

                // inventory, product_images at stock_movements: ON DELETE CASCADE
                $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
                array_map('sx_delete_upload', $files);
                sx_flash(['success', "“{$p['name']}” was deleted."]);
            } catch (PDOException $e) {
                error_log('Product delete failed: ' . $e->getMessage());
                sx_flash(['error', 'Could not delete the product. Please try again.']);
            }
        }
    }
    header('Location: ' . $back);
    exit();
}

// ---------------- Filters ----------------
$search   = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$stockF   = in_array($_GET['stock'] ?? '', ['low', 'out'], true) ? $_GET['stock'] : '';
$noSupp   = ($_GET['supplier'] ?? '') === 'none';
$sorts    = ['new' => ['Newest', 'id DESC'], 'name' => ['Name A–Z', 'name ASC'], 'stock' => ['Lowest stock', 'stock ASC, name'], 'sold' => ['Best sellers', 'sold DESC, name']];
$sort     = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'new';
$limit    = 24;
$page     = max(1, (int)($_GET['page'] ?? 1));

// Cards (default) o List; tinatandaan sa cookie ng browser na ito
$view = in_array($_GET['view'] ?? '', ['cards', 'list'], true) ? $_GET['view'] : ($_COOKIE['sx_products_view'] ?? 'cards');
if ($view !== 'list') $view = 'cards';
if (isset($_GET['view'])) setcookie('sx_products_view', $view, ['expires' => time() + 31536000, 'path' => '/sweepxpress/admin/', 'samesite' => 'Lax']);

$where  = [];
$params = [];
if ($search !== '') {
    $where[]  = '(p.name LIKE ? OR p.sku LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($category !== '') {
    $where[]  = 'p.category = ?';
    $params[] = $category;
}
if ($noSupp) $where[] = 'p.supplier_id IS NULL';
$having = match ($stockF) {
    'low'   => 'HAVING stock > 0 AND stock <= reorder_point',
    'out'   => 'HAVING stock = 0',
    default => '',
};

$base = "
    SELECT p.id, p.sku, p.name, p.category, p.price, p.cost_price, p.image_path, p.reorder_point, s.name AS supplier_name,
           COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id = p.id), 0) AS stock,
           COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     WHERE oi.product_id = p.id AND o.status <> 'cancelled'), 0) AS sold,
           (SELECT COUNT(*) FROM order_items WHERE product_id = p.id) AS order_lines
    FROM products p
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    {$having}
";

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$base}) t");
$countStmt->execute($params);
$totalResults = (int)$countStmt->fetchColumn();
$totalPages   = max(1, (int)ceil($totalResults / $limit));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $limit;

$stmt = $pdo->prepare("SELECT * FROM ({$base}) t ORDER BY {$sorts[$sort][1]} LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buod ng buong catalog (bento sa itaas; hindi apektado ng filter)
$sum = $pdo->query("
    SELECT COUNT(*) AS products,
           COALESCE(SUM(q.stock * q.price), 0) AS value,
           SUM(q.stock > 0 AND q.stock <= q.reorder_point) AS low,
           SUM(q.stock = 0) AS out_of_stock,
           SUM(q.supplier_id IS NULL) AS no_supplier,
           AVG(CASE WHEN q.cost_price IS NOT NULL AND q.price > 0 THEN (q.price - q.cost_price) / q.price * 100 END) AS margin
    FROM (
        SELECT p.price, p.cost_price, p.reorder_point, p.supplier_id, COALESCE(SUM(i.quantity), 0) AS stock
        FROM products p LEFT JOIN inventory i ON i.product_id = p.id
        GROUP BY p.id, p.price, p.cost_price, p.reorder_point, p.supplier_id
    ) q
")->fetch(PDO::FETCH_ASSOC);

$categories   = sx_product_categories($pdo);
$totalAll     = (int)$sum['products'];
$flash        = sx_flash();
$csrf         = sx_csrf_token();
$placeholder  = '/sweepxpress/assets/placeholder.jpg';

// Link na pinapanatili ang filters
$url = function (array $changes = []) use ($search, $category, $stockF, $noSupp, $sort, $page) {
    $q = array_filter(array_merge(['q' => $search, 'category' => $category, 'stock' => $stockF, 'supplier' => $noSupp ? 'none' : null,
                                   'sort' => $sort === 'new' ? null : $sort, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'products.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ============================================================
   ADMIN PRODUCTS (Phase 9i) — bento + cards (may List toggle); tokens mula sa assets/style.css
   ============================================================ */
.ap { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ap-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ap-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.ap-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

/* Bento */
.ap-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ap-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 104px; padding: var(--sx-space-4); color: inherit; text-decoration: none; }
.ap-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.ap-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.ap-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ap-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.ap-tile.hero span, .ap-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.ap-tile.hero strong { color: #fff; font-size: 2.2rem; }
.ap-tile.warn strong { color: var(--sx-status-pending); }
.ap-tile.bad strong { color: var(--sx-status-cancelled); }
a.ap-tile { transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
a.ap-tile:hover { transform: translateY(-2px); box-shadow: var(--sx-shadow-md); }

/* Toolbar */
.ap-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.ap-tools-row { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); }
.ap-search { display: flex; flex: 1 1 260px; gap: var(--sx-space-2); max-width: 460px; }
.ap-search .sx-input { flex: 1; min-width: 0; }
.ap-sort { width: auto; }
.ap-view { display: inline-flex; margin-left: auto; padding: 3px; border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-pill); background: var(--sx-surface); }
.ap-view a { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: var(--sx-radius-pill); color: var(--sx-muted); font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); text-decoration: none; }
.ap-view a.on { background: var(--sx-dark); color: var(--sx-on-dark); }
.ap-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.ap-chips .sep { width: 1px; margin: 0 var(--sx-space-1); background: var(--sx-border-light); }

/* Cards */
.ap-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: var(--sx-space-4); }
.ap-card { display: flex; flex-direction: column; overflow: hidden; padding: 0; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.ap-card:hover { transform: translateY(-4px); box-shadow: var(--sx-shadow-lg); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.ap-card-media { position: relative; display: block; aspect-ratio: 4 / 3; overflow: hidden; background: var(--sx-surface-alt); }
.ap-card-media img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
.ap-card:hover .ap-card-media img { transform: scale(1.05); }
.ap-card-media .ap-stock { position: absolute; top: var(--sx-space-3); left: var(--sx-space-3); box-shadow: var(--sx-shadow-sm); }
.ap-card-media .ap-cat { position: absolute; right: var(--sx-space-3); bottom: var(--sx-space-3); max-width: 70%; overflow: hidden; padding: 3px 10px; border-radius: var(--sx-radius-pill); background: color-mix(in srgb, var(--sx-surface) 90%, transparent); color: var(--sx-text); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); text-overflow: ellipsis; white-space: nowrap; backdrop-filter: blur(6px); }
.ap-card-body { display: flex; flex: 1; flex-direction: column; gap: var(--sx-space-3); padding: var(--sx-space-4); }
.ap-card-body h3 { margin: 0; font-size: var(--sx-text-base); font-weight: var(--sx-weight-bold); line-height: 1.3; }
.ap-card-body h3 a { color: var(--sx-dark); text-decoration: none; }
.ap-card-body h3 a:hover { color: var(--sx-primary); }
.ap-sku { display: block; margin-top: 2px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ap-price { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--sx-space-2); }
.ap-price strong { font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ap-margin { padding: 2px 8px; border-radius: var(--sx-radius-pill); background: var(--sx-accent-soft); color: var(--sx-accent-dark); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); }
.ap-margin.neg { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.ap-bar { height: 6px; overflow: hidden; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.ap-bar i { display: block; height: 100%; border-radius: inherit; background: var(--sx-accent); }
.ap-bar.low i { background: var(--sx-status-pending); }
.ap-bar.out i { background: var(--sx-status-cancelled); }
.ap-meta { display: flex; justify-content: space-between; gap: var(--sx-space-2); margin-top: 6px; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ap-meta b { color: var(--sx-dark); }
.ap-card-foot { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-2); margin-top: auto; padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); }
.ap-card-foot small { min-width: 0; overflow: hidden; font-size: var(--sx-text-xs); color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
.ap-card-foot small i { margin-right: 4px; color: var(--sx-primary); }
.ap-actions { display: flex; flex-shrink: 0; justify-content: flex-end; gap: var(--sx-space-1); }
.ap-actions .sx-btn { padding: 6px 10px; }
.ap-actions .sx-btn[disabled] { opacity: 0.4; cursor: not-allowed; }

/* List */
.ap-table td { vertical-align: middle; }
.ap-prod { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 220px; }
.ap-prod img { flex-shrink: 0; width: 48px; height: 48px; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.ap-prod a { color: var(--sx-dark); font-weight: var(--sx-weight-bold); text-decoration: none; }
.ap-prod a:hover { color: var(--sx-primary); }
.ap-table .num { text-align: right; white-space: nowrap; }
.ap-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); }

@media (min-width: 768px) { .ap-bento { grid-template-columns: 1.6fr repeat(4, 1fr); } .ap-tile.hero { grid-column: span 1; } }
@media (max-width: 767.98px) { .ap-table .hide-sm { display: none; } .ap-view { margin-left: 0; } }
</style>

<div class="ap sx-container">
    <div class="ap-head">
        <div>
            <span class="sx-eyebrow">Catalog</span>
            <h1 class="sx-heading-lg">Products</h1>
            <p><?= $totalAll ?> product<?= $totalAll === 1 ? '' : 's' ?> in the catalog</p>
        </div>
        <div class="ap-head-actions">
            <a href="/sweepxpress/admin/import_csv.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-file-csv"></i> Import CSV</a>
            <a href="/sweepxpress/admin/add_product.php" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-plus"></i> Add product</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="ap-bento">
        <div class="sx-card ap-tile hero">
            <span>Catalog value</span>
            <strong>₱<?= number_format((float)$sum['value'], 0) ?></strong>
            <small>Stock on hand × selling price</small>
        </div>
        <div class="sx-card ap-tile">
            <span>Average margin</span>
            <strong><?= $sum['margin'] === null ? '—' : number_format((float)$sum['margin'], 1) . '%' ?></strong>
            <small>Products with a cost price</small>
        </div>
        <a href="<?= h($url(['stock' => 'low', 'page' => null])) ?>" class="sx-card ap-tile<?= $sum['low'] ? ' warn' : '' ?>">
            <span>Low stock</span><strong><?= (int)$sum['low'] ?></strong><small>At or below reorder point</small>
        </a>
        <a href="<?= h($url(['stock' => 'out', 'page' => null])) ?>" class="sx-card ap-tile<?= $sum['out_of_stock'] ? ' bad' : '' ?>">
            <span>Out of stock</span><strong><?= (int)$sum['out_of_stock'] ?></strong><small>Can't be ordered</small>
        </a>
        <a href="<?= h($url(['supplier' => 'none', 'page' => null])) ?>" class="sx-card ap-tile<?= $sum['no_supplier'] ? ' warn' : '' ?>">
            <span>No supplier</span><strong><?= (int)$sum['no_supplier'] ?></strong><small>Can't be reordered yet</small>
        </a>
    </div>

    <div class="ap-tools">
        <div class="ap-tools-row">
            <form class="ap-search" method="get" role="search">
                <?php foreach (['category' => $category, 'stock' => $stockF, 'supplier' => $noSupp ? 'none' : '', 'sort' => $sort === 'new' ? '' : $sort] as $k => $v): ?>
                    <?php if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <input type="search" name="q" class="sx-input" placeholder="Search name or SKU…" value="<?= h($search) ?>" aria-label="Search products">
                <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
            </form>
            <select class="sx-select ap-sort" aria-label="Sort products" onchange="location.href = this.value">
                <?php foreach ($sorts as $k => [$label]): ?>
                    <option value="<?= h($url(['sort' => $k === 'new' ? null : $k, 'page' => null])) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <nav class="ap-view" aria-label="View">
                <a href="<?= h($url(['view' => 'cards'])) ?>" class="<?= $view === 'cards' ? 'on' : '' ?>" <?= $view === 'cards' ? 'aria-current="true"' : '' ?>><i class="fa-solid fa-grip"></i> Cards</a>
                <a href="<?= h($url(['view' => 'list'])) ?>" class="<?= $view === 'list' ? 'on' : '' ?>" <?= $view === 'list' ? 'aria-current="true"' : '' ?>><i class="fa-solid fa-list"></i> List</a>
            </nav>
        </div>
        <div class="ap-chips" aria-label="Filters">
            <a href="<?= h($url(['category' => null, 'page' => null])) ?>" class="sx-chip<?= $category === '' ? ' active' : '' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="<?= h($url(['category' => $cat, 'page' => null])) ?>" class="sx-chip<?= $category === $cat ? ' active' : '' ?>"><?= h($cat) ?></a>
            <?php endforeach; ?>
            <span class="sep" aria-hidden="true"></span>
            <a href="<?= h($url(['stock' => $stockF === 'low' ? null : 'low', 'page' => null])) ?>" class="sx-chip<?= $stockF === 'low' ? ' active' : '' ?>">Low stock</a>
            <a href="<?= h($url(['stock' => $stockF === 'out' ? null : 'out', 'page' => null])) ?>" class="sx-chip<?= $stockF === 'out' ? ' active' : '' ?>">Out of stock</a>
            <a href="<?= h($url(['supplier' => $noSupp ? null : 'none', 'page' => null])) ?>" class="sx-chip<?= $noSupp ? ' active' : '' ?>">No supplier</a>
        </div>
    </div>

    <?php if ($items): ?>
        <?php
        // Isang beses kinukuwenta para sa cards at list
        $rows = array_map(function ($p) {
            $stock = (int)$p['stock']; $rp = (int)$p['reorder_point'];
            $p['cls'] = $stock === 0 ? 'out' : ($stock <= $rp ? 'low' : 'in');
            $p['bar'] = min(100, (int)round($stock / max(1, $rp * 3) * 100)); // puno ang bar sa 3× ng reorder point
            $p['margin'] = $p['cost_price'] !== null && (float)$p['price'] > 0 ? ((float)$p['price'] - (float)$p['cost_price']) / (float)$p['price'] * 100 : null;
            return $p;
        }, $items);
        $deleteBtn = function (array $p): string {
            $name = h($p['name']);
            return '<button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-delete="' . (int)$p['id'] . '" data-name="' . $name . '" '
                . ($p['order_lines'] > 0 ? 'disabled title="In ' . (int)$p['order_lines'] . ' order(s) — can\'t be deleted"' : 'title="Delete"')
                . ' aria-label="Delete ' . $name . '"><i class="fa-solid fa-trash"></i></button>';
        };
        ?>
        <?php if ($view === 'cards'): ?>
            <div class="ap-grid">
                <?php foreach ($rows as $p): $edit = '/sweepxpress/admin/edit_product.php?id=' . (int)$p['id']; ?>
                    <article class="sx-card ap-card">
                        <a href="<?= $edit ?>" class="ap-card-media" tabindex="-1" aria-hidden="true">
                            <img src="<?= h($p['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                            <span class="ap-stock <?= $p['cls'] ?>"><?= (int)$p['stock'] === 0 ? 'Out of stock' : ($p['cls'] === 'low' ? 'Low stock' : 'In stock') ?></span>
                            <span class="ap-cat"><?= h($p['category'] ?: 'Uncategorized') ?></span>
                        </a>
                        <div class="ap-card-body">
                            <div>
                                <h3><a href="<?= $edit ?>"><?= h($p['name']) ?></a></h3>
                                <span class="ap-sku"><?= h($p['sku'] ?: '—') ?> · #<?= (int)$p['id'] ?></span>
                            </div>
                            <div class="ap-price">
                                <strong>₱<?= number_format((float)$p['price'], 2) ?></strong>
                                <?php if ($p['margin'] !== null): ?><span class="ap-margin<?= $p['margin'] < 0 ? ' neg' : '' ?>"><?= number_format($p['margin'], 0) ?>% margin</span><?php endif; ?>
                            </div>
                            <div>
                                <div class="ap-bar <?= $p['cls'] ?>" role="img" aria-label="<?= (int)$p['stock'] ?> in stock, reorder at <?= (int)$p['reorder_point'] ?>"><i style="width: <?= $p['bar'] ?>%"></i></div>
                                <div class="ap-meta"><span><b><?= number_format((int)$p['stock']) ?></b> in stock</span><span>reorder at <?= (int)$p['reorder_point'] ?></span></div>
                            </div>
                            <div class="ap-card-foot">
                                <small title="<?= h($p['supplier_name'] ?: 'No supplier') ?>"><i class="fa-solid fa-truck-field"></i><?= h($p['supplier_name'] ?: 'No supplier') ?> · <?= number_format((int)$p['sold']) ?> sold</small>
                                <div class="ap-actions">
                                    <a href="/sweepxpress/product.php?id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-ghost sx-btn-sm" target="_blank" title="View in store" aria-label="View <?= h($p['name']) ?> in store"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                                    <?= $deleteBtn($p) ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="sx-card" style="padding: 0; overflow: hidden">
                <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                    <table class="sx-table ap-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="hide-sm">Category</th>
                                <th class="hide-sm">Supplier</th>
                                <th class="num">Price</th>
                                <th class="num hide-sm">Margin</th>
                                <th>Stock</th>
                                <th class="num hide-sm">Sold</th>
                                <th class="num"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $p): ?>
                                <tr>
                                    <td>
                                        <div class="ap-prod">
                                            <img src="<?= h($p['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                                            <div>
                                                <a href="/sweepxpress/admin/edit_product.php?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a>
                                                <span class="ap-sku"><?= h($p['sku'] ?: '—') ?> · #<?= (int)$p['id'] ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="hide-sm muted"><?= h($p['category'] ?: 'Uncategorized') ?></td>
                                    <td class="hide-sm muted"><?= h($p['supplier_name'] ?: '—') ?></td>
                                    <td class="num">₱<?= number_format((float)$p['price'], 2) ?></td>
                                    <td class="num hide-sm"><?= $p['margin'] === null ? '—' : number_format($p['margin'], 0) . '%' ?></td>
                                    <td><span class="ap-stock <?= $p['cls'] ?>"><?= (int)$p['stock'] === 0 ? 'Out' : number_format((int)$p['stock']) ?></span></td>
                                    <td class="num hide-sm"><?= number_format((int)$p['sold']) ?></td>
                                    <td>
                                        <div class="ap-actions">
                                            <a href="/sweepxpress/product.php?id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-ghost sx-btn-sm" target="_blank" title="View in store" aria-label="View <?= h($p['name']) ?> in store"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                                            <a href="/sweepxpress/admin/edit_product.php?id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm" aria-label="Edit <?= h($p['name']) ?>"><i class="fa-solid fa-pen"></i></a>
                                            <?= $deleteBtn($p) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($items) ?> of <?= $totalResults ?></span>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Pages">
                    <?php if ($page > 1): ?><a href="<?= h($url(['page' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?><span class="cur" aria-current="page"><?= $i ?></span>
                        <?php else: ?><a href="<?= h($url(['page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a href="<?= h($url(['page' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-box-open"></i>
            <h3>No products found</h3>
            <p><?= $search || $category || $stockF || $noSupp ? 'Try a different search or filter.' : 'Add your first product to get started.' ?></p>
            <a href="/sweepxpress/admin/add_product.php" class="sx-btn sx-btn-primary sx-btn-sm" style="margin-top: var(--sx-space-3)"><i class="fa-solid fa-plus"></i> Add product</a>
        </div>
    <?php endif; ?>
</div>

<dialog class="co-dialog" id="deleteDialog" aria-labelledby="deleteTitle">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteId">
        <input type="hidden" name="back" value="<?= h(parse_url($url(), PHP_URL_QUERY) ?? '') ?>">
        <div class="co-dialog-head">
            <h3 id="deleteTitle">Delete product?</h3>
            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="co-dialog-body">
            <p style="margin: 0">“<strong id="deleteName"></strong>” and its stock records and images will be removed. This can't be undone.</p>
        </div>
        <div class="co-dialog-foot">
            <button type="button" class="sx-btn sx-btn-ghost" data-close>Cancel</button>
            <button type="submit" class="sx-btn sx-btn-danger">Delete</button>
        </div>
    </form>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('deleteDialog');
    document.querySelectorAll('[data-delete]').forEach((btn) => btn.addEventListener('click', () => {
        document.getElementById('deleteId').value = btn.dataset.delete;
        document.getElementById('deleteName').textContent = btn.dataset.name;
        dialog.showModal();
    }));
    dialog.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
