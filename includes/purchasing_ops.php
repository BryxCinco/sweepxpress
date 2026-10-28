<?php
// includes/purchasing_ops.php — restock purchase orders (RPO): bibili TAYO sa supplier.
// Iba ito sa "Pending PO" ng business customer (benta iyon, nasa orders table).
// Daloy: draft → sent → partial → received; draft/sent (wala pang natanggap) → cancelled.
// Ang pagtanggap ay dumadaan sa sx_stock_apply() (stock_ops.php), kaya iisa pa rin ang paraan ng pagbago ng stock.

require_once __DIR__ . '/stock_ops.php';

if (!function_exists('sx_po_statuses')) {
    define('SX_PO_MAX_COST', 10000000); // bawat unit, para sa typo na sobrang laki

    // status → [label, pill class] (gamit ang sx-pill ng orders)
    function sx_po_statuses(): array
    {
        return [
            'draft'     => ['Draft', 'pending'],
            'sent'      => ['Sent', 'preparing'],
            'partial'   => ['Partly received', 'shipped'],
            'received'  => ['Received', 'completed'],
            'cancelled' => ['Cancelled', 'cancelled'],
        ];
    }

    function sx_po_status_pill(string $status): string
    {
        [$label, $pill] = sx_po_statuses()[$status] ?? [ucfirst($status), 'pending'];
        return '<span class="sx-pill sx-pill-' . $pill . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    // RPO-2026-0007 (galing sa id, kaya laging unique)
    function sx_po_number(int $id, ?string $date = null): string
    {
        return sprintf('RPO-%s-%04d', date('Y', $date ? strtotime($date) : time()), $id);
    }

    /**
     * Linisin ang mga linya: [['product_id'=>, 'qty'=>, 'unit_cost'=>], ...]
     * Pinagsasama ang dobleng product; nilalaktawan ang blangkong row.
     * Nagbabalik ng [product_id => ['qty' => int, 'unit_cost' => float]].
     */
    function sx_po_clean_lines(PDO $pdo, array $lines): array
    {
        $clean = [];
        foreach ($lines as $l) {
            $pid  = (int)($l['product_id'] ?? 0);
            $qty  = (int)($l['qty'] ?? 0);
            $cost = trim((string)($l['unit_cost'] ?? ''));
            if (!$pid && !$qty) continue;
            if (!$pid) throw new RuntimeException('Choose a product for every line.');
            if ($qty < 1 || $qty > SX_STOCK_MAX_QTY) {
                throw new RuntimeException('Quantity must be between 1 and ' . number_format(SX_STOCK_MAX_QTY) . '.');
            }
            if ($cost === '' || !is_numeric($cost) || (float)$cost < 0 || (float)$cost > SX_PO_MAX_COST) {
                throw new RuntimeException('Enter a valid unit cost for every line.');
            }
            if (isset($clean[$pid])) {
                $clean[$pid]['qty'] += $qty;
                if ($clean[$pid]['qty'] > SX_STOCK_MAX_QTY) throw new RuntimeException('Quantity is too large.');
            } else {
                $clean[$pid] = ['qty' => $qty, 'unit_cost' => round((float)$cost, 2)];
            }
        }
        if (!$clean) throw new RuntimeException('Add at least one product.');

        $in = implode(',', array_fill(0, count($clean), '?'));
        $found = $pdo->prepare("SELECT COUNT(*) FROM products WHERE id IN ($in)");
        $found->execute(array_keys($clean));
        if ((int)$found->fetchColumn() !== count($clean)) throw new RuntimeException('One of the products no longer exists.');
        return $clean;
    }

    // Supplier na aktibo (para sa paggawa at pagpapadala ng PO)
    function sx_po_active_supplier(PDO $pdo, int $supplierId): array
    {
        $s = $pdo->prepare("SELECT id, name, lead_time_days, is_active FROM suppliers WHERE id = ?");
        $s->execute([$supplierId]);
        $supplier = $s->fetch(PDO::FETCH_ASSOC);
        if (!$supplier) throw new RuntimeException('Choose a supplier.');
        if (!(int)$supplier['is_active']) throw new RuntimeException("{$supplier['name']} is inactive. Reactivate the supplier first.");
        return $supplier;
    }

    function sx_po_expected_date(?string $date, int $leadDays): string
    {
        $date = trim((string)$date);
        if ($date === '') return date('Y-m-d', strtotime("+{$leadDays} days"));
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) throw new RuntimeException('Enter a valid expected date.');
        return $date;
    }

    // Isulat ulit ang mga linya ng draft at ang total
    function sx_po_write_lines(PDO $pdo, int $poId, array $clean): void
    {
        $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id = ?")->execute([$poId]);
        $ins = $pdo->prepare("INSERT INTO purchase_order_items (po_id, product_id, qty_ordered, unit_cost) VALUES (?, ?, ?, ?)");
        $total = 0.0;
        foreach ($clean as $pid => $l) {
            $ins->execute([$poId, $pid, $l['qty'], $l['unit_cost']]);
            $total += $l['qty'] * $l['unit_cost'];
        }
        $pdo->prepare("UPDATE purchase_orders SET total = ? WHERE id = ?")->execute([round($total, 2), $poId]);
    }

    // Kunin ang PO (naka-lock) at tiyaking nasa isa sa $allowed na status
    function sx_po_lock(PDO $pdo, int $poId, array $allowed): array
    {
        $s = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE");
        $s->execute([$poId]);
        $po = $s->fetch(PDO::FETCH_ASSOC);
        if (!$po) throw new RuntimeException('Purchase order not found.');
        if (!in_array($po['status'], $allowed, true)) {
            $label = sx_po_statuses()[$po['status']][0] ?? $po['status'];
            throw new RuntimeException("{$po['po_number']} is {$label} — that action is no longer available.");
        }
        return $po;
    }

    // Patakbuhin ang $fn sa loob ng isang transaction
    function sx_po_tx(PDO $pdo, callable $fn)
    {
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Gumawa ng draft. $meta: expected_date ('' = ngayon + lead time ng supplier), notes, location_id.
     * Nagbabalik ng bagong PO id.
     */
    function sx_po_create(PDO $pdo, int $supplierId, array $lines, array $meta, int $userId, string $userName): int
    {
        return sx_po_tx($pdo, function () use ($pdo, $supplierId, $lines, $meta, $userId, $userName) {
            $supplier = sx_po_active_supplier($pdo, $supplierId);
            $clean    = sx_po_clean_lines($pdo, $lines);
            $expected = sx_po_expected_date($meta['expected_date'] ?? '', (int)$supplier['lead_time_days']);

            $pdo->prepare("
                INSERT INTO purchase_orders (supplier_id, location_id, status, expected_date, notes, created_by, created_by_name)
                VALUES (?, ?, 'draft', ?, ?, ?, ?)
            ")->execute([
                $supplierId, (int)($meta['location_id'] ?? 1) ?: 1, $expected,
                trim((string)($meta['notes'] ?? '')) ?: null, $userId ?: null, $userName,
            ]);
            $poId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE purchase_orders SET po_number = ? WHERE id = ?")->execute([sx_po_number($poId), $poId]);
            sx_po_write_lines($pdo, $poId, $clean);
            return $poId;
        });
    }

    // Baguhin ang draft (supplier, petsa, notes, mga linya)
    function sx_po_update_draft(PDO $pdo, int $poId, int $supplierId, array $lines, array $meta): void
    {
        sx_po_tx($pdo, function () use ($pdo, $poId, $supplierId, $lines, $meta) {
            sx_po_lock($pdo, $poId, ['draft']);
            $supplier = sx_po_active_supplier($pdo, $supplierId);
            $clean    = sx_po_clean_lines($pdo, $lines);
            $expected = sx_po_expected_date($meta['expected_date'] ?? '', (int)$supplier['lead_time_days']);

            $pdo->prepare("UPDATE purchase_orders SET supplier_id = ?, expected_date = ?, notes = ? WHERE id = ?")
                ->execute([$supplierId, $expected, trim((string)($meta['notes'] ?? '')) ?: null, $poId]);
            sx_po_write_lines($pdo, $poId, $clean);
        });
    }

    // Draft lang ang puwedeng burahin (walang stock na nagalaw)
    function sx_po_delete_draft(PDO $pdo, int $poId): void
    {
        sx_po_tx($pdo, function () use ($pdo, $poId) {
            sx_po_lock($pdo, $poId, ['draft']);
            $pdo->prepare("DELETE FROM purchase_orders WHERE id = ?")->execute([$poId]);
        });
    }

    // Draft → Sent (naka-lock na ang mga linya)
    function sx_po_send(PDO $pdo, int $poId): void
    {
        sx_po_tx($pdo, function () use ($pdo, $poId) {
            $po = sx_po_lock($pdo, $poId, ['draft']);
            sx_po_active_supplier($pdo, (int)$po['supplier_id']);
            $n = $pdo->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE po_id = ?");
            $n->execute([$poId]);
            if (!(int)$n->fetchColumn()) throw new RuntimeException('Add at least one product before sending.');
            $pdo->prepare("UPDATE purchase_orders SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$poId]);
        });
    }

    /**
     * Tanggapin ang dumating: $qtys = [product_id => dami ngayong delivery]. Isang transaction:
     *  - hindi puwedeng lumampas sa kulang pa (kaya hindi nadodoble kahit ma-double submit)
     *  - inventory + stock_movements IN (ref_type 'po') sa location ng PO
     *  - products.cost_price = unit_cost ng natanggap (pinakahuling presyo)
     *  - status: received kapag kumpleto na lahat, kung hindi partial
     * Nagbabalik ng ['units' => kabuuang natanggap ngayon, 'status' => bagong status].
     */
    function sx_po_receive(PDO $pdo, int $poId, array $qtys, string $userName): array
    {
        return sx_po_tx($pdo, function () use ($pdo, $poId, $qtys, $userName) {
            $po = sx_po_lock($pdo, $poId, ['sent', 'partial']);

            $s = $pdo->prepare("
                SELECT poi.id, poi.product_id, poi.qty_ordered, poi.qty_received, poi.unit_cost, p.name
                FROM purchase_order_items poi
                JOIN products p ON p.id = poi.product_id
                WHERE poi.po_id = ?
                FOR UPDATE
            ");
            $s->execute([$poId]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);

            $rows = [];
            $updItem = $pdo->prepare("UPDATE purchase_order_items SET qty_received = qty_received + ? WHERE id = ?");
            $updCost = $pdo->prepare("UPDATE products SET cost_price = ? WHERE id = ?");
            $complete = true;
            foreach ($items as $it) {
                $remaining = (int)$it['qty_ordered'] - (int)$it['qty_received'];
                $qty = (int)($qtys[$it['product_id']] ?? 0);
                if ($qty < 0) throw new RuntimeException('Quantities cannot be negative.');
                if ($qty > $remaining) {
                    throw new RuntimeException("Only {$remaining} left to receive for “{$it['name']}”.");
                }
                if ($qty > 0) {
                    $updItem->execute([$qty, $it['id']]);
                    $updCost->execute([$it['unit_cost'], $it['product_id']]);
                    $rows[] = [
                        'product_id'  => $it['product_id'],
                        'location_id' => $po['location_id'],
                        'quantity'    => $qty,
                        'remark'      => "Received on {$po['po_number']}",
                        'ref_type'    => 'po',
                        'ref_id'      => $poId,
                    ];
                }
                if ($remaining - $qty > 0) $complete = false;
            }
            if (!$rows) throw new RuntimeException('Enter the quantity received for at least one product.');

            sx_stock_apply($pdo, 'in', $rows, $userName); // sumasama sa transaction na ito

            $status = $complete ? 'received' : 'partial';
            $pdo->prepare("UPDATE purchase_orders SET status = ?, received_at = IF(? = 'received', NOW(), received_at) WHERE id = ?")
                ->execute([$status, $status, $poId]);
            return ['units' => array_sum(array_column($rows, 'quantity')), 'status' => $status];
        });
    }

    // Partial → Received kahit kulang (hindi na darating ang natitira)
    function sx_po_close_short(PDO $pdo, int $poId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('Give a reason for closing this order short.');
        sx_po_tx($pdo, function () use ($pdo, $poId, $reason) {
            sx_po_lock($pdo, $poId, ['partial']);
            $pdo->prepare("UPDATE purchase_orders SET status = 'received', received_at = NOW(), status_note = ? WHERE id = ?")
                ->execute([mb_substr('Closed short: ' . $reason, 0, 255), $poId]);
        });
    }

    // Draft/Sent → Cancelled (hangga't wala pang natatanggap)
    function sx_po_cancel(PDO $pdo, int $poId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new RuntimeException('Give a reason for cancelling.');
        sx_po_tx($pdo, function () use ($pdo, $poId, $reason) {
            sx_po_lock($pdo, $poId, ['draft', 'sent']);
            $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', cancelled_at = NOW(), status_note = ? WHERE id = ?")
                ->execute([mb_substr($reason, 0, 255), $poId]);
        });
    }

    // PO + supplier + mga linya (para sa detail page at print)
    function sx_po_get(PDO $pdo, int $poId): ?array
    {
        $s = $pdo->prepare("
            SELECT po.*, s.name AS supplier_name, s.contact_person, s.email AS supplier_email, s.phone AS supplier_phone,
                   s.address AS supplier_address, s.payment_terms, l.name AS location_name
            FROM purchase_orders po
            JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN locations l ON l.id = po.location_id
            WHERE po.id = ?
        ");
        $s->execute([$poId]);
        $po = $s->fetch(PDO::FETCH_ASSOC);
        if (!$po) return null;

        $i = $pdo->prepare("
            SELECT poi.*, p.name, p.sku, p.image_path, (poi.qty_ordered - poi.qty_received) AS qty_remaining
            FROM purchase_order_items poi
            JOIN products p ON p.id = poi.product_id
            WHERE poi.po_id = ?
            ORDER BY p.name
        ");
        $i->execute([$poId]);
        $po['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
        return $po;
    }

    // POST arrays (product_id[], qty[], unit_cost[]) → mga linya para sa sx_po_clean_lines
    function sx_po_lines_from_post(): array
    {
        $lines = [];
        foreach ((array)($_POST['product_id'] ?? []) as $i => $pid) {
            $lines[] = ['product_id' => $pid, 'qty' => $_POST['qty'][$i] ?? 0, 'unit_cost' => $_POST['unit_cost'][$i] ?? ''];
        }
        return $lines;
    }

    // Darating pa: [product_id => units] mula sa sent/partial na PO (hindi kasama ang draft)
    function sx_po_on_order(PDO $pdo): array
    {
        return $pdo->query("
            SELECT poi.product_id, SUM(poi.qty_ordered - poi.qty_received) AS qty
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.po_id
            WHERE po.status IN ('sent', 'partial')
            GROUP BY poi.product_id
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Ilang units ang dapat i-order (0 = hindi pa kailangan).
     * Kailangan kapag stock + darating pa ≤ reorder point. Dami: reorder_qty kung naka-set,
     * kung hindi, sapat para umabot sa 2× ng reorder point.
     */
    function sx_po_suggest_qty(int $stock, int $onOrder, int $reorderPoint, ?int $reorderQty): int
    {
        if ($stock + $onOrder > $reorderPoint) return 0;
        if ($reorderQty) return $reorderQty;
        return max(1, $reorderPoint * 2 - $stock - $onOrder);
    }
}
