<?php
/**
 * admin/inventory.php — stock bawat produkto (galing sa inventory table), valuation,
 * filters, QR label at QR scan na search. Galaw ng stock: stock_in / stock_out / stock_adjust.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_products.php';

// =================================================================================
// 1. CSV EXPORT
// =================================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_report_' . date('Y-m-d_H-i') . '.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, "sep=,\n");
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['ID', 'Product Name', 'SKU', 'Supplier', 'Unit Price', 'Cost Price', 'Current Stock', 'Reorder Point', 'Value at Price', 'Value at Cost', 'Status']);
    try {
        $stmt = $pdo->query("
            SELECT p.id, p.name, p.sku, s.name AS supplier, p.price, p.cost_price, p.reorder_point, IFNULL(SUM(i.quantity), 0) AS real_stock
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            GROUP BY p.id ORDER BY p.name ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stock = (int)$row['real_stock'];
            $price = (float)$row['price'];
            $cost  = $row['cost_price'] === null ? null : (float)$row['cost_price'];
            $status = ($stock == 0) ? 'Out of Stock' : (($stock <= (int)$row['reorder_point']) ? 'Low Stock' : 'In Stock');
            fputcsv($output, [$row['id'], $row['name'], $row['sku'], $row['supplier'] ?? '', number_format($price, 2, '.', ''),
                              $cost === null ? '' : number_format($cost, 2, '.', ''), $stock, (int)$row['reorder_point'],
                              number_format($stock * $price, 2, '.', ''), $cost === null ? '' : number_format($stock * $cost, 2, '.', ''), $status]);
        }
    } catch (Exception $e) {}
    fclose($output);
    exit;
}

// =================================================================================
// 2. STATS
// =================================================================================
$valData = $pdo->query("
    SELECT
        COUNT(*) AS products,
        COALESCE(SUM(s.qty * s.price), 0) AS total_value,
        COALESCE(SUM(s.qty), 0) AS units,
        SUM(s.qty > 0 AND s.qty <= s.reorder_point) AS low_stock_count,
        SUM(s.qty = 0) AS out_of_stock_count
    FROM (
        SELECT p.price, p.reorder_point, IFNULL(SUM(i.quantity), 0) AS qty
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        GROUP BY p.id, p.price, p.reorder_point
    ) s
")->fetch(PDO::FETCH_ASSOC);

// =================================================================================
// 3. LIST
// =================================================================================
$limit  = 15;
$page   = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$filter = in_array($_GET['filter'] ?? '', ['low', 'out'], true) ? $_GET['filter'] : 'all';

$params = [];
$whereSql = '';
if ($search !== '') {
    $whereSql = 'WHERE (p.name LIKE ? OR p.sku LIKE ?)';
    $params = ["%{$search}%", "%{$search}%"];
}
$havingClause = match ($filter) {
    'low'   => 'HAVING real_stock > 0 AND real_stock <= reorder_point',
    'out'   => 'HAVING real_stock = 0',
    default => '',
};

$base = "
    SELECT p.id, p.name, COALESCE(p.sku, '') AS sku, COALESCE(p.price, 0) AS price, p.image_path, p.reorder_point,
           IFNULL(SUM(i.quantity), 0) AS real_stock,
           (SELECT MAX(created_at) FROM stock_movements WHERE product_id = p.id) AS last_move
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    {$whereSql}
    GROUP BY p.id, p.name, p.sku, p.price, p.image_path, p.reorder_point
    {$havingClause}
";

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$base}) t");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

// Low/out muna kapag "All" para unang makita ang kailangang i-restock
$order = $filter === 'all' ? '(real_stock > reorder_point), real_stock ASC, name' : 'real_stock ASC, name';
$stmt = $pdo->prepare("SELECT * FROM ({$base}) t ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = sx_flash();
$placeholder = '/sweepxpress/assets/placeholder.jpg';
$url = function (array $changes = []) use ($search, $filter, $page) {
    $q = array_filter(array_merge(['search' => $search, 'filter' => $filter === 'all' ? null : $filter, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'inventory.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   INVENTORY (Phase 9e) — tokens + sx-table / sx-chip / ap-stock mula sa assets/style.css
   ============================================================ */
