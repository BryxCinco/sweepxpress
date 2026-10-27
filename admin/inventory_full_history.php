<?php
/**
 * admin/inventory_full_history.php — lahat ng galaw ng stock (in, out, adjust) ng lahat ng produkto.
 */
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/stock_ops.php';

$limit  = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$type   = in_array($_GET['type'] ?? '', ['IN', 'OUT', 'ADJUST'], true) ? $_GET['type'] : '';

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR s.remark LIKE ? OR s.user_name LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
}
if ($type !== '') {
    $where[] = 's.movement_type = ?';
    $params[] = $type;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM stock_movements s LEFT JOIN products p ON s.product_id = p.id {$whereSql}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT s.id, s.product_id, s.movement_type, s.quantity, s.remark, s.created_at, s.user_name,
           l.name AS location_name, p.name AS product_name, p.sku
    FROM stock_movements s
    LEFT JOIN locations l ON s.location_id = l.id
    LEFT JOIN products p ON s.product_id = p.id
    {$whereSql}
    ORDER BY s.created_at DESC, s.id DESC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bilang bawat uri (para sa chips)
$typeCounts = $pdo->query("SELECT movement_type, COUNT(*) FROM stock_movements GROUP BY movement_type")->fetchAll(PDO::FETCH_KEY_PAIR);

$url = function (array $changes = []) use ($search, $type, $page) {
    $q = array_filter(array_merge(['search' => $search, 'type' => $type, 'page' => $page > 1 ? $page : null], $changes),
        fn($v) => $v !== null && $v !== '');
    return 'inventory_full_history.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   FULL STOCK HISTORY (Phase 9e) — tokens + sx-table / sx-chip / mv-type mula sa assets/style.css
   ============================================================ */
.fh { max-width: 1440px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.fh-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.fh-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.fh-tools { display: grid; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); padding: var(--sx-space-4); }
.fh-search { display: flex; gap: var(--sx-space-2); }
.fh-search .sx-input { flex: 1; min-width: 0; }
.fh-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.fh-chips .sx-chip span { margin-left: 4px; opacity: 0.75; }
.fh-table tbody tr { cursor: pointer; }
.fh-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); }
.fh-table .prod strong { display: block; color: var(--sx-dark); }
.fh-table .prod small { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--sx-muted); }
@media (min-width: 768px) {
    .fh-tools { grid-template-columns: minmax(280px, 460px) 1fr; align-items: center; }
    .fh-chips { justify-content: flex-end; }
}
@media (max-width: 767.98px) { .fh-table .hide-sm { display: none; } }
</style>

<div class="fh sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/admin/inventory.php">Inventory</a> <span aria-hidden="true">/</span> <span>Full history</span>
    </nav>
    <div class="fh-head">
        <div>
            <h1 class="sx-heading-lg">Stock movements</h1>
            <p>Every stock in, stock out, adjustment and order deduction.</p>
        </div>
    </div>

    <div class="sx-card fh-tools">
        <form method="GET" class="fh-search" role="search">
            <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= h($type) ?>"><?php endif; ?>
            <input type="search" name="search" class="sx-input" placeholder="Search product, SKU, remark or user…" value="<?= h($search) ?>" aria-label="Search movements">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
        <div class="fh-chips">
            <a href="<?= h($url(['type' => null, 'page' => null])) ?>" class="sx-chip<?= $type === '' ? ' active' : '' ?>">All <span><?= array_sum($typeCounts) ?></span></a>
            <?php foreach (['IN' => 'Stock in', 'OUT' => 'Stock out', 'ADJUST' => 'Adjustments'] as $k => $label): ?>
                <a href="<?= h($url(['type' => $k, 'page' => null])) ?>" class="sx-chip<?= $type === $k ? ' active' : '' ?>"><?= $label ?> <span><?= (int)($typeCounts[$k] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($rows): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table fh-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Movement</th>
                            <th class="hide-sm">Location</th>
                            <th class="hide-sm">By</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr data-href="product_history.php?product_id=<?= (int)$r['product_id'] ?>">
                                <td class="muted" style="white-space: nowrap"><?= date('M j, Y · g:i A', strtotime($r['created_at'])) ?></td>
                                <td class="prod"><strong><?= h($r['product_name'] ?? 'Deleted product') ?></strong><small><?= h($r['sku'] ?: '—') ?></small></td>
                                <td style="white-space: nowrap"><?= sx_movement_badge($r) ?></td>
                                <td class="hide-sm muted"><?= h($r['location_name'] ?: '—') ?></td>
                                <td class="hide-sm muted"><?= h($r['user_name'] ?: '—') ?></td>
                                <td class="muted"><?= h($r['remark'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ap-pager">
            <span>Showing <?= $offset + 1 ?>–<?= $offset + count($rows) ?> of <?= number_format($totalRecords) ?></span>
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
            <i class="fa-solid fa-inbox"></i>
            <h3>No movements found</h3>
            <p>Try a different search or filter.</p>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.fh-table tr[data-href]').forEach((tr) => tr.addEventListener('click', () => { window.location.href = tr.dataset.href; }));
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
