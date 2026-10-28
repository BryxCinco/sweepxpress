<?php
// admin/purchase_order_print.php — printable na restock PO para sa supplier (Print / Save as PDF).
// Kapareho ng papel ng invoice (sx_document_css sa includes/order_document.php).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/purchasing_ops.php';
require_once __DIR__ . '/../includes/order_document.php';

$id = (int)($_GET['id'] ?? 0);
$po = $id ? sx_po_get($pdo, $id) : null;
if (!$po) {
    header("Location: purchase_orders.php");
    exit;
}
$e    = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
$peso = fn($n) => '₱' . number_format((float)$n, 2);
$label = sx_po_statuses()[$po['status']][0] ?? $po['status'];
$stamp = ['draft' => 'Draft', 'received' => 'Received', 'cancelled' => 'Cancelled'][$po['status']] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase order <?= $e($po['po_number']) ?> · SweepXpress</title>
<?php require __DIR__ . '/../includes/theme_init.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/sweepxpress/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/sweepxpress/assets/brand/favicon-32.png">
    <?php sx_document_css(); ?>
    <style>
    .doc-stamp.muted { border-color: #94a3b8; color: #94a3b8; }
    .doc-stamp.bad { border-color: #ef4444; color: #ef4444; }
    .doc-sign { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sx-space-6); margin-top: var(--sx-space-8); font-size: 0.8rem; color: #64748b; }
    .doc-sign div { padding-top: var(--sx-space-2); border-top: 1px solid #94a3b8; }
    </style>
</head>
<body>
<div class="doc-wrap">
    <div class="doc-bar">
        <a href="purchase_order.php?id=<?= (int)$po['id'] ?>" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-solid fa-arrow-left"></i> Back to order</a>
        <div>
            <button type="button" class="sx-btn sx-btn-primary sx-btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
        </div>
    </div>

    <article class="doc">
        <?php if ($stamp): ?>
            <div class="doc-stamp<?= $po['status'] === 'draft' ? ' muted' : ($po['status'] === 'cancelled' ? ' bad' : '') ?>" aria-hidden="true"><?= $e($stamp) ?></div>
        <?php endif; ?>

        <header class="doc-head">
            <div class="doc-brand">
                <img src="/sweepxpress/assets/brand/logo-256.png" alt="">
                <div>
                    <strong>SweepXpress</strong>
                    <span>Cleaning supplies for homes &amp; businesses</span>
                </div>
            </div>
            <div class="doc-type">
                <h1>Purchase order</h1>
                <div>No. <b><?= $e($po['po_number']) ?></b></div>
                <div>Date <b><?= date('M j, Y', strtotime($po['sent_at'] ?: $po['created_at'])) ?></b></div>
            </div>
        </header>

        <section class="doc-parties">
            <div>
                <h2>Supplier</h2>
                <p>
                    <strong><?= $e($po['supplier_name']) ?></strong>
                    <?php if ($po['contact_person']): ?><br>Attn: <?= $e($po['contact_person']) ?><?php endif; ?>
                    <?php if ($po['supplier_address']): ?><br><?= $e($po['supplier_address']) ?><?php endif; ?>
                    <?php if ($po['supplier_email']): ?><br><?= $e($po['supplier_email']) ?><?php endif; ?>
                    <?php if ($po['supplier_phone']): ?><br><?= $e($po['supplier_phone']) ?><?php endif; ?>
                </p>
            </div>
            <div>
                <h2>Deliver to</h2>
                <p><strong>SweepXpress — <?= $e($po['location_name'] ?: 'Main Warehouse') ?></strong></p>
            </div>
            <div>
                <h2>Details</h2>
                <dl>
                    <dt>Expected</dt><dd><?= $po['expected_date'] ? date('M j, Y', strtotime($po['expected_date'])) : '—' ?></dd>
                    <dt>Terms</dt><dd><?= $e($po['payment_terms'] ?: '—') ?></dd>
                    <dt>Status</dt><dd><?= $e($label) ?></dd>
                </dl>
            </div>
        </section>

        <table class="doc-table">
            <thead>
                <tr>
                    <th class="idx hide-sm">#</th>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num hide-sm">Unit cost</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($po['items'] as $i => $it): ?>
                    <tr>
                        <td class="idx hide-sm"><?= $i + 1 ?></td>
                        <td><?= $e($it['name']) ?><?= $it['sku'] ? '<br><small style="color:#64748b">' . $e($it['sku']) . '</small>' : '' ?></td>
                        <td class="num"><?= number_format((int)$it['qty_ordered']) ?></td>
                        <td class="num hide-sm"><?= $peso($it['unit_cost']) ?></td>
                        <td class="num"><?= $peso($it['qty_ordered'] * $it['unit_cost']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="doc-totals">
            <table>
                <tr class="grand"><td>Total</td><td><?= $peso($po['total']) ?></td></tr>
            </table>
        </div>

        <?php if (trim((string)$po['notes']) !== ''): ?>
            <div class="doc-notes">
                <h2>Notes</h2>
                <pre><?= $e($po['notes']) ?></pre>
            </div>
        <?php endif; ?>

        <div class="doc-sign">
            <div>Prepared by<?= $po['created_by_name'] ? ': ' . $e($po['created_by_name']) : '' ?></div>
            <div>Received by (supplier)</div>
        </div>

        <footer class="doc-foot">
            Please confirm this order and the delivery date. Quote <?= $e($po['po_number']) ?> on your delivery receipt and invoice.
        </footer>
    </article>
</div>
</body>
</html>
