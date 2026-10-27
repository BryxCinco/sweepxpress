<?php
// admin/product_history.php — lahat ng galaw ng stock ng isang produkto, may running balance at chart
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) { header("Location: /sweepxpress/login.php"); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/stock_ops.php';

$product_id = (int)($_GET['product_id'] ?? 0);

// Product + kasalukuyang stock (redirect bago ang header; dati pagkatapos kaya hindi gumagana)
$productStmt = $pdo->prepare("
    SELECT p.id, p.name, COALESCE(p.sku, '') AS sku, COALESCE(p.image_path, '') AS image_path, p.price,
           IFNULL(SUM(i.quantity), 0) AS current_stock
    FROM products p
    LEFT JOIN inventory i ON i.product_id = p.id
    WHERE p.id = ?
    GROUP BY p.id, p.name, p.sku, p.image_path, p.price
");
$productStmt->execute([$product_id]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);
if (!$product) {
    header("Location: inventory.php");
    exit;
}
$current_stock = (int)$product['current_stock'];

// Lahat ng galaw, luma → bago, para makuwenta ang running balance
$historyStmt = $pdo->prepare("
    SELECT sm.movement_type, sm.quantity, sm.remark, sm.created_at, sm.user_name, l.name AS location_name
    FROM stock_movements sm
    LEFT JOIN locations l ON l.id = sm.location_id
    WHERE sm.product_id = ?
    ORDER BY sm.created_at ASC, sm.id ASC
");
$historyStmt->execute([$product_id]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Running balance: mula sa kasalukuyang stock, pabalik
$running = $current_stock;
$totalIn = $totalOut = 0;
for ($i = count($history) - 1; $i >= 0; $i--) {
    $delta = sx_movement_delta($history[$i]);
    $history[$i]['stock_after'] = $running;
    $running -= $delta;
    $history[$i]['stock_before'] = $running;
    if ($delta > 0) $totalIn += $delta; else $totalOut -= $delta;
}

// Chart: stock pagkatapos ng bawat galaw (luma → bago)
$chartLabels = array_map(fn($m) => date('M j', strtotime($m['created_at'])), $history);
$chartData   = array_map(fn($m) => $m['stock_after'], $history);

$history = array_reverse($history); // pinakabago muna sa table
$lowStock = 10;
$stockCls = $current_stock === 0 ? 'out' : ($current_stock <= $lowStock ? 'low' : 'in');
$placeholder = '/sweepxpress/assets/placeholder.jpg';

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   PRODUCT HISTORY (Phase 9e) — tokens + sx-table / mv-type / ap-stock mula sa assets/style.css
   ============================================================ */
.ph { max-width: 1300px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.ph-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-4); margin-bottom: var(--sx-space-5); }
.ph-prod { display: flex; align-items: center; gap: var(--sx-space-4); min-width: 0; }
.ph-prod img { flex-shrink: 0; width: 72px; height: 72px; border-radius: var(--sx-radius-lg); background: var(--sx-surface-alt); object-fit: cover; }
.ph-prod h1 { margin: 0; }
.ph-prod p { margin: 4px 0 0; color: var(--sx-muted); font-size: var(--sx-text-sm); }
.ph-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.ph-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.ph-kpi { padding: var(--sx-space-4); }
.ph-kpi span { display: block; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.ph-kpi strong { display: block; margin-top: 4px; font-size: 1.4rem; font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ph-chart { position: relative; height: 220px; }
.ph-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.ph-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); }
@media (min-width: 768px) { .ph-kpis { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 767.98px) { .ph-table .hide-sm { display: none; } }
</style>

<div class="ph sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/admin/inventory.php">Inventory</a> <span aria-hidden="true">/</span> <span>History</span>
    </nav>

    <div class="ph-head">
        <div class="ph-prod">
            <img src="<?= h($product['image_path'] ?: $placeholder) ?>" alt="" onerror="this.src='<?= $placeholder ?>'">
            <div>
                <h1 class="sx-heading-lg"><?= h($product['name']) ?></h1>
                <p><?= h($product['sku'] ?: 'No SKU') ?> · #<?= (int)$product['id'] ?> · ₱<?= number_format((float)$product['price'], 2) ?></p>
            </div>
        </div>
        <div class="ph-actions">
            <a href="stock_in.php?product_id=<?= (int)$product['id'] ?>" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-arrow-down"></i> Stock in</a>
            <a href="stock_out.php?product_id=<?= (int)$product['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-arrow-up"></i> Stock out</a>
            <a href="stock_adjust.php?product_id=<?= (int)$product['id'] ?>" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-sliders"></i> Adjust</a>
            <a href="edit_product.php?id=<?= (int)$product['id'] ?>" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
        </div>
    </div>

    <div class="ph-kpis">
        <div class="sx-card ph-kpi"><span>On hand</span><strong><span class="ap-stock <?= $stockCls ?>" style="font-size: 1rem"><?= number_format($current_stock) ?></span></strong></div>
        <div class="sx-card ph-kpi"><span>Total in</span><strong>+<?= number_format($totalIn) ?></strong></div>
        <div class="sx-card ph-kpi"><span>Total out</span><strong>−<?= number_format($totalOut) ?></strong></div>
        <div class="sx-card ph-kpi"><span>Movements</span><strong><?= number_format(count($history)) ?></strong></div>
    </div>

    <?php if (count($history) > 1): ?>
        <section class="sx-card" style="margin-bottom: var(--sx-space-4)">
            <h2 class="pf-title" style="margin-bottom: var(--sx-space-3)">Stock over time</h2>
            <div class="ph-chart"><canvas id="stockChart" role="img" aria-label="Stock level after each movement"></canvas></div>
        </section>
    <?php endif; ?>

    <?php if ($history): ?>
        <div class="sx-card" style="padding: 0; overflow: hidden">
            <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                <table class="sx-table ph-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Movement</th>
                            <th class="num hide-sm">Before</th>
                            <th class="num">After</th>
                            <th class="hide-sm">Location</th>
                            <th>Remark</th>
                            <th class="hide-sm">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $m): ?>
                            <tr>
                                <td class="muted" style="white-space: nowrap"><?= date('M j, Y · g:i A', strtotime($m['created_at'])) ?></td>
                                <td style="white-space: nowrap"><?= sx_movement_badge($m) ?></td>
                                <td class="num hide-sm muted"><?= number_format($m['stock_before']) ?></td>
                                <td class="num"><strong><?= number_format($m['stock_after']) ?></strong></td>
                                <td class="hide-sm muted"><?= h($m['location_name'] ?: '—') ?></td>
                                <td class="muted"><?= h($m['remark'] ?: '—') ?></td>
                                <td class="hide-sm muted"><?= h($m['user_name'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <h3>No movements yet</h3>
            <p>Stock in, stock out, adjustments and orders will show up here.</p>
        </div>
    <?php endif; ?>
</div>

<?php if (count($history) > 1): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;
    const css = getComputedStyle(document.documentElement);
    const primary = css.getPropertyValue('--sx-primary').trim();
    const grid = css.getPropertyValue('--sx-border-light').trim();
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = css.getPropertyValue('--sx-muted').trim();
    new Chart(document.getElementById('stockChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Stock', data: <?= json_encode($chartData) ?>, borderColor: primary,
                backgroundColor: primary + '22', fill: true, stepped: true, pointRadius: 0, borderWidth: 2,
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            interaction: { mode: 'index', intersect: false },
            scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: { color: grid }, ticks: { precision: 0 } } },
        },
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
