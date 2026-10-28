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
$limit    = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));

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
    SELECT p.id, p.sku, p.name, p.category, p.price, p.image_path, p.reorder_point,
           COALESCE((SELECT SUM(quantity) FROM inventory WHERE product_id = p.id), 0) AS stock,
           COALESCE((SELECT SUM(oi.quantity) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     WHERE oi.product_id = p.id AND o.status <> 'cancelled'), 0) AS sold,
           (SELECT COUNT(*) FROM order_items WHERE product_id = p.id) AS order_lines
    FROM products p
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    {$having}
";

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$base}) t");
$countStmt->execute($params);
$totalResults = (int)$countStmt->fetchColumn();
$totalPages   = max(1, (int)ceil($totalResults / $limit));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $limit;

$stmt = $pdo->prepare("{$base} ORDER BY p.id DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories   = sx_product_categories($pdo);
$totalAll     = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$flash        = sx_flash();
$csrf         = sx_csrf_token();
$placeholder  = '/sweepxpress/assets/placeholder.jpg';

// Link na pinapanatili ang filters
$url = function (array $changes = []) use ($search, $category, $stockF, $noSupp, $page) {
    $q = array_filter(array_merge(['q' => $search, 'category' => $category, 'stock' => $stockF, 'supplier' => $noSupp ? 'none' : null, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'products.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   ADMIN PRODUCTS (Phase 9e) — tokens + sx-table / sx-chip / co-dialog mula sa assets/style.css
   ============================================================ */
.ap { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ap-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ap-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.ap-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

.ap-filters { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); padding: var(--sx-space-4); }
.ap-search { display: flex; gap: var(--sx-space-2); }
.ap-search .sx-input { flex: 1; }
.ap-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.ap-chips .sep { width: 1px; margin: 0 var(--sx-space-1); background: var(--sx-border-light); }

.ap-table td { vertical-align: middle; }
.ap-prod { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 220px; }
.ap-prod img { flex-shrink: 0; width: 48px; height: 48px; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.ap-prod a { color: var(--sx-dark); font-weight: var(--sx-weight-bold); text-decoration: none; }
.ap-prod a:hover { color: var(--sx-primary); }
.ap-sku { display: block; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ap-table .num { text-align: right; white-space: nowrap; }
.ap-actions { display: flex; justify-content: flex-end; gap: var(--sx-space-1); }
.ap-actions .sx-btn { padding: 6px 10px; }
.ap-actions .sx-btn[disabled] { opacity: 0.4; cursor: not-allowed; }


@media (min-width: 768px) {
    .ap-filters { grid-template-columns: minmax(260px, 380px) 1fr; align-items: center; }
}
@media (max-width: 767.98px) {
    .ap-table .hide-sm { display: none; }
}
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

    <div class="sx-card ap-filters">
        <form class="ap-search" method="get" role="search">
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= h($category) ?>"><?php endif; ?>
            <?php if ($stockF !== ''): ?><input type="hidden" name="stock" value="<?= h($stockF) ?>"><?php endif; ?>
            <?php if ($noSupp): ?><input type="hidden" name="supplier" value="none"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="Search name or SKU…" value="<?= h($search) ?>" aria-label="Search products">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
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
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table ap-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="hide-sm">Category</th>
                            <th class="num">Price</th>
                            <th>Stock</th>
                            <th class="num hide-sm">Sold</th>
                            <th class="num"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $p):
                            $stock = (int)$p['stock'];
                            $cls = $stock === 0 ? 'out' : ($stock <= (int)$p['reorder_point'] ? 'low' : 'in');
                        ?>
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
                                <td class="hide-sm"><?= h($p['category'] ?: 'Uncategorized') ?></td>
                                <td class="num">₱<?= number_format((float)$p['price'], 2) ?></td>
                                <td><span class="ap-stock <?= $cls ?>"><?= $stock === 0 ? 'Out' : number_format($stock) ?></span></td>
                                <td class="num hide-sm"><?= number_format((int)$p['sold']) ?></td>
                                <td>
                                    <div class="ap-actions">
                                        <a href="/sweepxpress/product.php?id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-ghost sx-btn-sm" target="_blank" title="View in store" aria-label="View <?= h($p['name']) ?> in store"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
                                        <a href="/sweepxpress/admin/edit_product.php?id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm" aria-label="Edit <?= h($p['name']) ?>"><i class="fa-solid fa-pen"></i></a>
                                        <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-delete="<?= (int)$p['id'] ?>" data-name="<?= h($p['name']) ?>"
                                                <?= $p['order_lines'] > 0 ? 'disabled title="In ' . (int)$p['order_lines'] . ' order(s) — can\'t be deleted"' : 'title="Delete"' ?>
                                                aria-label="Delete <?= h($p['name']) ?>"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

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
            <p><?= $search || $category || $stockF ? 'Try a different search or filter.' : 'Add your first product to get started.' ?></p>
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
