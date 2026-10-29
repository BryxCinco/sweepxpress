<?php
// includes/supplier_ops.php — form, validation at mga bilang ng supplier
// (admin/suppliers.php, admin/supplier.php; gagamitin din ng purchase order pages sa 7d).

if (!function_exists('sx_supplier_input')) {
    // Mga field ng form → [values, errors]
    function sx_supplier_input(): array
    {
        $v = [
            'name'           => trim($_POST['name'] ?? ''),
            'contact_person' => trim($_POST['contact_person'] ?? ''),
            'email'          => trim($_POST['email'] ?? ''),
            'phone'          => trim($_POST['phone'] ?? ''),
            'address'        => trim($_POST['address'] ?? ''),
            'lead_time_days' => trim($_POST['lead_time_days'] ?? '7'),
            'payment_terms'  => trim($_POST['payment_terms'] ?? ''),
            'notes'          => trim($_POST['notes'] ?? ''),
        ];
        $errors = [];
        if ($v['name'] === '' || mb_strlen($v['name']) > 160) $errors[] = 'Enter the supplier name (up to 160 characters).';
        if (mb_strlen($v['contact_person']) > 120) $errors[] = 'Contact person is too long.';
        if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || strlen($v['email']) > 190)) $errors[] = 'Enter a valid email address.';
        if ($v['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,30}$/', $v['phone'])) $errors[] = 'Enter a valid phone number.';
        if (mb_strlen($v['address']) > 255) $errors[] = 'Address is too long.';
        if (!ctype_digit($v['lead_time_days']) || (int)$v['lead_time_days'] > 365) $errors[] = 'Lead time must be 0 to 365 days.';
        if (mb_strlen($v['payment_terms']) > 60) $errors[] = 'Payment terms are too long.';
        if (mb_strlen($v['notes']) > 2000) $errors[] = 'Notes are too long (2,000 characters max).';
        return [$v, $errors];
    }

    // Para sa INSERT/UPDATE: blangko → NULL
    function sx_supplier_params(array $v): array
    {
        $n = fn($s) => $s === '' ? null : $s;
        return [$v['name'], $n($v['contact_person']), $n($v['email']), $n($v['phone']), $n($v['address']),
                (int)$v['lead_time_days'], $n($v['payment_terms']), $n($v['notes'])];
    }

    // "3M Philippines" → "3P"
    function sx_supplier_initials(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $name)));
        $first = mb_substr($words[0] ?? '?', 0, 1);
        $second = isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0] ?? '', 1, 1);
        return mb_strtoupper($first . $second);
    }

    /**
     * Supplier + mga bilang. $where/$params para sa filter ng listahan; o isang supplier gamit ang id.
     *  products     — ilang product ang naka-link
     *  open_pos     — sent o partial; open_value = halaga ng hindi pa natatanggap
     *  received_pos — mga natapos na PO; on_time = dumating sa o bago ang expected_date
     *  avg_lead     — aktwal na araw mula sent hanggang received
     *  purchased    — kabuuang halaga ng natanggap
     *  last_delivery— huling IN galing sa PO ng supplier na ito
     */
    function sx_supplier_rows(PDO $pdo, string $where = '1=1', array $params = []): array
    {
        $stmt = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id) AS products,
                   COALESCE(po.open_pos, 0) AS open_pos,
                   COALESCE(po.open_value, 0) AS open_value,
                   COALESCE(po.received_pos, 0) AS received_pos,
                   COALESCE(po.on_time, 0) AS on_time,
                   po.avg_lead,
                   COALESCE(po.purchased, 0) AS purchased,
                   (SELECT MAX(sm.created_at)
                      FROM stock_movements sm
                      JOIN purchase_orders x ON sm.ref_type = 'po' AND sm.ref_id = x.id
                     WHERE x.supplier_id = s.id) AS last_delivery
            FROM suppliers s
            LEFT JOIN (
                SELECT o.supplier_id,
                       SUM(o.status IN ('sent','partial')) AS open_pos,
                       SUM(CASE WHEN o.status IN ('sent','partial') THEN i.remaining_value ELSE 0 END) AS open_value,
                       SUM(o.status = 'received') AS received_pos,
                       SUM(o.status = 'received' AND o.expected_date IS NOT NULL AND DATE(o.received_at) <= o.expected_date) AS on_time,
                       AVG(CASE WHEN o.status = 'received' AND o.sent_at IS NOT NULL THEN DATEDIFF(o.received_at, o.sent_at) END) AS avg_lead,
                       SUM(i.received_value) AS purchased
                FROM purchase_orders o
                LEFT JOIN (
                    SELECT po_id,
                           SUM((qty_ordered - qty_received) * unit_cost) AS remaining_value,
                           SUM(qty_received * unit_cost) AS received_value
                    FROM purchase_order_items GROUP BY po_id
                ) i ON i.po_id = o.id
                GROUP BY o.supplier_id
            ) po ON po.supplier_id = s.id
            WHERE {$where}
            ORDER BY s.is_active DESC, s.name
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // On-time na porsyento, o null kapag wala pang natapos na PO
    function sx_supplier_on_time(array $s): ?int
    {
        return (int)$s['received_pos'] > 0 ? (int)round($s['on_time'] / $s['received_pos'] * 100) : null;
    }

    // ---------------------------------------------------------------------------------
    // Supplier scorecard (Phase 8d). Galing lahat sa natapos na PO (status 'received', kasama ang
    // closed short): walang bagong table.
    //   on-time  = dumating sa o bago ang expected_date
    //   fill rate= natanggap ÷ in-order na dami
    //   price    = unit_cost ng unang kumpara sa huling PO ng bawat product sa panahong iyon
    //   score    = 40% on-time + 40% fill rate + 20% presyo (100 kung hindi tumaas; -5 puntos sa bawat +1%)
    // ---------------------------------------------------------------------------------
    define('SX_SC_MIN_POS', 2);   // kulang ang kasaysayan kung mas kaunti dito

    /**
     * supplier_id => ['supplier', 'pos', 'on_time_pct', 'avg_days_late', 'fill_pct', 'lead_actual', 'lead_promised',
     *                 'prices' => [[product_id, name, first, last, pct], ...], 'price_avg_pct', 'price_up',
     *                 'score', 'grade', 'tone']
     * $days = null para sa lahat ng panahon. Pinakamataas ang score ang una.
     */
    function sx_supplier_scorecards(PDO $pdo, ?int $days = 365): array
    {
        $since = $days ? ' AND o.received_at >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)' : '';

        $pos = $pdo->query("
            SELECT o.id, o.supplier_id, o.expected_date, DATE(o.received_at) AS received_on,
                   DATEDIFF(o.received_at, o.sent_at) AS lead_days,
                   SUM(i.qty_ordered) AS ordered, SUM(i.qty_received) AS received
            FROM purchase_orders o
            JOIN purchase_order_items i ON i.po_id = o.id
            WHERE o.status = 'received' {$since}
            GROUP BY o.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $lines = $pdo->query("
            SELECT o.supplier_id, i.product_id, p.name, i.unit_cost
            FROM purchase_orders o
            JOIN purchase_order_items i ON i.po_id = o.id
            JOIN products p ON p.id = i.product_id
            WHERE o.status = 'received' AND i.qty_received > 0 AND i.unit_cost > 0 {$since}
            ORDER BY o.received_at, o.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $acc = [];
        foreach ($pos as $r) {
            $a = &$acc[(int)$r['supplier_id']];
            $a['pos'] = ($a['pos'] ?? 0) + 1;
            $a['ordered'] = ($a['ordered'] ?? 0) + (int)$r['ordered'];
            $a['received'] = ($a['received'] ?? 0) + (int)$r['received'];
            if ($r['lead_days'] !== null) $a['leads'][] = (int)$r['lead_days'];
            if ($r['expected_date'] !== null) {
                $a['dated'] = ($a['dated'] ?? 0) + 1;
                $late = (int)((strtotime($r['received_on']) - strtotime($r['expected_date'])) / 86400);
                if ($late <= 0) $a['on_time'] = ($a['on_time'] ?? 0) + 1;
                else $a['late_days'][] = $late;
            }
            unset($a);
        }

        $byProduct = [];
        foreach ($lines as $l) {
            $k = $l['supplier_id'] . ':' . $l['product_id'];
            $byProduct[$k]['supplier_id'] = (int)$l['supplier_id'];
            $byProduct[$k]['product_id'] = (int)$l['product_id'];
            $byProduct[$k]['name'] = $l['name'];
            $byProduct[$k]['costs'][] = (float)$l['unit_cost'];
        }
        $prices = [];
        foreach ($byProduct as $p) {
            if (count($p['costs']) < 2) continue;
            $first = $p['costs'][0];
            $last = $p['costs'][count($p['costs']) - 1];
            $prices[$p['supplier_id']][] = ['product_id' => $p['product_id'], 'name' => $p['name'], 'first' => $first, 'last' => $last,
                                            'pct' => ($last - $first) / $first * 100];
        }

        $out = [];
        $suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($suppliers as $s) {
            $id = (int)$s['id'];
            $a = $acc[$id] ?? [];
            if (!$a && !(int)$s['is_active']) continue;
            $pl = $prices[$id] ?? [];
            usort($pl, fn($x, $y) => abs($y['pct']) <=> abs($x['pct']));
            $row = [
                'supplier'      => $s,
                'pos'           => $a['pos'] ?? 0,
                'on_time_pct'   => !empty($a['dated']) ? (int)round(($a['on_time'] ?? 0) / $a['dated'] * 100) : null,
                'avg_days_late' => !empty($a['late_days']) ? array_sum($a['late_days']) / count($a['late_days']) : null,
                'fill_pct'      => !empty($a['ordered']) ? (int)round($a['received'] / $a['ordered'] * 100) : null,
                'lead_actual'   => !empty($a['leads']) ? array_sum($a['leads']) / count($a['leads']) : null,
                'lead_promised' => (int)$s['lead_time_days'],
                'prices'        => $pl,
                'price_avg_pct' => $pl ? array_sum(array_column($pl, 'pct')) / count($pl) : null,
                'price_up'      => count(array_filter($pl, fn($x) => $x['pct'] >= 3)),
                'score' => null, 'grade' => 'Not enough history', 'tone' => 'none',
            ];
            if ($row['pos'] >= SX_SC_MIN_POS) {
                $parts = [];
                if ($row['on_time_pct'] !== null) $parts[] = [0.4, $row['on_time_pct']];
                if ($row['fill_pct'] !== null)    $parts[] = [0.4, $row['fill_pct']];
                if ($row['price_avg_pct'] !== null) $parts[] = [0.2, max(0, min(100, 100 - max(0, $row['price_avg_pct']) * 5))];
                if ($parts) {
                    $w = array_sum(array_column($parts, 0));
                    $row['score'] = (int)round(array_sum(array_map(fn($p) => $p[0] * $p[1], $parts)) / $w);
                    [$row['grade'], $row['tone']] = $row['score'] >= 90 ? ['Excellent', 'ok']
                        : ($row['score'] >= 75 ? ['Good', 'ok'] : ($row['score'] >= 60 ? ['Fair', 'warn'] : ['Needs attention', 'bad']));
                }
            }
            $out[$id] = $row;
        }
        uasort($out, fn($x, $y) => [$y['score'] !== null, $y['score'] ?? 0, $x['supplier']['name']]
                               <=> [$x['score'] !== null, $x['score'] ?? 0, $y['supplier']['name']]);
        return $out;
    }
}
