<?php
// includes/copilot_tools.php — mga read-only na tool ng Admin Copilot (Phase 8e-1).
//
// Ito ang TANGING paraan na nakakakita ng data ang AI: mga function na may prepared statements, whitelist sa
// parameter (period, limit), at walang personal na datos ng customer (email, telepono, address, pangalan).
// Walang tool na nagsusulat o nagbubura. Ang modelo ay humihingi ng tool sa pangalan; ang PHP ang nagsasagawa.
//
//   sx_copilot_tool_defs()                 → listahan ng tool sa format ng OpenAI/Groq ("tools")
//   sx_copilot_run_tool($pdo, $name, $args) → ['ok' => bool, 'data' => array | 'error' => string]

require_once __DIR__ . '/purchasing_ops.php';   // sx_reorder_items
require_once __DIR__ . '/forecast_ops.php';     // sx_sales_velocity, sx_reorder_suggestions
require_once __DIR__ . '/supplier_ops.php';     // sx_supplier_scorecards

if (!function_exists('sx_copilot_run_tool')) {
    define('SX_CP_MAX_BYTES', 7000);   // laki ng resulta ng isang tool na ibabalik sa modelo

    // ---------------------------------------------------------------------------------
    // Mga maliit na tulong
    // ---------------------------------------------------------------------------------
    function sx_cp_enum($v, array $allowed, $default)
    {
        $v = is_string($v) ? strtolower(trim($v)) : $v;
        return in_array($v, $allowed, true) ? $v : $default;
    }

    function sx_cp_int($v, int $min, int $max, int $default): int
    {
        return is_numeric($v) ? max($min, min($max, (int)$v)) : $default;
    }

    function sx_cp_window($v): int
    {
        $n = sx_cp_int($v, 1, 3650, 90);
        return $n <= 30 ? 30 : ($n <= 90 ? 90 : 365);
    }

    function sx_cp_money($v): float
    {
        return round((float)$v, 2);
    }

    /** Kapag masyadong malaki ang resulta, paliitin ang 'rows' at lagyan ng 'truncated'. */
    function sx_cp_fit(array $data): array
    {
        for ($i = 0; $i < 8 && strlen(json_encode($data, JSON_UNESCAPED_UNICODE)) > SX_CP_MAX_BYTES; $i++) {
            if (empty($data['rows'])) break;
            $data['rows'] = array_slice($data['rows'], 0, max(1, intdiv(count($data['rows']), 2)));
            $data['truncated'] = true;
        }
        return $data;
    }

    /**
     * Panahon → [from, to, prev_from, prev_to, label, prev_label]; SQL expression ang unang apat (constant lang,
     * hindi galing sa user). Ang "previous" ay ang katumbas na panahon bago nito, para sa paghahambing.
     */
    function sx_cp_period(string $p): array
    {
        $first = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        if ($p === 'yesterday') {
            return ['DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 'CURDATE()', 'DATE_SUB(CURDATE(), INTERVAL 2 DAY)', 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 'yesterday', 'the day before'];
        }
        if ($p === 'month') {
            return [$first, 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)', "DATE_SUB($first, INTERVAL 1 MONTH)", $first, 'this month so far', 'last full month'];
        }
        if ($p === 'last_month') {
            return ["DATE_SUB($first, INTERVAL 1 MONTH)", $first, "DATE_SUB($first, INTERVAL 2 MONTH)", "DATE_SUB($first, INTERVAL 1 MONTH)", 'last month', 'the month before'];
        }
        $n = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365][$p] ?? 30;
        return [
            'DATE_SUB(CURDATE(), INTERVAL ' . ($n - 1) . ' DAY)', 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)',
            'DATE_SUB(CURDATE(), INTERVAL ' . (2 * $n - 1) . ' DAY)', 'DATE_SUB(CURDATE(), INTERVAL ' . ($n - 1) . ' DAY)',
            $n === 1 ? 'today' : "last {$n} days", $n === 1 ? 'yesterday' : "the {$n} days before",
        ];
    }

    define('SX_CP_PERIODS', ['today', 'yesterday', '7d', '30d', '90d', '365d', 'month', 'last_month']);

    // ---------------------------------------------------------------------------------
    // Mga tool
    // ---------------------------------------------------------------------------------

    function sx_cp_sales_block(PDO $pdo, string $from, string $to): array
    {
        $r = $pdo->query("
            SELECT COALESCE(SUM(status <> 'cancelled'), 0) AS orders,
                   COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total END), 0) AS sales,
                   COALESCE(SUM(CASE WHEN status IN ('delivered','completed') THEN total END), 0) AS delivered_sales,
                   COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                   COALESCE(SUM(status <> 'cancelled' AND order_type = 'B2B'), 0) AS b2b_orders,
                   COALESCE(SUM(CASE WHEN status <> 'cancelled' AND order_type = 'B2B' THEN total END), 0) AS b2b_sales
            FROM orders WHERE created_at >= {$from} AND created_at < {$to}
        ")->fetch(PDO::FETCH_ASSOC);
        $orders = (int)$r['orders'];
        return [
            'orders'          => $orders,
            'sales'           => sx_cp_money($r['sales']),
            'delivered_sales' => sx_cp_money($r['delivered_sales']),
            'avg_order_value' => $orders ? sx_cp_money($r['sales'] / $orders) : 0.0,
            'cancelled'       => (int)$r['cancelled'],
            'b2b_orders'      => (int)$r['b2b_orders'],
            'b2b_sales'       => sx_cp_money($r['b2b_sales']),
        ];
    }

    function sx_cp_get_sales_summary(PDO $pdo, array $a): array
    {
        $period = sx_cp_enum($a['period'] ?? null, SX_CP_PERIODS, '30d');
        [$from, $to, $pfrom, $pto, $label, $plabel] = sx_cp_period($period);
        $cur  = sx_cp_sales_block($pdo, $from, $to);
        $prev = sx_cp_sales_block($pdo, $pfrom, $pto);
        $pct  = fn($c, $p) => $p > 0 ? round(($c - $p) / $p * 100, 1) : null;
        return [
            'currency' => 'PHP', 'period' => $label, 'current' => $cur,
            'previous' => ['period' => $plabel] + $prev,
            'change_pct' => ['sales' => $pct($cur['sales'], $prev['sales']), 'orders' => $pct($cur['orders'], $prev['orders'])],
            'note' => 'sales = all non-cancelled orders; delivered_sales = delivered or completed only',
        ];
    }

    function sx_cp_get_top_products(PDO $pdo, array $a): array
    {
        $period  = sx_cp_enum($a['period'] ?? null, SX_CP_PERIODS, '30d');
        $ranking = sx_cp_enum($a['ranking'] ?? null, ['top', 'slowest'], 'top');
        $limit   = sx_cp_int($a['limit'] ?? null, 1, 15, 5);
        [$from, $to, , , $label] = sx_cp_period($period);
        $sold = "SELECT oi.product_id, SUM(oi.quantity) AS units, SUM(oi.quantity * oi.price) AS revenue, COUNT(DISTINCT o.id) AS orders
                 FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE o.status <> 'cancelled' AND o.created_at >= {$from} AND o.created_at < {$to} GROUP BY oi.product_id";
        if ($ranking === 'top') {
            $sql = "SELECT p.id, p.name, s.units, s.revenue, s.orders,
                           COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock
                    FROM ({$sold}) s JOIN products p ON p.id = s.product_id ORDER BY s.units DESC, p.name LIMIT {$limit}";
        } else {   // mga may stock pero kaunti o walang benta
            $sql = "SELECT p.id, p.name, COALESCE(s.units, 0) AS units, COALESCE(s.revenue, 0) AS revenue, COALESCE(s.orders, 0) AS orders,
                           COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock
                    FROM products p LEFT JOIN ({$sold}) s ON s.product_id = p.id
                    HAVING stock > 0 ORDER BY units ASC, stock DESC, p.name LIMIT {$limit}";
        }
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'units_sold' => (int)$r['units'],
            'revenue' => sx_cp_money($r['revenue']), 'orders' => (int)$r['orders'], 'stock' => (int)$r['stock'],
        ], $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
        return ['period' => $label, 'ranking' => $ranking, 'rows' => $rows];
    }

    function sx_cp_get_low_stock(PDO $pdo, array $a): array
    {
        $limit   = sx_cp_int($a['limit'] ?? null, 1, 30, 8);
        $window  = sx_cp_window($a['window_days'] ?? 90);
        $vel     = sx_sales_velocity($pdo, $window);
        $items   = sx_reorder_items($pdo);
        $out = $low = $covered = 0;
        $rows = [];
        foreach ($items as $r) {
            $stock = (int)$r['stock'];
            $perDay = $vel[(int)$r['id']]['per_day'] ?? 0.0;
            $days = sx_days_of_stock($stock, $perDay);
            if ($stock === 0) $out++;
            if ($r['suggest'] === 0) $covered++; else $low++;
            if (count($rows) < $limit) {
                $rows[] = [
                    'id' => (int)$r['id'], 'name' => $r['name'], 'stock' => $stock, 'reorder_point' => (int)$r['reorder_point'],
                    'on_order' => (int)$r['on_order'], 'suggested_order_qty' => (int)$r['suggest'],
                    'sells_per_day' => round($perDay, 2), 'days_left' => $days,
                    'supplier' => $r['supplier_name'], 'supplier_lead_days' => $r['lead_time_days'] === null ? null : (int)$r['lead_time_days'],
                    'status' => $stock === 0 ? 'out_of_stock' : ($r['suggest'] === 0 ? 'covered_by_open_po' : 'needs_reorder'),
                ];
            }
        }
        return ['at_or_below_reorder_point' => count($items), 'out_of_stock' => $out, 'needs_reorder' => $low,
                'covered_by_open_po' => $covered, 'sales_window_days' => $window, 'rows' => $rows];
    }

    function sx_cp_get_stock_forecast(PDO $pdo, array $a): array
    {
        $window = sx_cp_window($a['window_days'] ?? 90);
        $limit  = sx_cp_int($a['limit'] ?? null, 1, 20, 6);
        $query  = trim((string)($a['product'] ?? ''));
        $vel    = sx_sales_velocity($pdo, $window);
        $where = '1=1';
        $params = [];
        if ($query !== '') {
            $like = '%' . addcslashes(mb_substr($query, 0, 80), '%_\\') . '%';
            $where = '(p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)';
            $params = [$like, $like, ctype_digit($query) ? (int)$query : 0];
        }
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, s.name AS supplier, s.lead_time_days,
                   COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock
            FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE {$where} ORDER BY p.name");
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $perDay = $vel[(int)$r['id']]['per_day'] ?? 0.0;
            $days = sx_days_of_stock((int)$r['stock'], $perDay);
            if ($query === '' && $days === null) continue;   // walang benta, hindi masabi
            $rows[] = [
                'id' => (int)$r['id'], 'name' => $r['name'], 'stock' => (int)$r['stock'], 'sells_per_day' => round($perDay, 2),
                'days_left' => $days, 'supplier' => $r['supplier'],
                'supplier_lead_days' => $r['lead_time_days'] === null ? null : (int)$r['lead_time_days'],
                'runs_out_before_supplier_can_deliver' => $days !== null && $r['lead_time_days'] !== null && $days <= (int)$r['lead_time_days'],
            ];
        }
        usort($rows, fn($x, $y) => [$x['days_left'] === null, $x['days_left'] ?? 0] <=> [$y['days_left'] === null, $y['days_left'] ?? 0]);
        return ['sales_window_days' => $window, 'matches' => count($rows), 'rows' => array_slice($rows, 0, $limit),
                'note' => 'days_left null means no sales in the window, so it cannot be estimated'];
    }

    function sx_cp_get_reorder_suggestions(PDO $pdo, array $a): array
    {
        $window = sx_cp_window($a['window_days'] ?? 90);
        $limit  = sx_cp_int($a['limit'] ?? null, 1, 20, 6);
        $count = ['raise' => 0, 'lower' => 0, 'same' => 0, 'no_data' => 0];
        $rows = [];
        foreach (sx_reorder_suggestions($pdo, $window) as $r) {
            $count[$r['status']]++;
            if (!in_array($r['status'], ['raise', 'lower'], true)) continue;
            $rows[] = [
                'id' => (int)$r['id'], 'name' => $r['name'], 'action' => $r['status'],
                'reorder_point_now' => (int)$r['reorder_point'], 'reorder_point_suggested' => (int)$r['new_rp'],
                'order_qty_suggested' => (int)$r['new_rq'], 'sells_per_day' => round($r['stats']['mean'], 2),
                'supplier_lead_days' => (int)$r['lead'], 'lead_time_assumed' => (bool)$r['lead_assumed'],
                'safety_stock' => (int)$r['safety'], 'bulk_day_capped' => (bool)$r['stats']['capped'],
            ];
        }
        usort($rows, fn($x, $y) => abs($y['reorder_point_suggested'] - $y['reorder_point_now']) <=> abs($x['reorder_point_suggested'] - $x['reorder_point_now']));
        return ['sales_window_days' => $window, 'counts' => $count, 'rows' => array_slice($rows, 0, $limit),
                'formula' => 'reorder point = sales per day x supplier lead time + safety stock; order qty = about 30 days of sales'];
    }

    function sx_cp_get_orders_overview(PDO $pdo, array $a): array
    {
        $by = [];
        foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM orders GROUP BY status") as $r) $by[$r['status']] = (int)$r['n'];
        $stale = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','Pending PO') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
        $oldest = array_map(fn($r) => [
            'order_id' => (int)$r['id'], 'status' => $r['status'], 'type' => $r['order_type'], 'total' => sx_cp_money($r['total']), 'age_days' => (int)$r['age'],
        ], $pdo->query("
            SELECT id, status, order_type, total, DATEDIFF(NOW(), created_at) AS age FROM orders
            WHERE status IN ('pending','Pending PO','cancellation_requested') ORDER BY created_at ASC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC));
        return [
            'by_status' => $by,
            'to_process' => ($by['pending'] ?? 0) + ($by['Pending PO'] ?? 0),
            'pending_older_than_7_days' => $stale,
            'cancellation_requests' => $by['cancellation_requested'] ?? 0,
            'oldest_waiting' => $oldest,
            'note' => 'shipped is shown to staff as Preparing',
        ];
    }

    function sx_cp_get_purchase_orders(PDO $pdo, array $a): array
    {
        $filter = sx_cp_enum($a['filter'] ?? null, ['open', 'overdue', 'arriving', 'draft', 'received', 'all'], 'open');
        $limit  = sx_cp_int($a['limit'] ?? null, 1, 20, 6);
        $where = match ($filter) {
            'open'     => "o.status IN ('sent','partial')",
            'overdue'  => "o.status IN ('sent','partial') AND o.expected_date < CURDATE()",
            'arriving' => "o.status IN ('sent','partial') AND o.expected_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
            'draft'    => "o.status = 'draft'",
            'received' => "o.status = 'received'",
            default    => "o.status <> 'cancelled'",
        };
        $order = $filter === 'received' ? 'o.received_at DESC' : 'o.expected_date IS NULL, o.expected_date ASC';
        $rows = array_map(fn($r) => [
            'po_number' => $r['po_number'], 'supplier' => $r['supplier'], 'status' => $r['status'], 'expected_date' => $r['expected_date'],
            'days_overdue' => $r['overdue'] > 0 && in_array($r['status'], ['sent', 'partial'], true) ? (int)$r['overdue'] : 0,
            'total' => sx_cp_money($r['total']), 'units_ordered' => (int)$r['ordered'], 'units_received' => (int)$r['received'],
        ], $pdo->query("
            SELECT o.po_number, s.name AS supplier, o.status, o.expected_date, o.total, DATEDIFF(CURDATE(), o.expected_date) AS overdue,
                   COALESCE(SUM(i.qty_ordered), 0) AS ordered, COALESCE(SUM(i.qty_received), 0) AS received
            FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id LEFT JOIN purchase_order_items i ON i.po_id = o.id
            WHERE {$where} GROUP BY o.id ORDER BY {$order} LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC));
        return ['filter' => $filter, 'rows' => $rows];
    }

    function sx_cp_get_supplier_scorecard(PDO $pdo, array $a): array
    {
        $period = sx_cp_enum($a['period'] ?? null, ['90d', '180d', '365d', 'all'], '365d');
        $cards  = sx_supplier_scorecards($pdo, $period === 'all' ? null : (int)$period);
        $rows = [];
        foreach ($cards as $c) {
            $rows[] = [
                'supplier' => $c['supplier']['name'], 'score' => $c['score'], 'grade' => $c['grade'], 'completed_orders' => $c['pos'],
                'on_time_pct' => $c['on_time_pct'], 'avg_days_late' => $c['avg_days_late'] === null ? null : round($c['avg_days_late'], 1),
                'fill_rate_pct' => $c['fill_pct'],
                'lead_days_actual' => $c['lead_actual'] === null ? null : round($c['lead_actual'], 1), 'lead_days_promised' => $c['lead_promised'],
                'avg_price_change_pct' => $c['price_avg_pct'] === null ? null : round($c['price_avg_pct'], 1), 'products_up_3pct_or_more' => $c['price_up'],
                'biggest_price_moves' => array_map(fn($p) => ['product' => $p['name'], 'from' => sx_cp_money($p['first']), 'to' => sx_cp_money($p['last']), 'pct' => round($p['pct'], 1)],
                                                    array_slice($c['prices'], 0, 2)),
            ];
        }
        return ['period' => $period, 'score_formula' => '40% on-time, 40% fill rate, 20% price steadiness; needs 2+ completed orders', 'rows' => $rows];
    }

    function sx_cp_find_product(PDO $pdo, array $a): array
    {
        $q = trim((string)($a['query'] ?? ''));
        if ($q === '') throw new InvalidArgumentException('query is required');
        $limit = sx_cp_int($a['limit'] ?? null, 1, 10, 5);
        $like = '%' . addcslashes(mb_substr($q, 0, 80), '%_\\') . '%';
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.sku, p.category, p.price, p.cost_price, p.reorder_point, s.name AS supplier,
                   COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock
            FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.name LIKE ? OR p.sku LIKE ? OR p.category LIKE ? ORDER BY p.name LIMIT {$limit}");
        $stmt->execute([$like, $like, $like]);
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'sku' => $r['sku'], 'category' => $r['category'],
            'price' => sx_cp_money($r['price']), 'cost_price' => $r['cost_price'] === null ? null : sx_cp_money($r['cost_price']),
            'margin_pct' => $r['cost_price'] !== null && $r['price'] > 0 ? round(($r['price'] - $r['cost_price']) / $r['price'] * 100, 1) : null,
            'stock' => (int)$r['stock'], 'reorder_point' => (int)$r['reorder_point'], 'supplier' => $r['supplier'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['query' => $q, 'rows' => $rows];
    }

    /** Isang tawag para sa "daily brief". */
    function sx_cp_get_business_snapshot(PDO $pdo, array $a): array
    {
        [$f1, $t1] = sx_cp_period('today');
        [$f2, $t2] = sx_cp_period('month');
        $today = sx_cp_sales_block($pdo, $f1, $t1);
        $month = sx_cp_sales_block($pdo, $f2, $t2);
        $orders = sx_cp_get_orders_overview($pdo, []);
        $items = sx_reorder_items($pdo);
        $po = $pdo->query("
            SELECT COALESCE(SUM(status IN ('sent','partial')), 0) AS open_pos,
                   COALESCE(SUM(status IN ('sent','partial') AND expected_date < CURDATE()), 0) AS overdue,
                   COALESCE(SUM(status IN ('sent','partial') AND expected_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)), 0) AS arriving_7d,
                   COALESCE(SUM(status = 'draft'), 0) AS drafts
            FROM purchase_orders")->fetch(PDO::FETCH_ASSOC);
        return [
            'currency' => 'PHP',
            'today' => ['orders' => $today['orders'], 'sales' => $today['sales']],
            'month_so_far' => ['orders' => $month['orders'], 'sales' => $month['sales'], 'delivered_sales' => $month['delivered_sales']],
            'orders_to_process' => $orders['to_process'], 'cancellation_requests' => $orders['cancellation_requests'],
            'pending_older_than_7_days' => $orders['pending_older_than_7_days'],
            'stock' => [
                'at_or_below_reorder_point' => count($items),
                'out_of_stock' => count(array_filter($items, fn($r) => (int)$r['stock'] === 0)),
                'need_reorder_now' => count(array_filter($items, fn($r) => $r['suggest'] > 0)),
                'products_without_supplier' => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE supplier_id IS NULL")->fetchColumn(),
            ],
            'purchase_orders' => ['open' => (int)$po['open_pos'], 'overdue' => (int)$po['overdue'], 'arriving_next_7_days' => (int)$po['arriving_7d'], 'drafts' => (int)$po['drafts']],
        ];
    }

    // ---------------------------------------------------------------------------------
    // Rehistro: pangalan → handler. Ang mga paglalarawan (Ingles) ang binabasa ng modelo para pumili ng tool.
    // ---------------------------------------------------------------------------------
    function sx_cp_period_param(): array
    {
        return ['type' => 'string', 'enum' => SX_CP_PERIODS];   // default 30d
    }

    function sx_copilot_registry(): array
    {
        $limit = fn(int $max) => ['type' => 'integer', 'maximum' => $max];
        $win = ['type' => 'integer', 'enum' => [30, 90, 365]];   // araw ng benta na pagbabatayan; default 90
        return [
            'get_business_snapshot' => ['sx_cp_get_business_snapshot', 'Headline numbers now: sales today and this month, orders waiting, cancellation requests, low/out-of-stock counts, open and overdue purchase orders. For a daily brief.', []],
            'get_sales_summary' => ['sx_cp_get_sales_summary', 'Sales and orders for a period with B2B share and percent change vs the previous period.', ['period' => sx_cp_period_param()]],
            'get_top_products' => ['sx_cp_get_top_products', 'Best sellers (ranking=top) or products with stock that barely sell (ranking=slowest) in a period.', ['period' => sx_cp_period_param(), 'ranking' => ['type' => 'string', 'enum' => ['top', 'slowest']], 'limit' => $limit(15)]],
            'get_low_stock' => ['sx_cp_get_low_stock', 'Products at or below reorder point: stock, on order, suggested qty, days left, supplier lead time.', ['limit' => $limit(30), 'window_days' => $win]],
            'get_stock_forecast' => ['sx_cp_get_stock_forecast', 'Days of stock left from recent sales speed. Give a product name/SKU/id, or omit it for the soonest to run out.', ['product' => ['type' => 'string'], 'window_days' => $win, 'limit' => $limit(20)]],
            'get_reorder_suggestions' => ['sx_cp_get_reorder_suggestions', 'Suggested new reorder points and order quantities (raise/lower) from sales speed, lead time and safety stock.', ['window_days' => $win, 'limit' => $limit(20)]],
            'get_orders_overview' => ['sx_cp_get_orders_overview', 'Customer orders by status, waiting and stale pending orders, cancellation requests, oldest waiting. No personal data.', []],
            'get_purchase_orders' => ['sx_cp_get_purchase_orders', 'Restock purchase orders to suppliers by filter.', ['filter' => ['type' => 'string', 'enum' => ['open', 'overdue', 'arriving', 'draft', 'received', 'all']], 'limit' => $limit(20)]],
            'get_supplier_scorecard' => ['sx_cp_get_supplier_scorecard', 'Supplier reliability: score, on-time %, fill rate, actual vs promised lead time, price changes.', ['period' => ['type' => 'string', 'enum' => ['90d', '180d', '365d', 'all']]]],
            'find_product' => ['sx_cp_find_product', 'Find products by name, SKU or category: price, cost, margin, stock, supplier.', ['query' => ['type' => 'string'], 'limit' => $limit(10)], ['query']],
        ];
    }

    /** Listahan ng tool sa format ng OpenAI/Groq. */
    function sx_copilot_tool_defs(): array
    {
        $defs = [];
        foreach (sx_copilot_registry() as $name => $t) {
            $params = ['type' => 'object', 'properties' => (object)$t[2]];
            if (!empty($t[3])) $params['required'] = $t[3];
            $defs[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $t[1], 'parameters' => $params]];
        }
        return $defs;
    }

    /** Isagawa ang tool. Hindi kailanman nagbabato: ang error ay ibinabalik para mabasa ng modelo. */
    function sx_copilot_run_tool(PDO $pdo, string $name, $args): array
    {
        $reg = sx_copilot_registry();
        if (!isset($reg[$name])) return ['ok' => false, 'error' => 'Unknown tool.'];
        if (is_string($args)) $args = json_decode($args, true);
        if (!is_array($args)) $args = [];
        try {
            return ['ok' => true, 'data' => sx_cp_fit(($reg[$name][0])($pdo, $args))];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log("copilot tool {$name} failed: " . $e->getMessage());
            return ['ok' => false, 'error' => 'The tool failed. Try again or ask differently.'];
        }
    }
}
