<?php
// includes/order_ops.php — iisang paraan ng pagpalit ng status ng order (admin).
// Kasama ang deliveries row at ang pagbalik/pagbawas ng stock kapag na-cancel o nabuksan ulit ang order.

if (!function_exists('sx_order_statuses')) {
    // status → [label, pill class] (kapareho ng customer pages)
    function sx_order_statuses(): array
    {
        return [
            'Pending PO'             => ['PO review', 'pending'],
            'pending'                => ['Pending', 'pending'],
            'preparing'              => ['Preparing', 'preparing'],
            'shipped'                => ['Shipped', 'shipped'],
            'delivered'              => ['Delivered', 'delivered'],
            'completed'              => ['Completed', 'completed'],
            'cancellation_requested' => ['Cancel requested', 'cancelled'],
            'cancelled'              => ['Cancelled', 'cancelled'],
        ];
    }

    function sx_order_status_pill(string $status): string
    {
        [$label, $pill] = sx_order_statuses()[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'pending'];
        return '<span class="sx-pill sx-pill-' . $pill . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    // Nakabawas pa ba sa stock ang order sa status na ito? (cancelled lang ang hindi)
    function sx_order_holds_stock(string $status): bool
    {
        return $status !== 'cancelled';
    }

    /**
     * Palitan ang status ng order. Isang transaction:
     *  - orders.status (+ $extra na columns: admin_note, cancellation_reason)
     *  - deliveries row (gagawa kung wala pa)
     *  - stock: ibinabalik kapag na-cancel; binabawas ulit kapag nabuksan mula sa cancelled
     * $deliveryDate: null = huwag galawin ang petsa, '' = burahin, 'Y-m-d' = itakda.
     * $extra: admin_note / cancellation_reason (columns), require_status (hal. 'cancellation_requested').
     * Nagbabalik ng ['old' => , 'new' => , 'stock' => bilang ng units na gumalaw].
     * RuntimeException kapag hindi puwede (walang nabago).
     */
    function sx_update_order_status(PDO $pdo, int $orderId, string $newStatus, ?string $deliveryDate, string $user, array $extra = []): array
    {
        $allowed = ['Pending PO', 'pending', 'preparing', 'delivered', 'completed', 'cancelled', 'cancellation_requested'];
        if ($newStatus === 'shipped') $newStatus = 'preparing'; // gaya ng dati
        if (!in_array($newStatus, $allowed, true)) throw new RuntimeException('Choose a valid status.');
        if ($deliveryDate !== null && $deliveryDate !== '' && !DateTime::createFromFormat('Y-m-d', $deliveryDate)) {
            throw new RuntimeException('Enter a valid delivery date.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, status, customer_name, address FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new RuntimeException('Order not found.');

            $old = $order['status'];
            if (isset($extra['require_status']) && $old !== $extra['require_status']) {
                throw new RuntimeException('This order was already updated (status is now “' . $old . '”).');
            }
            if ($old === 'completed' && $newStatus !== 'completed') {
                throw new RuntimeException('Completed orders are locked.');
            }

            // ---- Stock ----
            $moved = 0;
            $wasHolding = sx_order_holds_stock($old);
            $willHold   = sx_order_holds_stock($newStatus);
            if ($wasHolding !== $willHold) {
                $items = $pdo->prepare("SELECT product_id, SUM(quantity) AS qty FROM order_items WHERE order_id = ? GROUP BY product_id");
                $items->execute([$orderId]);
                $lock = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = 1 FOR UPDATE");
                $upd  = $pdo->prepare("UPDATE inventory SET quantity = ?, updated_at = NOW() WHERE id = ?");
                $ins  = $pdo->prepare("INSERT INTO inventory (product_id, location_id, quantity, updated_at) VALUES (?, 1, ?, NOW())");
                $log  = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at) VALUES (?, 1, ?, ?, ?, ?, ?, NOW())");
                $name = $pdo->prepare("SELECT name FROM products WHERE id = ?");

                foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
                    $qty = (int)$it['qty'];
                    $lock->execute([$it['product_id']]);
                    $inv = $lock->fetch(PDO::FETCH_ASSOC);
                    $current = $inv ? (int)$inv['quantity'] : 0;

                    if (!$willHold) {
                        // Na-cancel → ibalik sa Main Warehouse (dito rin binawas ng checkout)
                        $new = $current + $qty;
                        $type = 'IN';
                        $remark = "Order #{$orderId} cancelled - stock returned";
                    } else {
                        // Binuksan ulit mula sa cancelled → bawasan ulit
                        if ($current < $qty) {
                            $name->execute([$it['product_id']]);
                            throw new RuntimeException("Not enough stock to reopen this order: “" . ($name->fetchColumn() ?: 'product') . "” has {$current}, needs {$qty}.");
                        }
                        $new = $current - $qty;
                        $type = 'OUT';
                        $remark = "Order #{$orderId} reopened";
                    }

                    if ($inv) $upd->execute([$new, $inv['id']]);
                    else      $ins->execute([$it['product_id'], $new]);
                    $log->execute([$it['product_id'], $type, $qty, $remark, $user, $user]);
                    $moved += $qty;
                }
            }

            // ---- Delivery row (enum: pending, preparing, delivered, completed, cancelled) ----
            $deliveryStatus = match ($newStatus) {
                'Pending PO', 'cancellation_requested' => 'pending',
                default => $newStatus,
            };
            $d = $pdo->prepare("SELECT id FROM deliveries WHERE order_id = ? ORDER BY id LIMIT 1");
            $d->execute([$orderId]);
            if ($deliveryId = $d->fetchColumn()) {
                if ($deliveryDate === null) {
                    $pdo->prepare("UPDATE deliveries SET status = ? WHERE id = ?")->execute([$deliveryStatus, $deliveryId]);
                } else {
                    $pdo->prepare("UPDATE deliveries SET status = ?, delivery_date = ? WHERE id = ?")
                        ->execute([$deliveryStatus, $deliveryDate ?: null, $deliveryId]);
                }
            } else {
                $pdo->prepare("INSERT INTO deliveries (order_id, customer_name, address, status, delivery_date) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$orderId, $order['customer_name'] ?: 'Unknown', $order['address'] ?: 'Unknown', $deliveryStatus, $deliveryDate ?: null]);
            }

            // ---- Order ----
            $set = ['status = ?'];
            $params = [$newStatus];
            foreach (['admin_note', 'cancellation_reason'] as $col) {
                if (array_key_exists($col, $extra)) {
                    $set[] = "{$col} = ?";
                    $params[] = $extra[$col];
                }
            }
            $params[] = $orderId;
            $pdo->prepare("UPDATE orders SET " . implode(', ', $set) . " WHERE id = ?")->execute($params);

            $pdo->commit();
            return ['old' => $old, 'new' => $newStatus, 'stock' => $moved];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
