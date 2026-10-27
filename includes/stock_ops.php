<?php
// includes/stock_ops.php — iisang logic at form para sa admin/stock_in.php, stock_out.php at stock_adjust.php.
// Bawat galaw: inventory (FOR UPDATE) + isang row sa stock_movements, lahat sa iisang transaction.

if (!function_exists('sx_stock_apply')) {
    define('SX_STOCK_MAX_QTY', 100000); // bawat row (dati walang limit — may 111,111,111 sa log)

    /**
     * $mode: 'in' | 'out' | 'adjust'
     * $rows: [['product_id'=>, 'location_id'=>, 'quantity'=>, 'remark'=>, 'direction'=>'add'|'deduct'], ...]
     * Nagbabalik ng bilang ng na-save na row; RuntimeException kapag may mali (walang na-save).
     */
    function sx_stock_apply(PDO $pdo, string $mode, array $rows, string $user, string $master = ''): int
    {
        if ($mode === 'adjust' && trim($master) === '') {
            throw new RuntimeException('A reason is required for adjustments.');
        }

        $type = ['in' => 'IN', 'out' => 'OUT', 'adjust' => 'ADJUST'][$mode];
        $sel = $pdo->prepare("
            SELECT i.id, i.quantity, p.name
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id AND i.location_id = ?
            WHERE p.id = ?
            FOR UPDATE
        ");
        $upd = $pdo->prepare("UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $ins = $pdo->prepare("INSERT INTO inventory (product_id, location_id, quantity, updated_at) VALUES (?, ?, ?, NOW())");
        $log = $pdo->prepare("
            INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $pdo->beginTransaction();
        try {
            $count = 0;
            foreach ($rows as $r) {
                $pid = (int)($r['product_id'] ?? 0);
                $lid = (int)($r['location_id'] ?? 0);
                $qty = (int)($r['quantity'] ?? 0);
                if (!$pid && !$qty) continue; // blangkong row
                if (!$pid || !$lid) throw new RuntimeException('Choose a product and location for every row.');
                if ($qty < 1 || $qty > SX_STOCK_MAX_QTY) {
                    throw new RuntimeException('Quantity must be between 1 and ' . number_format(SX_STOCK_MAX_QTY) . '.');
                }

                $sel->execute([$lid, $pid]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException("Product #{$pid} no longer exists.");

                $current = (int)($row['quantity'] ?? 0);
                $deduct  = $mode === 'out' || ($mode === 'adjust' && ($r['direction'] ?? 'add') === 'deduct');
                $new     = $deduct ? $current - $qty : $current + $qty;
                if ($new < 0) {
                    throw new RuntimeException("Not enough stock for “{$row['name']}” at that location. Available: {$current}, requested: {$qty}.");
                }

                if ($row['id']) $upd->execute([$new, $row['id']]);
                else            $ins->execute([$pid, $lid, $new]);

                $remark = trim((string)($r['remark'] ?? ''));
                if ($mode === 'adjust') {
                    $remark = ($deduct ? '[DEDUCT] ' : '[ADD] ') . trim($master) . ($remark !== '' ? " ({$remark})" : '');
                }
                $log->execute([$pid, $lid, $type, $qty, mb_substr($remark, 0, 255), $user, $user]);
                $count++;
            }
            if ($count === 0) throw new RuntimeException('Add at least one item.');
            $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Signed na pagbabago ng isang stock_movements row (+ pumasok, − lumabas)
    function sx_movement_delta(array $m): int
    {
        $q = (int)$m['quantity'];
        return match ($m['movement_type']) {
            'IN'    => $q,
            'OUT'   => -$q,
            default => str_contains((string)$m['remark'], '[DEDUCT]') ? -$q : (str_contains((string)$m['remark'], '[ADD]') ? $q : 0),
        };
    }

    // Badge ng uri ng galaw (product_history, inventory_full_history)
    function sx_movement_badge(array $m): string
    {
        $d = sx_movement_delta($m);
        [$cls, $label] = match ($m['movement_type']) {
            'IN'    => ['in', 'In'],
            'OUT'   => ['out', 'Out'],
            default => ['adjust', 'Adjust'],
        };
        return '<span class="mv-type ' . $cls . '">' . $label . '</span> <strong class="mv-qty ' . ($d < 0 ? 'neg' : 'pos') . '">'
            . ($d < 0 ? '−' : '+') . number_format(abs($d ?: (int)$m['quantity'])) . '</strong>';
    }

    // POST arrays → rows
    function sx_stock_rows_from_post(): array
    {
        $rows = [];
        foreach ((array)($_POST['product_id'] ?? []) as $i => $pid) {
            $rows[] = [
                'product_id'  => $pid,
                'location_id' => $_POST['location_id'][$i] ?? 0,
                'quantity'    => $_POST['quantity'][$i] ?? 0,
                'remark'      => $_POST['remark'][$i] ?? '',
                'direction'   => $_POST['direction'][$i] ?? 'add',
            ];
        }
        return $rows;
    }

    // Produkto + kasalukuyang stock (para sa dropdown at "on hand")
    function sx_stock_products(PDO $pdo): array
    {
        return $pdo->query("
            SELECT p.id, p.name, p.sku, COALESCE(SUM(i.quantity), 0) AS stock
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id
            GROUP BY p.id, p.name, p.sku
            ORDER BY p.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Buong page body ng isang stock form.
     * $cfg: mode, title, intro, submit, icon, remarkHint, products, locations, csrf, preselect, error, rows, master
     */
    function sx_stock_form(array $cfg): void
    {
        $e = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
        $mode = $cfg['mode'];
        $tabs = [
            'in'     => ['/sweepxpress/admin/stock_in.php', 'fa-arrow-down', 'Stock in'],
            'out'    => ['/sweepxpress/admin/stock_out.php', 'fa-arrow-up', 'Stock out'],
            'adjust' => ['/sweepxpress/admin/stock_adjust.php', 'fa-sliders', 'Adjust'],
        ];
        ?>
<div class="sk sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="/sweepxpress/admin/inventory.php">Inventory</a> <span aria-hidden="true">/</span> <span><?= $e($cfg['title']) ?></span>
    </nav>
    <div class="pf-head">
        <div>
            <h1 class="sx-heading-lg"><?= $e($cfg['title']) ?></h1>
            <p><?= $e($cfg['intro']) ?></p>
        </div>
        <nav class="sk-tabs" aria-label="Stock actions">
            <?php foreach ($tabs as $key => [$href, $icon, $label]): ?>
                <a href="<?= $href ?>" class="sx-chip<?= $key === $mode ? ' active' : '' ?>"><i class="fa-solid <?= $icon ?>"></i> <?= $label ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if (!empty($cfg['error'])): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= $e($cfg['error']) ?></div>
        </div>
    <?php endif; ?>

    <form method="post" class="sx-card sk-card" id="stockForm">
        <input type="hidden" name="csrf_token" value="<?= $e($cfg['csrf']) ?>">

        <?php if ($mode === 'adjust'): ?>
            <div class="sx-field">
                <label class="sx-label" for="master_remark">Reason for this adjustment</label>
                <input type="text" id="master_remark" name="master_remark" class="sx-input" required maxlength="150"
                       placeholder="e.g. Monthly stock count, damaged items" value="<?= $e($cfg['master'] ?? '') ?>">
            </div>
        <?php endif; ?>

        <div class="sk-rows" id="skRows"></div>
        <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" id="skAdd"><i class="fa-solid fa-plus"></i> Add another item</button>

        <div class="sk-foot">
            <span class="sx-hint" id="skSummary"></span>
            <div class="pf-actions" style="margin: 0">
                <a href="/sweepxpress/admin/inventory.php" class="sx-btn sx-btn-ghost">Cancel</a>
                <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid <?= $e($cfg['icon']) ?>"></i> <?= $e($cfg['submit']) ?></button>
            </div>
        </div>
    </form>
</div>

<template id="skRowTpl">
    <div class="sk-row">
        <div class="sx-field sk-product">
            <label class="sx-label">Product</label>
            <select name="product_id[]" class="sx-select" required>
                <option value="">Choose a product…</option>
                <?php foreach ($cfg['products'] as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-stock="<?= (int)$p['stock'] ?>"><?= $e($p['name']) ?><?= $p['sku'] ? ' · ' . $e($p['sku']) : '' ?></option>
                <?php endforeach; ?>
            </select>
            <span class="sx-hint sk-onhand"></span>
        </div>
        <div class="sx-field">
            <label class="sx-label">Location</label>
            <select name="location_id[]" class="sx-select" required>
                <?php foreach ($cfg['locations'] as $l): ?>
                    <option value="<?= (int)$l['id'] ?>"><?= $e($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($mode === 'adjust'): ?>
            <div class="sx-field">
                <label class="sx-label">Action</label>
                <select name="direction[]" class="sx-select">
                    <option value="add">Add</option>
                    <option value="deduct">Deduct</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="sx-field">
            <label class="sx-label">Quantity</label>
            <input type="number" name="quantity[]" class="sx-input" min="1" max="<?= SX_STOCK_MAX_QTY ?>" required inputmode="numeric">
        </div>
        <div class="sx-field">
            <label class="sx-label"><?= $mode === 'adjust' ? 'Note' : 'Remark' ?> <span class="sx-hint">(optional)</span></label>
            <input type="text" name="remark[]" class="sx-input" maxlength="100" placeholder="<?= $e($cfg['remarkHint']) ?>">
        </div>
        <button type="button" class="sk-remove" aria-label="Remove row"><i class="fa-solid fa-xmark"></i></button>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = document.getElementById('skRows');
    const tpl = document.getElementById('skRowTpl');
    const summary = document.getElementById('skSummary');
    const mode = <?= json_encode($mode) ?>;

    function refresh() {
        const all = rows.querySelectorAll('.sk-row');
        all.forEach((r) => { r.querySelector('.sk-remove').hidden = all.length === 1; });
        summary.textContent = all.length + ' item' + (all.length === 1 ? '' : 's');
    }

    // Ipakita ang stock na hawak; sa Stock out, limitahan ang quantity
    function showStock(row) {
        const opt = row.querySelector('select[name="product_id[]"]').selectedOptions[0];
        const hint = row.querySelector('.sk-onhand');
        const qty = row.querySelector('input[name="quantity[]"]');
        if (!opt || !opt.value) { hint.textContent = ''; return; }
        const stock = Number(opt.dataset.stock);
        hint.textContent = stock.toLocaleString() + ' on hand (all locations)';
        if (mode === 'out') qty.max = Math.max(stock, 1);
    }

    // data: {product_id, location_id, quantity, remark, direction} — para maibalik ang tinype kapag may error
    function addRow(data = {}) {
        const row = tpl.content.firstElementChild.cloneNode(true);
        const product = row.querySelector('select[name="product_id[]"]');
        const set = (name, v) => { const el = row.querySelector(`[name="${name}[]"]`); if (el && v !== undefined && v !== null) el.value = v; };
        set('product_id', data.product_id);
        set('location_id', data.location_id);
        set('quantity', data.quantity);
        set('remark', data.remark);
        set('direction', data.direction);
        product.addEventListener('change', () => showStock(row));
        row.querySelector('.sk-remove').addEventListener('click', () => { row.remove(); refresh(); });
        rows.appendChild(row);
        showStock(row);
        refresh();
        return row;
    }

    document.getElementById('skAdd').addEventListener('click', () => addRow().querySelector('select').focus());
    const initial = <?= json_encode(array_values($cfg['rows'] ?? [])) ?>;
    if (initial.length) initial.forEach((r) => addRow(r));
    else addRow({ product_id: <?= (int)($cfg['preselect'] ?? 0) ?: 'null' ?> });

    // Iwas dobleng submit
    document.getElementById('stockForm').addEventListener('submit', function () {
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    });
});
</script>
        <?php
    }
}
