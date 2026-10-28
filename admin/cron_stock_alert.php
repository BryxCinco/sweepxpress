<?php
/**
 * AUTO-INVENTORY REFILL REMINDER (Real-Time Version)
 * Can be run manually, via Cron, or triggered by Checkout.
 */

// 1. LOAD DEPENDENCIES
// We use __DIR__ to find files relative to this script

// Admin guard: kapag DIREKTANG binuksan ang URL lang ito iche-check.
// Kapag ini-include ng checkout.php / purchaseorder.php (customer ang naka-login),
// laktawan — kung hindi, mag-e-exit ito sa gitna ng checkout.
// Laktawan din sa CLI (totoong cron job), dahil walang session doon.
if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    require_once __DIR__ . '/admin_auth.php';
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// 2. CHECK MODE (Silent vs Manual)
// Kung $silent_mode ay TRUE, hindi ito mag-didisplay sa screen (pang checkout to)
$is_silent = isset($silent_mode) && $silent_mode === true;

// 3. SETTINGS & CREDENTIALS
// Mail credentials galing sa includes/email_config.php (.env) — nilo-load sa SEND EMAIL step sa ibaba.

// Threshold: products.reorder_point ng bawat produkto (Phase 7c; dati 10 para sa lahat)

// ==============================
// 🧠 LOGIC & SENDING
// ==============================
try {
    // 1. Check Stock
    $sql = "SELECT p.name, p.sku, p.reorder_point, IFNULL(SUM(i.quantity),0) as real_stock
            FROM products p 
            LEFT JOIN inventory i ON i.product_id = p.id 
            GROUP BY p.id, p.reorder_point
            HAVING real_stock <= p.reorder_point
            ORDER BY real_stock ASC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $lowStockItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Prepare Email Content
    if (count($lowStockItems) > 0) {
        
        $css = "<style>
            body { font-family: sans-serif; background: #f4f6f9; padding: 20px; }
            .card { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: auto; box-shadow: 0 5px 15px rgba(0,0,0,0.1); border-top: 5px solid #d9534f; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th { text-align: left; color: #555; padding: 10px; border-bottom: 2px solid #eee; }
            td { padding: 12px 10px; border-bottom: 1px solid #eee; }
            .badge { padding: 5px 10px; border-radius: 5px; font-size: 0.8em; font-weight: bold; }
            .crit { background: #fff3cd; color: #856404; }
            .out { background: #f8d7da; color: #721c24; }
        </style>";

        $htmlContent = $css . "<div class='card'>
            <h2 style='color:#d9534f'>⚠️ Low Stock Alert (Real-Time)</h2>
            <p><strong>Uy Boss!</strong> A purchase was just made, and these items are now low:</p>
            <table>
                <tr><th>Item Name</th><th>Qty</th><th>Status</th></tr>";
        
        foreach ($lowStockItems as $item) {
            $stock = $item['real_stock'];
            $status = ($stock == 0) ? "<span class='badge out'>Out of Stock</span>" : "<span class='badge crit'>Critical</span>";
            $itemName = htmlspecialchars((string)$item['name'], ENT_QUOTES, 'UTF-8');
            $itemSku  = htmlspecialchars((string)$item['sku'], ENT_QUOTES, 'UTF-8');
            $htmlContent .= "<tr>
                <td>{$itemName} <br><small style='color:#999'>{$itemSku} · reorder at {$item['reorder_point']}</small></td>
                <td><strong>{$stock}</strong></td>
                <td>{$status}</td>
            </tr>";
        }
        $htmlContent .= "</table>
        <p style='margin-top:20px; font-size:0.9em; color:#777; text-align:center;'>
            Triggered by Purchase • " . date('Y-m-d h:i A') . "
        </p></div>";

        // Show on screen ONLY if NOT silent
        if (!$is_silent) {
            echo $htmlContent;
        }

        // SEND EMAIL via SMTP
        $mail = null;
        try {
            require_once __DIR__ . '/../includes/email_config.php';
            $mail = make_mailer();
            $mail->setFrom(MAIL_USERNAME, 'SweepXpress Admin Team');
            $mail->addAddress(MAIL_USERNAME); // admin inbox = mail account

            $mail->isHTML(true);
            $mail->Subject = ' Stock Alert: ' . count($lowStockItems) . ' items low';
            $mail->Body    = $htmlContent;

            $mail->send();
            
            if (!$is_silent) {
                echo "<div style='text-align:center; margin-top:20px; color:green; padding:15px; background:#dcfce7; border-radius:8px;'><strong>✅ Email Sent!</strong></div>";
            }
        } catch (Throwable $e) {
            // Log error silently if in silent mode, or echo if manual
            $error = $mail ? $mail->ErrorInfo : $e->getMessage();
            error_log("Stock alert email failed: {$error}");
            if (!$is_silent) {
                echo "Email Error: " . h($error);
            }
        }

    } else {
        if (!$is_silent) {
            echo "<h2 style='color:green; text-align:center;'>✅ All Stocks Good</h2>";
        }
    }

} catch (Exception $e) {
    if (!$is_silent) echo "System Error: " . $e->getMessage();
}
?>