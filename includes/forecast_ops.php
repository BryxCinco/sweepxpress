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
}
