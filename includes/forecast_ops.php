<?php
// includes/forecast_ops.php — bilis ng benta at "days of stock left" (Phase 8).
// Galing lahat sa order_items + orders; walang bagong table. Hindi kasama ang cancelled na order.

if (!function_exists('sx_sales_velocity')) {
    define('SX_VELOCITY_DAYS', 90);
    define('SX_FORECAST_WINDOWS', [30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 365 days']);

    /**
     * Anong window ang gagamitin: ?window=30|90|365, kung wala ay ang huling pinili (cookie
     * ng browser na ito), kung wala pa rin ay 90. Tawagin bago mag-output (nagse-set ng cookie).
     */
    function sx_forecast_window(): int
    {
        $asked = isset($_GET['window']) ? (int)$_GET['window'] : 0;
        if (isset(SX_FORECAST_WINDOWS[$asked])) {
            setcookie('sx_forecast_days', (string)$asked, ['expires' => time() + 31536000, 'path' => '/sweepxpress/admin/', 'samesite' => 'Lax']);
            return $asked;
        }
        $saved = (int)($_COOKIE['sx_forecast_days'] ?? 0);
        return isset(SX_FORECAST_WINDOWS[$saved]) ? $saved : SX_VELOCITY_DAYS;
    }

    /** Dropdown ng window. $keep = ibang query params na dapat manatili (hal. search, filter). */
    function sx_forecast_picker(int $days, array $keep = []): string
    {
        static $css = false;
        $out = '';
        if (!$css) {
            $css = true;
            $out .= '<style>.fc-window{display:inline-flex;align-items:center;gap:var(--sx-space-2);margin:0;font-size:var(--sx-text-xs);color:var(--sx-muted)}.fc-window .sx-select{width:auto;min-height:0;padding-block:6px}</style>';
        }
        $out .= '<form method="get" class="fc-window"><label for="fc-window">Sales based on</label>';
        foreach ($keep as $k => $v) {
            if ($v !== null && $v !== '') $out .= '<input type="hidden" name="' . htmlspecialchars((string)$k, ENT_QUOTES) . '" value="' . htmlspecialchars((string)$v, ENT_QUOTES) . '">';
        }
        $out .= '<select name="window" id="fc-window" class="sx-select" onchange="this.form.submit()">';
        foreach (SX_FORECAST_WINDOWS as $d => $label) {
            $out .= '<option value="' . $d . '"' . ($d === $days ? ' selected' : '') . '>' . $label . '</option>';
        }
        return $out . '</select></form>';
    }

    /**
     * product_id => ['units', 'orders', 'per_day', 'last_sold'] para sa huling $days araw.
     * Ang hati ay ang mas maikli sa $days at sa edad ng product (min. 7 araw), para hindi
     * mapababa ang bilis ng bagong product na kakalagay lang.
     */
    function sx_sales_velocity(PDO $pdo, int $days = SX_VELOCITY_DAYS): array
    {
        $days = max(7, min(365, $days));
        $stmt = $pdo->prepare("
            SELECT oi.product_id,
                   SUM(oi.quantity)          AS units,
                   COUNT(DISTINCT o.id)      AS orders,
                   MAX(o.created_at)         AS last_sold,
                   GREATEST(7, LEAST(:days, DATEDIFF(NOW(), p.created_at))) AS span
            FROM order_items oi
            JOIN orders o   ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            WHERE o.status <> 'cancelled' AND o.created_at >= DATE_SUB(NOW(), INTERVAL :days2 DAY)
            GROUP BY oi.product_id, p.created_at
        ");
        $stmt->execute([':days' => $days, ':days2' => $days]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['product_id']] = [
                'units'     => (int)$r['units'],
                'orders'    => (int)$r['orders'],
                'per_day'   => (int)$r['units'] / max(7, (int)$r['span']),
                'last_sold' => $r['last_sold'],
            ];
        }
        return $out;
    }

    /** Ilang araw bago maubos ang $stock sa bilis na $perDay. null = walang benta, kaya hindi masabi. */
    function sx_days_of_stock(int $stock, float $perDay): ?int
    {
        if ($perDay <= 0) return null;
        return (int)floor(max(0, $stock) / $perDay);
    }

    /** [label, tone] para sa chip: tone = out | low | ok | none */
    function sx_days_label(?int $days, int $stock, int $leadTime = 7): array
    {
        if ($stock <= 0)    return ['Out of stock', 'out'];
        if ($days === null) return ['No recent sales', 'none'];
        if ($days > 365)    return ['1 yr+', 'ok'];
        $tone = $days <= $leadTime ? 'low' : 'ok';   // mauubos bago pa dumating ang bagong order
        return ['~' . $days . ' day' . ($days === 1 ? '' : 's') . ' left', $tone];
    }

    // ---------------------------------------------------------------------------------
    // Smart reorder points (Phase 8c). Klasikong inventory math, hindi black box:
    //   reorder point = benta/araw × lead time ng supplier + safety stock
    //   safety stock  = 1.65 × (pagbabago ng benta kada araw) × √lead time   (≈95% na hindi mauubusan)
    //   reorder qty   = benta/araw × 30 (isang buwang suplay)
    // Ang bulk na araw (hal. isang order na 1,000 pcs) ay nilalagyan ng cap para hindi nito sirain ang average.
    // ---------------------------------------------------------------------------------
    define('SX_RP_Z', 1.65);            // service level ≈ 95%
    define('SX_RP_CYCLE_DAYS', 30);     // ilang araw na suplay ang bawat order
    define('SX_RP_DEFAULT_LEAD', 7);    // kung walang supplier
    define('SX_RP_MIN_ORDERS', 3);      // mas kaunti dito = kulang pa ang datos

    /** product_id => ['mean', 'sd', 'orders', 'sold_days', 'capped', 'units'] */
    function sx_demand_stats(PDO $pdo, int $days = SX_VELOCITY_DAYS): array
    {
        $days = max(7, min(365, $days));
        $stmt = $pdo->prepare("
            SELECT oi.product_id, DATE(o.created_at) AS d, SUM(oi.quantity) AS q, COUNT(DISTINCT o.id) AS n,
                   GREATEST(7, LEAST(:days, DATEDIFF(NOW(), p.created_at))) AS span
            FROM order_items oi
            JOIN orders o   ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            WHERE o.status <> 'cancelled' AND o.created_at >= DATE_SUB(NOW(), INTERVAL :days2 DAY)
            GROUP BY oi.product_id, p.created_at, DATE(o.created_at)
        ");
        $stmt->execute([':days' => $days, ':days2' => $days]);
        $byProduct = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byProduct[(int)$r['product_id']]['span'] = max(7, (int)$r['span']);
            $byProduct[(int)$r['product_id']]['days'][] = ['q' => (int)$r['q'], 'n' => (int)$r['n']];
        }
        $out = [];
        foreach ($byProduct as $pid => $info) {
            $qs = array_column($info['days'], 'q');
            sort($qs);
            $mid = intdiv(count($qs), 2);
            $median = count($qs) % 2 ? $qs[$mid] : ($qs[$mid - 1] + $qs[$mid]) / 2;
            $cap = max(10, 5 * $median);             // ang isang araw ay hindi lalampas ng 5× ng karaniwang araw ng benta (min. 10 pcs)
            $span = $info['span'];
            $sum = $sumSq = 0.0;
            $capped = false;
            foreach ($qs as $q) {
                if ($q > $cap) { $q = $cap; $capped = true; }
                $sum += $q;
                $sumSq += $q * $q;
            }
            $mean = $sum / $span;
            $out[$pid] = [
                'mean'      => $mean,
                'sd'        => sqrt(max(0.0, $sumSq / $span - $mean * $mean)),
                'orders'    => array_sum(array_column($info['days'], 'n')),
                'sold_days' => count($qs),
                'capped'    => $capped,
                'units'     => (int)array_sum($qs),
            ];
        }
        return $out;
    }

    /** [reorder_point, reorder_qty, safety_stock] mula sa stats at lead time. */
    function sx_reorder_point_math(float $mean, float $sd, int $lead): array
    {
        $lead   = max(1, $lead);
        $safety = (int)ceil(SX_RP_Z * $sd * sqrt($lead));
        $rp     = (int)ceil($mean * $lead + $safety);
        return [min(100000, max(1, $rp)), min(100000, max(1, (int)ceil($mean * SX_RP_CYCLE_DAYS))), $safety];
    }

    /**
     * Mungkahi para sa bawat product. status: raise | lower | same | no_data
     * (same = nasa loob ng 10% o 1 pc ng kasalukuyan).
     */
    function sx_reorder_suggestions(PDO $pdo, int $days = SX_VELOCITY_DAYS): array
    {
        $stats = sx_demand_stats($pdo, $days);
        $rows = $pdo->query("
            SELECT p.id, p.name, p.sku, p.image_path, p.reorder_point, p.reorder_qty, p.supplier_id,
                   s.name AS supplier_name, s.lead_time_days,
                   COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock
            FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id
            ORDER BY p.name
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $st = $stats[(int)$r['id']] ?? null;
            $r['lead_assumed'] = $r['lead_time_days'] === null;
            $r['lead']   = $r['lead_assumed'] ? SX_RP_DEFAULT_LEAD : (int)$r['lead_time_days'];
            $r['stats']  = $st;
            $r['new_rp'] = $r['new_rq'] = $r['safety'] = null;
            if (!$st || $st['orders'] < SX_RP_MIN_ORDERS || $st['sold_days'] < 2) {
                $r['status'] = 'no_data';
                continue;
            }
            [$r['new_rp'], $r['new_rq'], $r['safety']] = sx_reorder_point_math($st['mean'], $st['sd'], $r['lead']);
            $old = (int)$r['reorder_point'];
            $r['status'] = abs($r['new_rp'] - $old) <= max(1, (int)round($old * 0.1)) ? 'same'
                         : ($r['new_rp'] > $old ? 'raise' : 'lower');
        }
        return $rows;
    }

    /**
     * I-apply ang mga mungkahi sa napiling product. Kinukuwenta ulit dito sa server (hindi
     * pinagkakatiwalaan ang numerong galing sa form). Binabalik ang bilang ng na-update.
     */
    function sx_apply_reorder_points(PDO $pdo, array $productIds, int $days): int
    {
        $want = array_flip(array_map('intval', $productIds));
        $upd = $pdo->prepare("UPDATE products SET reorder_point = ?, reorder_qty = ? WHERE id = ?");
        $n = 0;
        $pdo->beginTransaction();
        try {
            foreach (sx_reorder_suggestions($pdo, $days) as $r) {
                if (!isset($want[(int)$r['id']]) || !in_array($r['status'], ['raise', 'lower'], true)) continue;
                $upd->execute([$r['new_rp'], $r['new_rq'], $r['id']]);
                $n += $upd->rowCount() > 0 ? 1 : 0;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $n;
    }
}
