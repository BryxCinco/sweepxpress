<?php
// includes/order_document.php — iisang printable na dokumento para sa Invoice.php at customers/UserReceipt.php
// (at ang CSS nito, sx_document_css(), para sa restock PO print).
// Hindi dumadaan sa header.php (walang navbar) para malinis kapag pini-print o sine-save bilang PDF.

if (!function_exists('sx_order_document')) {
    // Style ng printable na papel (puti, A4). Ginagamit din ng admin/purchase_order_print.php.
    function sx_document_css(): void
    {
        ?>
    <style>
    body { background: var(--sx-surface-alt); }
    .doc-wrap { max-width: 860px; margin: 0 auto; padding: var(--sx-space-5) var(--sx-space-4) var(--sx-space-8); }
    .doc-bar { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--sx-space-2); margin-bottom: var(--sx-space-4); }
    .doc-bar div { display: flex; gap: var(--sx-space-2); }

    .doc {
        position: relative;
        overflow: hidden;
        padding: clamp(24px, 5vw, 48px);
        border-radius: var(--sx-radius-lg);
        background: #fff;
        color: #0f172a;
        box-shadow: var(--sx-shadow-md);
    }
    .doc::before {                        /* brand na linya sa itaas */
        content: "";
        position: absolute;
        inset: 0 0 auto;
        height: 6px;
        background: var(--sx-gradient-brand);
    }
    .doc-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--sx-space-5); padding-bottom: var(--sx-space-5); border-bottom: 1px solid #e2e8f0; }
    .doc-brand { display: flex; align-items: center; gap: var(--sx-space-3); }
    .doc-brand img { width: 52px; height: 52px; border-radius: 12px; }
    .doc-brand strong { display: block; font-size: 1.35rem; font-weight: 800; letter-spacing: -0.02em; color: #1e3a8a; }
    .doc-brand span { font-size: 0.8rem; color: #64748b; }
    .doc-type { text-align: right; }
    .doc-type h1 { margin: 0; font-size: 1.6rem; font-weight: 800; letter-spacing: -0.02em; text-transform: uppercase; color: #0f172a; }
    .doc-type div { font-size: 0.85rem; color: #64748b; }
    .doc-type b { color: #0f172a; }

    .doc-parties { display: grid; gap: var(--sx-space-5); padding: var(--sx-space-5) 0; }
    .doc-parties h2 { margin: 0 0 6px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; }
    .doc-parties p { margin: 0; font-size: 0.9rem; line-height: 1.55; overflow-wrap: anywhere; }
    .doc-parties dl { display: grid; grid-template-columns: auto 1fr; gap: 4px var(--sx-space-3); margin: 0; font-size: 0.9rem; }
    .doc-parties dt { color: #64748b; }
    .doc-parties dd { margin: 0; font-weight: 600; text-align: right; }

    .doc-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .doc-table th { padding: 10px 12px; background: #f1f5f9; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; text-align: left; text-transform: uppercase; color: #475569; }
    .doc-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .doc-table .num { text-align: right; white-space: nowrap; }
    .doc-table .idx { width: 36px; color: #94a3b8; }

    .doc-totals { display: grid; justify-content: end; margin-top: var(--sx-space-4); }
    .doc-totals table { min-width: 280px; font-size: 0.9rem; }
    .doc-totals td { padding: 5px 0; }
    .doc-totals td:last-child { padding-left: var(--sx-space-6); text-align: right; font-weight: 600; }
    .doc-totals .grand td { padding-top: 12px; border-top: 2px solid #0f172a; font-size: 1.15rem; font-weight: 800; }
    .doc-totals .grand td:last-child { color: #1e3a8a; }

    .doc-notes { margin-top: var(--sx-space-6); padding: var(--sx-space-4); border-radius: var(--sx-radius-md); background: #f8fafc; font-size: 0.8rem; color: #475569; }
    .doc-notes h2 { margin: 0 0 6px; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; }
    .doc-notes pre { margin: 0; font-family: inherit; white-space: pre-wrap; }
    .doc-foot { margin-top: var(--sx-space-6); padding-top: var(--sx-space-4); border-top: 1px dashed #cbd5e1; font-size: 0.8rem; text-align: center; color: #64748b; }

    .doc-stamp {
        position: absolute;
        top: 120px;
        right: 40px;
        padding: 6px 18px;
        border: 3px solid #10b981;
        border-radius: 8px;
        color: #10b981;
        font-size: 1.4rem;
        font-weight: 800;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        opacity: 0.85;
        transform: rotate(-12deg);
        pointer-events: none;
    }

    @media (min-width: 640px) {
        .doc-parties { grid-template-columns: 1fr 1fr 1.1fr; }
    }
    @media (max-width: 639.98px) {
        .doc-type { text-align: left; }
        .doc-table .hide-sm { display: none; }
        .doc-stamp { top: 96px; right: 16px; font-size: 1.1rem; }
    }

    @page { size: A4; margin: 14mm; }
    @media print {
        body { background: #fff; }
        .doc-bar { display: none; }
        .doc-wrap { max-width: none; padding: 0; }
        .doc { padding: 0; box-shadow: none; border-radius: 0; }
        .doc::before { display: none; }
        .doc-parties { grid-template-columns: 1fr 1fr 1.1fr; }
        .doc-table .hide-sm { display: table-cell; }
        .doc-table th, .doc-notes { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .doc-table tr { break-inside: avoid; }
    }
    </style>
        <?php
    }

    /**
     * $doc keys:
     *   title      — "Official receipt", "Invoice", "Order summary"
     *   order      — row ng orders (kasama ang email, delivery_date kung meron)
     *   items      — [quantity, price, name]
     *   stamp      — teksto ng stamp (hal. "Paid") o null
     *   due        — due date (para sa PO na may Net terms) o null
     *   backHref / backLabel
     */
    function sx_order_document(array $doc): void
    {
        $e     = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
        $peso  = fn($n) => '₱' . number_format((float)$n, 2);
        $order = $doc['order'];
        $items = $doc['items'];

        $subtotal = 0;
        foreach ($items as $it) $subtotal += (float)$it['price'] * (int)$it['quantity'];
        $deliveryFee = max(0, (float)$order['total'] - $subtotal);

        $statusLabels = [
            'Pending PO' => 'PO under review', 'shipped' => 'Preparing',
            'cancellation_requested' => 'Cancel requested',
        ];
        $status  = $statusLabels[$order['status']] ?? ucfirst(str_replace('_', ' ', $order['status']));
        $payment = $order['payment_terms']
            ?: ($order['payment_method'] === 'COD' ? 'Cash on delivery' : $order['payment_method']);
        $docNo   = strtoupper(substr($doc['title'], 0, 3)) . '-' . date('Y', strtotime($order['created_at'])) . '-' . str_pad((string)$order['id'], 5, '0', STR_PAD_LEFT);
        $isPO    = ($order['order_type'] ?? '') === 'B2B' || $order['status'] === 'Pending PO';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($doc['title']) ?> #<?= (int)$order['id'] ?> · SweepXpress</title>
<?php require __DIR__ . '/theme_init.php'; // ang papel ay laging puti; ang paligid lang ang dumidilim ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="/sweepxpress/assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="/sweepxpress/assets/brand/favicon-32.png">
    <?php sx_document_css(); ?>
</head>
<body>
<div class="doc-wrap">
    <div class="doc-bar">
        <a href="<?= $e($doc['backHref']) ?>" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-solid fa-arrow-left"></i> <?= $e($doc['backLabel']) ?></a>
        <div>
            <button type="button" class="sx-btn sx-btn-primary sx-btn-sm" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print / Save PDF
            </button>
        </div>
    </div>

    <article class="doc">
        <?php if (!empty($doc['stamp'])): ?>
            <div class="doc-stamp" aria-hidden="true"><?= $e($doc['stamp']) ?></div>
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
                <h1><?= $e($doc['title']) ?></h1>
                <div>No. <b><?= $e($docNo) ?></b></div>
                <div>Order <b>#<?= (int)$order['id'] ?></b> · <?= date('M j, Y', strtotime($order['created_at'])) ?></div>
            </div>
        </header>

        <section class="doc-parties">
            <div>
                <h2>Billed to</h2>
                <p>
                    <strong><?= $e($order['customer_name'] ?: 'Customer') ?></strong><br>
                    <?= $e($order['email'] ?? '') ?>
                    <?php if (!empty($order['phone_number'])): ?><br><?= $e($order['phone_number']) ?><?php endif; ?>
                </p>
            </div>
            <div>
                <h2>Deliver to</h2>
                <p><?= nl2br($e($order['address'] ?: 'Not specified')) ?></p>
            </div>
            <div>
                <h2>Details</h2>
                <dl>
                    <dt>Payment</dt><dd><?= $e($payment) ?></dd>
                    <?php if (!empty($doc['due'])): ?>
                        <dt>Due date</dt><dd><?= $e($doc['due']) ?></dd>
                    <?php endif; ?>
                    <dt>Status</dt><dd><?= $e($status) ?></dd>
                    <?php if (!empty($order['delivery_date'])): ?>
                        <dt>Delivery</dt><dd><?= date('M j, Y', strtotime($order['delivery_date'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($isPO): ?>
                        <dt>Type</dt><dd>Purchase order</dd>
                    <?php endif; ?>
                </dl>
            </div>
        </section>

        <table class="doc-table">
            <thead>
                <tr>
                    <th class="idx hide-sm">#</th>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num hide-sm">Unit price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td class="idx hide-sm"><?= $i + 1 ?></td>
                        <td><?= $e($it['name'] ?? 'Product no longer available') ?></td>
                        <td class="num"><?= (int)$it['quantity'] ?></td>
                        <td class="num hide-sm"><?= $peso($it['price']) ?></td>
                        <td class="num"><?= $peso((float)$it['price'] * (int)$it['quantity']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <tr><td colspan="5" style="text-align:center; color:#64748b">No item details for this order.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="doc-totals">
            <table>
                <tr><td>Subtotal</td><td><?= $peso($subtotal) ?></td></tr>
                <tr><td>Delivery fee</td><td><?= $deliveryFee > 0 ? $peso($deliveryFee) : 'Free' ?></td></tr>
                <tr class="grand"><td>Total</td><td><?= $peso($order['total']) ?></td></tr>
            </table>
        </div>

        <?php if ($isPO && trim($order['notes'] ?? '') !== ''): ?>
            <div class="doc-notes">
                <h2>Purchase order details</h2>
                <pre><?= $e($order['notes']) ?></pre>
            </div>
        <?php endif; ?>

        <footer class="doc-foot">
            Thank you for ordering with SweepXpress. Please keep this document for your records.
            <br>This is a system-generated document; no signature is required.
        </footer>
    </article>
</div>
</body>
</html>
        <?php
    }
}
