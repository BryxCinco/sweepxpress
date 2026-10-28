<?php

require_once __DIR__ . '/../config.php';

// Login check bago ang header (dati pagkatapos, kaya hindi gumagana ang redirect)
if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$user_id = $_SESSION['user']['id'];

// Status → [label, pill class, tab]
// "shipped" ay ipinapakita pa ring "Preparing" (gaya ng dati)
$statusMap = [
    'pending'                => ['Pending',          'pending',   'pending'],
    'Pending PO'             => ['PO under review',  'pending',   'pending'],
    'preparing'              => ['Preparing',        'preparing', 'preparing'],
    'shipped'                => ['Preparing',        'preparing', 'preparing'],
    'delivered'              => ['Delivered',        'delivered', 'delivered'],
    'completed'              => ['Completed',        'completed', 'completed'],
    'cancellation_requested' => ['Cancel requested', 'cancelled', 'cancelled'],
    'cancelled'              => ['Cancelled',        'cancelled', 'cancelled'],
];
$tabs = [
    'all'       => 'All',
    'pending'   => 'Pending',
    'preparing' => 'Preparing',
    'delivered' => 'Delivered',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

try {
    // Isang delivery row lang bawat order (iwas dobleng card kung may duplicate sa deliveries)
    $stmt = $pdo->prepare("
        SELECT o.*, d.delivery_date
        FROM orders o
        LEFT JOIN (
            SELECT order_id, MAX(delivery_date) AS delivery_date
            FROM deliveries
            GROUP BY order_id
        ) d ON d.order_id = o.id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lahat ng items ng user sa isang query, naka-group bawat order
    $itemsByOrder = [];
    if ($orders) {
        $stmt = $pdo->prepare("
            SELECT oi.order_id, oi.quantity, p.name, p.image_path
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE o.user_id = ?
            ORDER BY oi.id
        ");
        $stmt->execute([$user_id]);
        foreach ($stmt as $row) {
            $itemsByOrder[$row['order_id']][] = $row;
        }
    }
} catch (PDOException $e) {
    error_log('my_orders.php: ' . $e->getMessage());
    $orders = [];
    $itemsByOrder = [];
    $loadError = true;
}

// Bilang bawat tab
$tabCounts = array_fill_keys(array_keys($tabs), 0);
$tabCounts['all'] = count($orders);
foreach ($orders as $o) {
    $tab = $statusMap[$o['status'] ?? ''][2] ?? 'pending';
    $tabCounts[$tab]++;
}

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   MY ORDERS (Phase 9d) — tokens + sx-pill / sx-chip mula sa assets/style.css
   ============================================================ */
.mo { padding-block: var(--sx-space-5) var(--sx-space-8); }
.mo-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-5);
}
.mo-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }

/* Status tabs (gaya ng "To Pay / To Ship" sa Shopee) */
.mo-tabs {
    position: sticky;
    top: var(--sx-nav-h, 70px);
    z-index: 5;
    display: flex;
    gap: var(--sx-space-2);
    margin: 0 calc(var(--sx-space-4) * -1) var(--sx-space-4);
    padding: var(--sx-space-3) var(--sx-space-4);
    overflow-x: auto;
    scrollbar-width: none;
    background: var(--sx-bg);
}
.mo-tabs::-webkit-scrollbar { display: none; }
.mo-tabs .sx-chip { flex-shrink: 0; }
.mo-tabs .sx-chip span {
    min-width: 20px;
    padding: 0 6px;
    border-radius: var(--sx-radius-pill);
    background: var(--sx-surface-alt);
    color: var(--sx-muted);
    font-size: var(--sx-text-xs);
    text-align: center;
}
.mo-tabs .sx-chip.active span { background: color-mix(in srgb, var(--sx-on-dark) 20%, transparent); color: inherit; }

