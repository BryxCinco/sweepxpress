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
require_once __DIR__ . '/../includes/purchasing_ops.php'; // sx_po_on_order
require_once __DIR__ . '/../includes/forecast_ops.php';   // sx_sales_velocity

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
$limit  = 24;
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
    SELECT p.id, p.name, COALESCE(p.sku, '') AS sku, COALESCE(p.price, 0) AS price, p.cost_price, p.image_path, p.reorder_point,
           p.category, s.name AS supplier_name, s.lead_time_days,
           IFNULL(SUM(i.quantity), 0) AS real_stock,
           (SELECT MAX(created_at) FROM stock_movements WHERE product_id = p.id) AS last_move
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    {$whereSql}
    GROUP BY p.id, p.name, p.sku, p.price, p.cost_price, p.image_path, p.reorder_point, p.category, s.name
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
$onOrder  = sx_po_on_order($pdo);
$window   = sx_forecast_window();
$velocity = sx_sales_velocity($pdo, $window);
$incoming = array_sum($onOrder);

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
   INVENTORY (Phase 9i) — bento + isang card bawat produkto; tokens mula sa assets/style.css
   ============================================================ */
.iv { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.iv-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.iv-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.iv-head-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }

/* Bento */
.iv-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.iv-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 104px; padding: var(--sx-space-4); color: inherit; text-decoration: none; }
.iv-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.iv-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.iv-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.iv-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.iv-tile.hero span, .iv-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.iv-tile.hero strong { color: #fff; font-size: 2.2rem; }
.iv-tile.warn strong { color: var(--sx-status-pending); }
.iv-tile.bad strong { color: var(--sx-status-cancelled); }
a.iv-tile { transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
a.iv-tile:hover { transform: translateY(-2px); box-shadow: var(--sx-shadow-md); }
.iv-moves { grid-column: span 2; display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-2); padding: var(--sx-space-3); }
.iv-move { display: flex; align-items: center; gap: var(--sx-space-3); padding: var(--sx-space-2) var(--sx-space-3); border-radius: var(--sx-radius-md); color: var(--sx-dark); font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); text-decoration: none; transition: background var(--sx-transition); }
.iv-move:hover { background: var(--sx-surface-alt); color: var(--sx-primary); }
.iv-move i { display: grid; place-items: center; width: 32px; height: 32px; border-radius: var(--sx-radius-md); background: var(--sx-primary-soft); color: var(--sx-primary); }
.iv-move.in i { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.iv-move.out i { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }

/* Toolbar */
.iv-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); padding: var(--sx-space-4); }
.iv-search { display: flex; gap: var(--sx-space-2); }
.iv-search .sx-input { flex: 1; min-width: 0; }
.iv-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.iv-chips .sx-chip span { margin-left: 4px; opacity: 0.75; }

