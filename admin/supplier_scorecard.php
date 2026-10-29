<?php
// admin/supplier_scorecard.php — gaano ka-maaasahan ang bawat supplier (Phase 8d).
// Ang kuwenta: includes/supplier_ops.php (sx_supplier_scorecards), galing sa mga natapos na purchase order.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php';
require_once __DIR__ . '/../includes/supplier_ops.php';

$periods = ['90' => 'Last 90 days', '180' => 'Last 180 days', '365' => 'Last 365 days', 'all' => 'All time'];
$period  = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : '365';
$cards   = sx_supplier_scorecards($pdo, $period === 'all' ? null : (int)$period);

$scored = array_values(array_filter($cards, fn($c) => $c['score'] !== null));
$best   = $scored[0] ?? null;
$avg    = fn(string $k) => ($vals = array_filter(array_column($cards, $k), fn($v) => $v !== null)) ? (int)round(array_sum($vals) / count($vals)) : null;
$avgOn  = $avg('on_time_pct');
$avgFill = $avg('fill_pct');
$priceUps = array_sum(array_column($cards, 'price_up'));

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   SUPPLIER SCORECARD (Phase 8d) — tokens mula sa assets/style.css
   ============================================================ */
.sc { max-width: 1240px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.sc-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-6); }
.sc-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 104px; padding: var(--sx-space-4); }
.sc-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.sc-tile strong { font-size: 1.7rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.sc-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sc-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.sc-tile.hero span, .sc-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.sc-tile.hero strong { color: #fff; font-size: 1.9rem; }
.sc-tile.warn strong { color: var(--sx-status-pending); }

.sc-grid { display: grid; gap: var(--sx-space-4); }
.sc-card { display: grid; gap: var(--sx-space-4); padding: var(--sx-space-5); }
.sc-head { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.sc-rank { flex-shrink: 0; width: 28px; font-size: var(--sx-text-sm); font-weight: var(--sx-weight-bold); color: var(--sx-muted); }
.sc-ring { --v: 0; --c: var(--sx-muted); position: relative; display: grid; place-items: center; flex-shrink: 0; width: 64px; height: 64px; border-radius: 50%;
           background: conic-gradient(var(--c) calc(var(--v) * 1%), var(--sx-surface-alt) 0); }
.sc-ring::before { content: ""; position: absolute; inset: 6px; border-radius: 50%; background: var(--sx-surface); }
.sc-ring b { position: relative; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.sc-ring.ok { --c: var(--sx-status-completed); }
.sc-ring.warn { --c: var(--sx-status-pending); }
.sc-ring.bad { --c: var(--sx-status-cancelled); }
.sc-name { min-width: 0; }
.sc-name a { display: block; overflow: hidden; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); text-decoration: none; text-overflow: ellipsis; white-space: nowrap; }
.sc-name a:hover { color: var(--sx-primary); }
.sc-name small { color: var(--sx-muted); }
.sc-grade { margin-left: auto; flex-shrink: 0; padding: 4px 12px; border-radius: var(--sx-radius-pill); font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); background: var(--sx-surface-alt); color: var(--sx-muted); }
.sc-grade.ok { background: var(--sx-status-completed-bg); color: var(--sx-status-completed); }
.sc-grade.warn { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.sc-grade.bad { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.sc-metrics { display: grid; gap: var(--sx-space-3); }
.sc-m { display: grid; gap: 4px; }
.sc-m header { display: flex; justify-content: space-between; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sc-m header b { color: var(--sx-dark); }
.sc-bar { height: 8px; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); overflow: hidden; }
.sc-bar i { display: block; height: 100%; border-radius: inherit; background: var(--sx-accent); }
.sc-bar i.low { background: var(--sx-status-pending); }
.sc-bar i.bad { background: var(--sx-status-cancelled); }
.sc-facts { display: flex; flex-wrap: wrap; gap: var(--sx-space-2) var(--sx-space-5); padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light); font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sc-facts b { color: var(--sx-dark); }
.sc-prices { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; font-size: var(--sx-text-xs); }
.sc-prices li { display: flex; justify-content: space-between; gap: var(--sx-space-3); color: var(--sx-muted); }
.sc-prices li span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sc-prices .up { color: var(--sx-status-cancelled); font-weight: var(--sx-weight-bold); }
.sc-prices .down { color: var(--sx-status-completed); font-weight: var(--sx-weight-bold); }
.sc-how { margin-top: var(--sx-space-6); padding: var(--sx-space-4) var(--sx-space-5); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.sc-how b { color: var(--sx-dark); }
.sc-period { display: inline-flex; align-items: center; gap: var(--sx-space-2); margin: 0; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sc-period .sx-select { width: auto; min-height: 0; padding-block: 6px; }
@media (min-width: 768px) {
    .sc-bento { grid-template-columns: 1.6fr repeat(3, 1fr); }
    .sc-tile.hero { grid-column: span 1; }
    .sc-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 1100px) { .sc-grid { grid-template-columns: repeat(3, 1fr); } }
</style>

<div class="sc sx-container">
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Supplier scorecard</h1>
            <p>How reliably each supplier delivers: on time, in full, and at a steady price. Based on completed purchase orders.</p>
        </div>
        <div style="display: flex; align-items: center; gap: var(--sx-space-3); flex-wrap: wrap">
            <form method="get" class="sc-period">
                <label for="sc-period">Period</label>
                <select name="period" id="sc-period" class="sx-select" onchange="this.form.submit()">
                    <?php foreach ($periods as $k => $label): ?><option value="<?= h($k) ?>"<?= $k === $period ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </form>
            <a href="suppliers.php" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-truck-field"></i> Suppliers</a>
        </div>
    </div>

    <div class="sc-bento">
        <div class="sx-card sc-tile hero">
            <span>Most reliable supplier</span>
            <strong><?= $best ? h($best['supplier']['name']) : '—' ?></strong>
            <small><?= $best ? 'Score ' . (int)$best['score'] . ' · ' . h($best['grade']) : 'Not enough completed orders yet' ?></small>
        </div>
        <div class="sx-card sc-tile"><span>Delivered on time</span><strong><?= $avgOn === null ? '—' : $avgOn . '%' ?></strong><small>Average across suppliers</small></div>
        <div class="sx-card sc-tile"><span>Fill rate</span><strong><?= $avgFill === null ? '—' : $avgFill . '%' ?></strong><small>Received ÷ ordered</small></div>
        <div class="sx-card sc-tile<?= $priceUps ? ' warn' : '' ?>"><span>Price increases</span><strong><?= (int)$priceUps ?></strong><small>Products up 3% or more</small></div>
    </div>

    <?php if (!$cards): ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-truck-field"></i>
            <h3>No suppliers yet</h3>
            <p>Add a supplier and receive a purchase order to start building a scorecard.</p>
            <a href="supplier.php" class="sx-btn sx-btn-primary">Add supplier</a>
        </div>
    <?php else: ?>
        <div class="sc-grid">
            <?php $rank = 0; foreach ($cards as $id => $c):
                $s = $c['supplier'];
                $rank += $c['score'] !== null ? 1 : 0;
                $bar = fn(?int $v) => $v === null ? '' : ($v >= 90 ? '' : ($v >= 75 ? ' low' : ' bad'));
            ?>
                <article class="sx-card sc-card">
                    <div class="sc-head">
                        <?php if ($c['score'] !== null): ?>
                            <span class="sc-rank">#<?= $rank ?></span>
                            <div class="sc-ring <?= h($c['tone']) ?>" style="--v: <?= (int)$c['score'] ?>" role="img" aria-label="Score <?= (int)$c['score'] ?> out of 100"><b><?= (int)$c['score'] ?></b></div>
                        <?php else: ?>
                            <div class="sc-ring" role="img" aria-label="No score yet"><b>—</b></div>
                        <?php endif; ?>
                        <div class="sc-name">
                            <a href="supplier.php?id=<?= (int)$s['id'] ?>"><?= h($s['name']) ?></a>
                            <small><?= (int)$c['pos'] ?> completed order<?= $c['pos'] === 1 ? '' : 's' ?><?= $s['is_active'] ? '' : ' · inactive' ?></small>
                        </div>
                        <span class="sc-grade <?= h($c['tone']) ?>"><?= h($c['grade']) ?></span>
                    </div>

                    <?php if ($c['pos']): ?>
                        <div class="sc-metrics">
                            <div class="sc-m">
                                <header><span>On time</span><b><?= $c['on_time_pct'] === null ? '—' : $c['on_time_pct'] . '%' ?></b></header>
                                <div class="sc-bar"><i class="<?= trim($bar($c['on_time_pct'])) ?>" style="width: <?= (int)($c['on_time_pct'] ?? 0) ?>%"></i></div>
                            </div>
                            <div class="sc-m">
                                <header><span>Fill rate</span><b><?= $c['fill_pct'] === null ? '—' : $c['fill_pct'] . '%' ?></b></header>
                                <div class="sc-bar"><i class="<?= trim($bar($c['fill_pct'])) ?>" style="width: <?= min(100, (int)($c['fill_pct'] ?? 0)) ?>%"></i></div>
                            </div>
                        </div>
                        <div class="sc-facts">
                            <span>Lead time <b><?= $c['lead_actual'] === null ? '—' : rtrim(rtrim(number_format($c['lead_actual'], 1), '0'), '.') . ' d' ?></b> actual vs <b><?= (int)$c['lead_promised'] ?> d</b> promised</span>
                            <?php if ($c['avg_days_late'] !== null): ?><span>Late orders average <b><?= rtrim(rtrim(number_format($c['avg_days_late'], 1), '0'), '.') ?> days</b> late</span><?php endif; ?>
                        </div>
                        <?php if ($c['prices']): ?>
                            <ul class="sc-prices" aria-label="Price changes">
                                <?php foreach (array_slice($c['prices'], 0, 3) as $p): $pct = $p['pct']; ?>
                                    <li>
                                        <span><?= h($p['name']) ?></span>
                                        <span>₱<?= number_format($p['first'], 2) ?> → ₱<?= number_format($p['last'], 2) ?>
                                            <span class="<?= $pct > 0.5 ? 'up' : ($pct < -0.5 ? 'down' : '') ?>"><?= $pct > 0 ? '+' : '' ?><?= number_format($pct, 1) ?>%</span></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="margin: 0; font-size: var(--sx-text-sm); color: var(--sx-muted)">No completed purchase orders in this period.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="sx-card sc-how">
        <b>How the score works:</b> 40% on-time delivery, 40% fill rate (how much of the order actually arrived), 20% price steadiness
        (full marks if prices did not rise, minus 5 points for every +1% average increase). A supplier needs at least
        <?= SX_SC_MIN_POS ?> completed orders to be scored. Orders closed short count against the fill rate.
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