/* Order card */
.mo-list { display: grid; gap: var(--sx-space-4); }
.mo-card { padding: 0; overflow: hidden; }
.mo-card[hidden] { display: none; }
.mo-card.has-update { box-shadow: inset 3px 0 0 var(--sx-status-pending), var(--sx-shadow-sm); }
.mo-card-top {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--sx-space-2) var(--sx-space-3);
    padding: var(--sx-space-3) var(--sx-space-5);
    border-bottom: 1px solid var(--sx-border-light);
}
.mo-id { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.mo-id small { font-weight: var(--sx-weight-medium); color: var(--sx-muted); }
.mo-tag {
    padding: 2px 8px;
    border-radius: var(--sx-radius-pill);
    background: var(--sx-primary-soft);
    color: var(--sx-primary);
    font-size: var(--sx-text-xs);
    font-weight: var(--sx-weight-bold);
}
.mo-tag.update { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }

.mo-card-body {
    display: grid;
    gap: var(--sx-space-4);
    padding: var(--sx-space-4) var(--sx-space-5);
}
.mo-items { display: flex; align-items: center; gap: var(--sx-space-3); min-width: 0; }
.mo-thumbs { display: flex; flex-shrink: 0; }
.mo-thumbs img,
.mo-thumbs .more {
    width: 56px;
    height: 56px;
    border: 2px solid var(--sx-surface);
    border-radius: var(--sx-radius-md);
    background: var(--sx-surface-alt);
    object-fit: cover;
}
.mo-thumbs > * + * { margin-left: -14px; }
.mo-thumbs .more {
    display: grid;
    place-items: center;
    font-size: var(--sx-text-xs);
    font-weight: var(--sx-weight-bold);
    color: var(--sx-muted);
}
.mo-items-text { min-width: 0; }
.mo-items-text strong {
    display: block;
    overflow: hidden;
    color: var(--sx-dark);
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mo-items-text span { font-size: var(--sx-text-sm); color: var(--sx-muted); }

.mo-meta { display: flex; flex-wrap: wrap; gap: var(--sx-space-2) var(--sx-space-5); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.mo-meta i { margin-right: 4px; }
.mo-meta .ok { color: var(--sx-status-delivered); }

.mo-card-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--sx-space-3);
    padding: var(--sx-space-3) var(--sx-space-5);
    background: var(--sx-surface-alt);
}
.mo-total { font-size: var(--sx-text-sm); color: var(--sx-muted); }
.mo-total strong { margin-left: 4px; font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-primary); }
.mo-actions { display: flex; gap: var(--sx-space-2); }

.mo-empty-tab[hidden] { display: none; }

@media (min-width: 768px) {
    .mo-tabs { margin-inline: 0; padding-inline: 0; }
    .mo-card-body { grid-template-columns: 1fr auto; align-items: center; }
    .mo-meta { justify-content: flex-end; }
}
@media (max-width: 575.98px) {
    .mo-actions { width: 100%; }
    .mo-actions .sx-btn { flex: 1; }
}
</style>