.iv { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.iv-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.iv-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.iv-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

.iv-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.iv-kpi { padding: var(--sx-space-4); color: inherit; text-decoration: none; }
.iv-kpi span { display: block; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.iv-kpi strong { display: block; margin-top: 4px; font-size: clamp(1.2rem, 1rem + 0.8vw, 1.6rem); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.iv-kpi.warn strong { color: var(--sx-status-pending); }
.iv-kpi.danger strong { color: var(--sx-status-cancelled); }
a.iv-kpi:hover { box-shadow: var(--sx-shadow-md); }

.iv-moves { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-2); margin-bottom: var(--sx-space-4); }
.iv-move {
    display: flex;
    align-items: center;
    gap: var(--sx-space-3);
    padding: var(--sx-space-3) var(--sx-space-4);
    border: 1px solid var(--sx-border-light);
    border-radius: var(--sx-radius-md);
    background: var(--sx-surface);
    color: var(--sx-dark);
    font-weight: var(--sx-weight-semibold);
    text-decoration: none;
}
.iv-move:hover { border-color: var(--sx-primary); color: var(--sx-primary); }
.iv-move i { display: grid; place-items: center; width: 34px; height: 34px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); }
.iv-move.in i { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.iv-move.out i { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }

.iv-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); padding: var(--sx-space-4); }
.iv-search { display: flex; gap: var(--sx-space-2); }
.iv-search .sx-input { flex: 1; min-width: 0; }
.iv-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.iv-chips .sx-chip span { margin-left: 4px; opacity: 0.75; }

.iv-table tbody tr { cursor: pointer; }
.iv-table .num { text-align: right; white-space: nowrap; }
.iv-prod { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 200px; }
.iv-prod img { flex-shrink: 0; width: 40px; height: 40px; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.iv-prod strong { display: block; color: var(--sx-dark); }
.iv-prod small { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--sx-muted); }
.iv-row-actions { display: flex; justify-content: flex-end; gap: var(--sx-space-1); }
.iv-row-actions .sx-btn { padding: 6px 10px; }

#scanner-modal .co-dialog-body { min-width: min(420px, 85vw); }

@media (min-width: 768px) {
    .iv-kpis { grid-template-columns: repeat(4, 1fr); }
    .iv-moves { grid-template-columns: repeat(4, 1fr); }
    .iv-tools { grid-template-columns: minmax(280px, 420px) 1fr; align-items: center; }
    .iv-chips { justify-content: flex-end; }
}
@media (max-width: 767.98px) {
    .iv-table .hide-sm { display: none; }
}
</style>

<div class="iv sx-container">
    <div class="iv-head">
        <div>
            <span class="sx-eyebrow">Warehouse</span>
            <h1 class="sx-heading-lg">Inventory</h1>
            <p>Stock levels per product, valued at selling price.</p>
        </div>
        <div class="iv-head-actions">
            <a href="cron_stock_alert.php" target="_blank" class="sx-btn sx-btn-outline sx-btn-sm" title="Check for low stock and send the alert email">
                <i class="fa-solid fa-bell"></i> Stock check
            </a>
            <a href="import_csv.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-file-import"></i> Import CSV</a>
            <a href="inventory.php?action=export" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-check"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="iv-kpis">
        <div class="sx-card iv-kpi"><span>Stock value</span><strong>₱<?= number_format((float)$valData['total_value'], 2) ?></strong></div>
        <div class="sx-card iv-kpi"><span>Units on hand</span><strong><?= number_format((int)$valData['units']) ?></strong></div>
        <a href="<?= h($url(['filter' => 'low', 'page' => null])) ?>" class="sx-card iv-kpi warn"><span>Low stock</span><strong><?= (int)$valData['low_stock_count'] ?></strong></a>
        <a href="<?= h($url(['filter' => 'out', 'page' => null])) ?>" class="sx-card iv-kpi danger"><span>Out of stock</span><strong><?= (int)$valData['out_of_stock_count'] ?></strong></a>
    </div>

    <div class="iv-moves">
        <a href="stock_in.php" class="iv-move in"><i class="fa-solid fa-arrow-down"></i> Stock in</a>
        <a href="stock_out.php" class="iv-move out"><i class="fa-solid fa-arrow-up"></i> Stock out</a>
        <a href="stock_adjust.php" class="iv-move"><i class="fa-solid fa-sliders"></i> Adjust</a>
        <a href="inventory_full_history.php" class="iv-move"><i class="fa-solid fa-clock-rotate-left"></i> Full history</a>
    </div>

    <div class="sx-card iv-tools">
        <form method="GET" class="iv-search" id="searchForm" role="search">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
            <input type="search" name="search" id="searchInput" class="sx-input" placeholder="Search product name or SKU…" value="<?= h($search) ?>" aria-label="Search inventory">
            <button type="button" class="sx-btn sx-btn-outline sx-btn-sm" id="openScanner" title="Scan a product QR label" aria-label="Scan QR label"><i class="fa-solid fa-qrcode"></i></button>
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
        <div class="iv-chips">
            <a href="<?= h($url(['filter' => null, 'page' => null])) ?>" class="sx-chip<?= $filter === 'all' ? ' active' : '' ?>">All items <span><?= (int)$valData['products'] ?></span></a>
            <a href="<?= h($url(['filter' => 'low', 'page' => null])) ?>" class="sx-chip<?= $filter === 'low' ? ' active' : '' ?>">Low stock <span><?= (int)$valData['low_stock_count'] ?></span></a>
            <a href="<?= h($url(['filter' => 'out', 'page' => null])) ?>" class="sx-chip<?= $filter === 'out' ? ' active' : '' ?>">Out of stock <span><?= (int)$valData['out_of_stock_count'] ?></span></a>
        </div>
    </div>

    <?php if ($products): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table iv-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="num hide-sm">Price</th>
                            <th>Stock</th>
                            <th class="num hide-sm">Value</th>
                            <th class="hide-sm">Last movement</th>
                            <th class="num"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p):
                            $stock = (int)$p['real_stock'];
                            $cls = $stock === 0 ? 'out' : ($stock <= (int)$p['reorder_point'] ? 'low' : 'in');
                            $historyUrl = 'product_history.php?product_id=' . (int)$p['id'];
                        ?>
                            <tr data-href="<?= $historyUrl ?>">
                                <td>
                                    <div class="iv-prod">
                                        <img src="<?= h($p['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                                        <div><strong><?= h($p['name']) ?></strong><small><?= h($p['sku'] ?: '—') ?></small></div>
                                    </div>
                                </td>
                                <td class="num hide-sm">₱<?= number_format((float)$p['price'], 2) ?></td>
                                <td><span class="ap-stock <?= $cls ?>"><?= $stock === 0 ? 'Out' : number_format($stock) ?></span></td>
                                <td class="num hide-sm">₱<?= number_format($stock * (float)$p['price'], 2) ?></td>
                                <td class="hide-sm"><?= $p['last_move'] ? date('M j, Y', strtotime($p['last_move'])) : '—' ?></td>
                                <td>
                                    <div class="iv-row-actions">
                                        <a href="stock_in.php?product_id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm" title="Stock in" aria-label="Stock in <?= h($p['name']) ?>"><i class="fa-solid fa-plus"></i></a>
                                        <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-qr="<?= h($p['name']) ?>" title="Print QR label" aria-label="Print QR label for <?= h($p['name']) ?>"><i class="fa-solid fa-qrcode"></i></button>
                                        <a href="<?= $historyUrl ?>" class="sx-btn sx-btn-ghost sx-btn-sm" title="History" aria-label="History of <?= h($p['name']) ?>"><i class="fa-solid fa-chevron-right"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($products) ?> of <?= $totalProducts ?></span>
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
            <i class="fa-solid fa-magnifying-glass"></i>
            <h3>No products found</h3>
            <p>Try a different search or filter.</p>
        </div>
    <?php endif; ?>
