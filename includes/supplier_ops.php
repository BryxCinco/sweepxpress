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
}