<div class="mo sx-container">
    <div class="mo-head">
        <div>
            <span class="sx-eyebrow">Account</span>
            <h1 class="sx-heading-lg">My Orders</h1>
            <p><?= number_format(count($orders)) ?> order<?= count($orders) === 1 ? '' : 's' ?> placed</p>
        </div>
        <a href="/sweepxpress/index.php" class="sx-btn sx-btn-ghost"><i class="fa-solid fa-store"></i> Continue shopping</a>
    </div>

    <?php if (!empty($loadError)): ?>
        <div class="sx-alert sx-alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i><div>We couldn't load your orders right now. Please try again later.</div>
        </div>
    <?php elseif (($_GET['status'] ?? '') === 'not_found'): ?>
        <div class="sx-alert sx-alert-warning" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div>That order could not be found.</div>
        </div>
    <?php endif; ?>

    <?php if ($orders): ?>
        <nav class="mo-tabs" aria-label="Filter orders by status">
            <?php foreach ($tabs as $key => $label): ?>
                <button type="button" class="sx-chip<?= $key === 'all' ? ' active' : '' ?>" data-tab="<?= $key ?>"
                        aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>">
                    <?= $label ?> <span><?= $tabCounts[$key] ?></span>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="mo-list">
            <?php foreach ($orders as $order):
                $status = $order['status'] ?? 'pending';
                [$statusLabel, $pillClass, $tab] = $statusMap[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'pending', 'pending'];
                $items     = $itemsByOrder[$order['id']] ?? [];
                $unitCount = array_sum(array_column($items, 'quantity'));
                $hasUpdate = trim($order['admin_note'] ?? '') !== '';
                $isPO      = $order['order_type'] === 'B2B' || $status === 'Pending PO';
            ?>
                <article class="sx-card mo-card<?= $hasUpdate ? ' has-update' : '' ?>" data-tab="<?= $tab ?>">
                    <div class="mo-card-top">
                        <div class="mo-id">
                            Order #<?= (int)$order['id'] ?>
                            <?php if ($isPO): ?><span class="mo-tag">Purchase order</span><?php endif; ?>
                            <?php if ($hasUpdate): ?><span class="mo-tag update"><i class="fa-solid fa-bell"></i> New update</span><?php endif; ?>
                            <small>· <?= date('M j, Y', strtotime($order['created_at'])) ?></small>
                        </div>
                        <span class="sx-pill sx-pill-<?= $pillClass ?>"><?= h($statusLabel) ?></span>
                    </div>

                    <div class="mo-card-body">
                        <div class="mo-items">
                            <?php if ($items): ?>
                                <div class="mo-thumbs" aria-hidden="true">
                                    <?php foreach (array_slice($items, 0, 3) as $it): ?>
                                        <img src="<?= h($it['image_path'] ?: '/sweepxpress/assets/placeholder.jpg') ?>" alt=""
                                             loading="lazy" onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                                    <?php endforeach; ?>
                                    <?php if (count($items) > 3): ?><span class="more">+<?= count($items) - 3 ?></span><?php endif; ?>
                                </div>
                                <div class="mo-items-text">
                                    <strong><?= h($items[0]['name'] ?? 'Item no longer available') ?></strong>
                                    <span>
                                        <?= count($items) > 1 ? '+ ' . (count($items) - 1) . ' more · ' : '' ?>
                                        <?= $unitCount ?> unit<?= $unitCount === 1 ? '' : 's' ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <div class="mo-items-text"><span>No item details</span></div>
                            <?php endif; ?>
                        </div>

                        <div class="mo-meta">
                            <span><i class="fa-solid fa-wallet"></i><?= h($order['payment_terms'] ?: $order['payment_method']) ?></span>
                            <?php if (!empty($order['delivery_date'])): ?>
                                <span class="ok"><i class="fa-solid fa-truck"></i>Delivery <?= date('M j, Y', strtotime($order['delivery_date'])) ?></span>
                            <?php else: ?>
                                <span><i class="fa-regular fa-calendar"></i>Delivery not yet scheduled</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mo-card-foot">
                        <div class="mo-total">Order total <strong>₱<?= number_format((float)$order['total'], 2) ?></strong></div>
                        <div class="mo-actions">
                            <a href="/sweepxpress/Invoice.php?order_id=<?= (int)$order['id'] ?>" class="sx-btn sx-btn-ghost sx-btn-sm">
                                <i class="fa-solid fa-file-invoice"></i> Invoice
                            </a>
                            <a href="/sweepxpress/customers/order_details.php?id=<?= (int)$order['id'] ?>" class="sx-btn sx-btn-primary sx-btn-sm">
                                View details
                            </a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="sx-card sx-empty mo-empty-tab" hidden>
                <i class="fa-solid fa-box-open"></i>
                <h3>No orders here</h3>
                <p>Orders with this status will show up here.</p>
            </div>
        </div>

        <script>
        // Status tabs — client-side lang, walang reload
        document.addEventListener('DOMContentLoaded', function () {
            const chips = document.querySelectorAll('.mo-tabs .sx-chip');
            const cards = document.querySelectorAll('.mo-card');
            const empty = document.querySelector('.mo-empty-tab');

            chips.forEach((chip) => chip.addEventListener('click', function () {
                const tab = this.dataset.tab;
                let shown = 0;
                chips.forEach((c) => {
                    c.classList.toggle('active', c === this);
                    c.setAttribute('aria-pressed', c === this ? 'true' : 'false');
                });
                cards.forEach((card) => {
                    const match = tab === 'all' || card.dataset.tab === tab;
                    card.hidden = !match;
                    if (match) shown++;
                });
                empty.hidden = shown > 0;
            }));
        });
        </script>
    <?php elseif (empty($loadError)): ?>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-box-open"></i>
            <h3>No orders yet</h3>
            <p>When you place an order, you can follow it here.</p>
            <a href="/sweepxpress/index.php" class="sx-btn sx-btn-primary sx-btn-arrow" style="margin-top: var(--sx-space-4)">
                Start shopping <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