/* Cards */
.iv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--sx-space-4); }
.iv-card { display: flex; flex-direction: column; gap: var(--sx-space-4); padding: var(--sx-space-4); cursor: pointer; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.iv-card:hover { transform: translateY(-4px); box-shadow: var(--sx-shadow-lg); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.iv-card.low { border-color: color-mix(in srgb, var(--sx-status-pending) 35%, transparent); }
.iv-card.out { border-color: color-mix(in srgb, var(--sx-status-cancelled) 40%, transparent); }
.iv-card-top { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.iv-card-top img { width: 56px; height: 56px; flex-shrink: 0; border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); object-fit: cover; }
.iv-card-top div { min-width: 0; flex: 1; }
.iv-card-top strong { display: block; overflow: hidden; color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.iv-card-top small { display: block; overflow: hidden; font-size: var(--sx-text-xs); color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
.iv-card-top small code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.iv-level { display: flex; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-2); }
.iv-level strong { font-size: 2rem; font-weight: var(--sx-weight-extrabold); line-height: 1; letter-spacing: -0.02em; color: var(--sx-dark); }
.iv-level strong small { margin-left: 4px; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.iv-card.low .iv-level strong { color: var(--sx-status-pending); }
.iv-card.out .iv-level strong { color: var(--sx-status-cancelled); }
.iv-gauge { position: relative; height: 8px; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.iv-gauge i, .iv-gauge b { position: absolute; inset: 0 auto 0 0; border-radius: inherit; }
.iv-gauge b { background: color-mix(in srgb, var(--sx-primary) 40%, transparent); }
.iv-gauge i { background: var(--sx-accent); }
.iv-card.low .iv-gauge i { background: var(--sx-status-pending); }
.iv-gauge em { position: absolute; top: -3px; bottom: -3px; width: 2px; border-radius: 2px; background: var(--sx-dark); opacity: 0.5; }
.iv-gauge-label { display: flex; justify-content: space-between; margin-top: 6px; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.iv-gauge-label .on { color: var(--sx-primary); font-weight: var(--sx-weight-semibold); }
.iv-stats { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sx-space-2); }
.iv-stats div { padding: var(--sx-space-2) var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); }
.iv-stats span { display: block; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.iv-days.low { color: var(--sx-status-cancelled); }
.iv-days.out { color: var(--sx-status-cancelled); }
.iv-days.none { color: var(--sx-muted); font-weight: var(--sx-weight-regular); }
.iv-stats b { font-size: var(--sx-text-sm); color: var(--sx-dark); }
.iv-card-foot { display: flex; align-items: center; justify-content: space-between; gap: var(--sx-space-2); margin-top: auto; padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); }
.iv-card-foot small { min-width: 0; overflow: hidden; font-size: var(--sx-text-xs); color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
.iv-row-actions { display: flex; flex-shrink: 0; gap: var(--sx-space-1); }
.iv-row-actions .sx-btn { padding: 6px 10px; }

@media (max-width: 767.98px) { .iv-tile.wide-sm { grid-column: span 2; } }
#scanner-modal .co-dialog-body { min-width: min(420px, 85vw); }

@media (min-width: 768px) {
    .iv-bento { grid-template-columns: 1.5fr repeat(3, 1fr); }
    .iv-tile.hero { grid-column: span 1; grid-row: span 2; }
    .iv-moves { grid-column: span 3; grid-template-columns: repeat(4, 1fr); }
    .iv-tools { grid-template-columns: minmax(280px, 420px) 1fr; align-items: center; }
    .iv-chips { justify-content: flex-end; }
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

    <div class="iv-bento">
        <div class="sx-card iv-tile hero">
            <span>Stock value</span>
            <strong>₱<?= number_format((float)$valData['total_value'], 0) ?></strong>
            <small><?= number_format((int)$valData['units']) ?> units on hand · at selling price</small>
        </div>
        <a href="<?= h($url(['filter' => 'low', 'page' => null])) ?>" class="sx-card iv-tile<?= $valData['low_stock_count'] ? ' warn' : '' ?>">
            <span>Low stock</span><strong><?= (int)$valData['low_stock_count'] ?></strong><small>At or below reorder point</small>
        </a>
        <a href="<?= h($url(['filter' => 'out', 'page' => null])) ?>" class="sx-card iv-tile<?= $valData['out_of_stock_count'] ? ' bad' : '' ?>">
            <span>Out of stock</span><strong><?= (int)$valData['out_of_stock_count'] ?></strong><small>Can't be ordered</small>
        </a>
        <a href="purchase_orders.php?tab=sent" class="sx-card iv-tile wide-sm">
            <span>Incoming</span><strong><?= number_format($incoming) ?></strong><small>Units on open purchase orders</small>
        </a>
        <nav class="sx-card iv-moves" aria-label="Stock actions">
            <a href="stock_in.php" class="iv-move in"><i class="fa-solid fa-arrow-down"></i> Stock in</a>
            <a href="stock_out.php" class="iv-move out"><i class="fa-solid fa-arrow-up"></i> Stock out</a>
            <a href="stock_adjust.php" class="iv-move"><i class="fa-solid fa-sliders"></i> Adjust</a>
            <a href="inventory_full_history.php" class="iv-move"><i class="fa-solid fa-clock-rotate-left"></i> Full history</a>
        </nav>
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
            <?= sx_forecast_picker($window, ['search' => $search, 'filter' => $filter === 'all' ? null : $filter]) ?>
        </div>
    </div>

    <?php if ($products): ?>
        <div class="iv-grid">
            <?php foreach ($products as $p):
                $stock = (int)$p['real_stock'];
                $rp    = (int)$p['reorder_point'];
                $oo    = (int)($onOrder[$p['id']] ?? 0);
                $cls   = $stock === 0 ? 'out' : ($stock <= $rp ? 'low' : 'in');
                $max   = max(1, $rp * 3, $stock + $oo);   // sukat ng gauge: hindi bababa sa 3× ng reorder point
                $historyUrl = 'product_history.php?product_id=' . (int)$p['id'];
                $perDay = $velocity[(int)$p['id']]['per_day'] ?? 0.0;
                [$dLabel, $dTone] = sx_days_label(sx_days_of_stock($stock, $perDay), $stock, (int)($p['lead_time_days'] ?? 7));
            ?>
                <article class="sx-card iv-card <?= $cls ?>" data-href="<?= $historyUrl ?>">
                    <div class="iv-card-top">
                        <img src="<?= h($p['image_path'] ?: $placeholder) ?>" alt="" loading="lazy" onerror="this.src='<?= $placeholder ?>'">
                        <div>
                            <strong><?= h($p['name']) ?></strong>
                            <small><code><?= h($p['sku'] ?: '—') ?></code> · <?= h($p['category'] ?: 'Uncategorized') ?></small>
                        </div>
                        <span class="ap-stock <?= $cls ?>"><?= $cls === 'out' ? 'Out' : ($cls === 'low' ? 'Low' : 'OK') ?></span>
                    </div>
                    <div>
                        <div class="iv-level">
                            <strong><?= number_format($stock) ?><small>units</small></strong>
                        </div>
                        <div class="iv-gauge" role="img" aria-label="<?= $stock ?> in stock<?= $oo ? ", {$oo} on order" : '' ?>, reorder at <?= $rp ?>" style="margin-top: var(--sx-space-2)">
                            <?php if ($oo): ?><b style="width: <?= min(100, round(($stock + $oo) / $max * 100)) ?>%"></b><?php endif; ?>
                            <i style="width: <?= min(100, round($stock / $max * 100)) ?>%"></i>
                            <em style="left: <?= min(100, round($rp / $max * 100)) ?>%" title="Reorder point"></em>
                        </div>
                        <div class="iv-gauge-label"><span>Reorder at <?= $rp ?></span><?php if ($oo): ?><span class="on">+<?= number_format($oo) ?> on order</span><?php endif; ?></div>
                    </div>
                    <div class="iv-stats">
                        <div><span>Value</span><b>₱<?= number_format($stock * (float)$p['price'], 2) ?></b></div>
                        <div><span>Unit price</span><b>₱<?= number_format((float)$p['price'], 2) ?></b></div>
                        <div><span>Sells</span><b><?= $perDay > 0 ? rtrim(rtrim(number_format($perDay, 2), '0'), '.') . '/day' : '—' ?></b></div>
                        <div><span>Runs out</span><b class="iv-days <?= $dTone ?>"><?= h($dLabel) ?></b></div>
                    </div>
                    <div class="iv-card-foot">
                        <small><i class="fa-regular fa-clock"></i> <?= $p['last_move'] ? 'Moved ' . date('M j, Y', strtotime($p['last_move'])) : 'No movements yet' ?><?= $p['supplier_name'] ? ' · ' . h($p['supplier_name']) : '' ?></small>
                        <div class="iv-row-actions">
                            <a href="stock_in.php?product_id=<?= (int)$p['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm" title="Stock in" aria-label="Stock in <?= h($p['name']) ?>"><i class="fa-solid fa-plus"></i></a>
                            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-qr="<?= h($p['name']) ?>" title="Print QR label" aria-label="Print QR label for <?= h($p['name']) ?>"><i class="fa-solid fa-qrcode"></i></button>
                            <a href="<?= $historyUrl ?>" class="sx-btn sx-btn-ghost sx-btn-sm" title="History" aria-label="History of <?= h($p['name']) ?>"><i class="fa-solid fa-chevron-right"></i></a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
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
    // Buong card → product history (maliban kapag pinindot ang button/link sa loob)
    document.querySelectorAll('.iv-card[data-href]').forEach((card) => card.addEventListener('click', (e) => {
        if (!e.target.closest('a, button')) window.location.href = card.dataset.href;
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
