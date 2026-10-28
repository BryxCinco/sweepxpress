<?php
// admin/suppliers.php — lahat ng supplier bilang cards (Phase 7b). Detail at edit: supplier.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php'; // sx_flash
require_once __DIR__ . '/../includes/supplier_ops.php';

$show   = in_array($_GET['show'] ?? '', ['active', 'inactive', 'all'], true) ? $_GET['show'] : 'active';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($show !== 'all') { $where[] = 's.is_active = ?'; $params[] = $show === 'active' ? 1 : 0; }
if ($search !== '') {
    $where[] = '(s.name LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
}
$suppliers = sx_supplier_rows($pdo, $where ? implode(' AND ', $where) : '1=1', $params);

// Buod (lahat ng supplier, hindi apektado ng filter)
$sum = $pdo->query("
    SELECT (SELECT COUNT(*) FROM suppliers WHERE is_active = 1) AS active,
           (SELECT COUNT(*) FROM suppliers WHERE is_active = 0) AS inactive,
           (SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','partial')) AS open_pos,
           (SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','partial')
                AND expected_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS arriving,
           (SELECT COUNT(*) FROM purchase_orders WHERE status IN ('sent','partial') AND expected_date < CURDATE()) AS late,
           (SELECT COUNT(*) FROM products WHERE supplier_id IS NULL) AS unassigned
")->fetch(PDO::FETCH_ASSOC);

$flash = sx_flash();
$url = function (array $changes = []) use ($show, $search) {
    $q = array_filter(array_merge(['show' => $show === 'active' ? null : $show, 'q' => $search], $changes), fn($v) => $v !== null && $v !== '');
    return 'suppliers.php' . ($q ? '?' . http_build_query($q) : '');
};

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   SUPPLIERS (Phase 7b) — bento cards; tokens mula sa assets/style.css
   ============================================================ */
.sp { max-width: 1300px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.sp-bento { display: grid; grid-template-columns: repeat(2, 1fr); grid-auto-flow: dense; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.sp-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 112px; padding: var(--sx-space-4); color: inherit; text-decoration: none; }
.sp-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.sp-tile strong { font-size: 1.75rem; font-weight: var(--sx-weight-extrabold); line-height: 1; color: var(--sx-dark); }
.sp-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sp-tile i { justify-self: end; font-size: 1.1rem; color: var(--sx-primary); }
.sp-tile.hero { grid-column: span 2; background: var(--sx-gradient-brand); color: #fff; }
.sp-tile.hero span, .sp-tile.hero small { color: rgba(255, 255, 255, 0.8); }
.sp-tile.hero strong { color: #fff; font-size: 2.4rem; }
.sp-tile.hero i { color: rgba(255, 255, 255, 0.85); }
.sp-tile.warn strong { color: var(--sx-status-pending); }
.sp-tile.bad strong { color: var(--sx-status-cancelled); }
.sp-tile.wide { grid-column: span 2; }
a.sp-tile { transition: transform var(--sx-transition), box-shadow var(--sx-transition); }
a.sp-tile:hover { transform: translateY(-2px); box-shadow: var(--sx-shadow-md); }

.sp-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-3); margin-bottom: var(--sx-space-4); }
.sp-tools form { display: flex; flex: 1 1 280px; gap: var(--sx-space-2); max-width: 420px; }
.sp-tools .sx-input { flex: 1; min-width: 0; }
.sp-chips { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); }
.sp-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }

.sp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: var(--sx-space-4); }
.sp-card { position: relative; display: grid; gap: var(--sx-space-4); padding: var(--sx-space-5); color: inherit; text-decoration: none; transition: transform var(--sx-transition), box-shadow var(--sx-transition), border-color var(--sx-transition); }
.sp-card:hover { transform: translateY(-3px); box-shadow: var(--sx-shadow-lg); border-color: color-mix(in srgb, var(--sx-primary) 30%, transparent); }
.sp-card.off { opacity: 0.7; }
.sp-card-head { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.sp-av { display: grid; place-items: center; flex-shrink: 0; width: 48px; height: 48px; border-radius: var(--sx-radius-md); font-weight: var(--sx-weight-extrabold); letter-spacing: 0.02em; }
.sp-av.t0 { background: var(--sx-primary-soft); color: var(--sx-primary); }
.sp-av.t1 { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.sp-av.t2 { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.sp-av.t3 { background: var(--sx-status-shipped-bg); color: var(--sx-status-shipped); }
.sp-av.t4 { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.sp-card-head div { min-width: 0; }
.sp-card-head strong { display: block; overflow: hidden; font-size: var(--sx-text-base); font-weight: var(--sx-weight-bold); color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.sp-card-head small { display: block; overflow: hidden; color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
.sp-card .sx-pill { position: absolute; top: var(--sx-space-4); right: var(--sx-space-4); }
.sp-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sx-space-2); }
.sp-stats div { padding: var(--sx-space-2) var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); }
.sp-stats b { display: block; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.sp-stats span { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sp-meta { display: flex; flex-wrap: wrap; gap: var(--sx-space-2) var(--sx-space-4); margin: 0; padding: 0; list-style: none; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.sp-meta i { margin-right: 4px; color: var(--sx-primary); }
.sp-meta .good i { color: var(--sx-accent-dark); }

@media (min-width: 768px) {
    .sp-bento { grid-template-columns: 2fr repeat(3, 1fr); }
    .sp-tile.hero { grid-column: span 1; grid-row: span 2; min-height: 0; }
}
</style>

<div class="sp sx-container">
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg">Suppliers</h1>
            <p>Who you buy from, what they supply and how reliably they deliver.</p>
        </div>
        <a href="supplier.php" class="sx-btn sx-btn-primary"><i class="fa-solid fa-plus"></i> Add supplier</a>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="sp-bento">
        <div class="sx-card sp-tile hero">
            <span>Active suppliers</span>
            <strong><?= (int)$sum['active'] ?></strong>
            <small><?= (int)$sum['inactive'] ?> inactive</small>
            <i class="fa-solid fa-truck-field"></i>
        </div>
        <div class="sx-card sp-tile">
            <span>Open purchase orders</span>
            <strong><?= (int)$sum['open_pos'] ?></strong>
            <small>Sent or partly received</small>
        </div>
        <div class="sx-card sp-tile">
            <span>Arriving in 7 days</span>
            <strong><?= (int)$sum['arriving'] ?></strong>
            <small>By expected date</small>
        </div>
        <div class="sx-card sp-tile<?= $sum['late'] ? ' bad' : '' ?>">
            <span>Late deliveries</span>
            <strong><?= (int)$sum['late'] ?></strong>
            <small>Past expected date</small>
        </div>
        <a href="products.php" class="sx-card sp-tile wide<?= $sum['unassigned'] ? ' warn' : '' ?>">
            <span>Products without a supplier</span>
            <strong><?= (int)$sum['unassigned'] ?></strong>
            <small>Set one on the product page</small>
        </a>
        <a href="supplier.php" class="sx-card sp-tile">
            <span>New supplier</span>
            <small>Add contact, lead time and terms</small>
            <i class="fa-solid fa-plus"></i>
        </a>
    </div>

    <div class="sp-tools">
        <form method="get" role="search">
            <?php if ($show !== 'active'): ?><input type="hidden" name="show" value="<?= h($show) ?>"><?php endif; ?>
            <input type="search" name="q" class="sx-input" placeholder="Name, contact, email or phone…" value="<?= h($search) ?>" aria-label="Search suppliers">
            <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
        <nav class="sp-chips" aria-label="Filter suppliers">
            <a href="<?= h($url(['show' => null])) ?>" class="sx-chip<?= $show === 'active' ? ' active' : '' ?>">Active <span><?= (int)$sum['active'] ?></span></a>
            <a href="<?= h($url(['show' => 'inactive'])) ?>" class="sx-chip<?= $show === 'inactive' ? ' active' : '' ?>">Inactive <span><?= (int)$sum['inactive'] ?></span></a>
            <a href="<?= h($url(['show' => 'all'])) ?>" class="sx-chip<?= $show === 'all' ? ' active' : '' ?>">All <span><?= (int)$sum['active'] + (int)$sum['inactive'] ?></span></a>
        </nav>
    </div>

    <?php if ($suppliers): ?>
        <div class="sp-grid">
            <?php foreach ($suppliers as $s): $onTime = sx_supplier_on_time($s); ?>
                <a href="supplier.php?id=<?= (int)$s['id'] ?>" class="sx-card sp-card<?= $s['is_active'] ? '' : ' off' ?>">
                    <?php if (!$s['is_active']): ?><span class="sx-pill sx-pill-cancelled">Inactive</span><?php endif; ?>
                    <div class="sp-card-head">
                        <span class="sp-av t<?= (int)$s['id'] % 5 ?>" aria-hidden="true"><?= h(sx_supplier_initials($s['name'])) ?></span>
                        <div>
                            <strong><?= h($s['name']) ?></strong>
                            <small><?= h($s['contact_person'] ?: ($s['email'] ?: ($s['phone'] ?: 'No contact yet'))) ?></small>
                        </div>
                    </div>
                    <div class="sp-stats">
                        <div><b><?= (int)$s['products'] ?></b><span>Products</span></div>
                        <div><b><?= (int)$s['open_pos'] ?></b><span>Open POs</span></div>
                        <div><b><?= $onTime === null ? '—' : $onTime . '%' ?></b><span>On time</span></div>
                    </div>
                    <ul class="sp-meta">
                        <li><i class="fa-regular fa-clock"></i><?= (int)$s['lead_time_days'] ?>-day lead time</li>
                        <?php if ($s['payment_terms']): ?><li><i class="fa-solid fa-file-invoice"></i><?= h($s['payment_terms']) ?></li><?php endif; ?>
                        <li class="<?= $s['last_delivery'] ? 'good' : '' ?>"><i class="fa-solid fa-box-open"></i><?= $s['last_delivery'] ? 'Last delivery ' . date('M j', strtotime($s['last_delivery'])) : 'No deliveries yet' ?></li>
                    </ul>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-truck-field"></i>
            <?php if ($search !== '' || $show !== 'active'): ?>
                <h3>No suppliers match</h3>
                <p>Try another search or filter.</p>
            <?php else: ?>
                <h3>No suppliers yet</h3>
                <p>Add the companies you buy stock from, then link them to products.</p>
                <a href="supplier.php" class="sx-btn sx-btn-primary"><i class="fa-solid fa-plus"></i> Add supplier</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