</div>

<dialog class="co-dialog" id="scanner-modal" aria-labelledby="scanTitle">
    <div class="co-dialog-head">
        <h3 id="scanTitle"><i class="fa-solid fa-camera"></i> Scan product QR</h3>
        <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" id="closeScanner" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="co-dialog-body">
        <p class="sx-hint" style="margin-top: 0">Point the camera at a product label to search for it.</p>
        <div id="scanner-preview"></div>
    </div>
</dialog>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Buong row → product history (maliban kapag pinindot ang button/link sa loob)
    document.querySelectorAll('.iv-table tr[data-href]').forEach((tr) => tr.addEventListener('click', (e) => {
        if (!e.target.closest('a, button')) window.location.href = tr.dataset.href;
    }));

    // QR label: pangalan ng produkto ang laman (ito rin ang hinahanap ng scanner)
    document.querySelectorAll('[data-qr]').forEach((btn) => btn.addEventListener('click', () => {
        const name = btn.dataset.qr;
        const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' + encodeURIComponent(name);
        const win = window.open('', 'Print', 'width=400,height=500');
        win.document.write(`<html><body style="text-align:center;font-family:sans-serif;padding:40px;"><div style="border:3px solid black;border-radius:10px;padding:20px;display:inline-block;"><img src="${url}" width="150" height="150"><br><h2 style="margin:10px 0;">${esc(name)}</h2></div><br><br><button onclick="window.print()" style="padding:10px 20px;">Print Label</button></body></html>`);
        win.document.close();
    }));

    // QR scanner → search
    const dialog = document.getElementById('scanner-modal');
    let scanner = null;
    const stop = () => { if (scanner) { scanner.clear().catch(() => {}); scanner = null; } };
    document.getElementById('openScanner').addEventListener('click', () => {
        if (typeof Html5QrcodeScanner === 'undefined') { sxToast('Scanner could not load', 'error'); return; }
        dialog.showModal();
        scanner = new Html5QrcodeScanner('scanner-preview', { fps: 10, qrbox: 250 }, false);
        scanner.render((text) => {
            stop();
            dialog.close();
            document.getElementById('searchInput').value = text;
            document.getElementById('searchForm').submit();
        }, () => {});
    });
    document.getElementById('closeScanner').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', stop);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
