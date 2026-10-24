<?php
// E:\xampp\htdocs\sweepxpress\admin\AdminReceipt.php

// 1. CONFIGURATION, AUTHENTICATION, AND UTILITIES
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php'; // Assuming is_admin() is here

// Enforce Admin Access
if (!function_exists('is_admin') || !is_admin()) {
    // Redirect non-admin users or guests
    header("Location: /sweepxpress/login.php");
    exit();
}

// Function to safely output text (Preventing redeclaration error)
if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Ensure the order_id is provided and is a number
if (!isset($_GET['order_id']) || !is_numeric($_GET['order_id'])) {
    die("Error: Invalid Order ID.");
}

$order_id = (int)$_GET['order_id'];

// 2. FETCH ORDER DATA (Admin-level detail fetch)
try {
    // Fetch main order and delivery details
    $stmtOrder = $pdo->prepare("
        SELECT 
            o.*, 
            u.email,
            d.delivery_date,
            d.status AS delivery_status
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN deliveries d ON o.id = d.order_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmtOrder->execute([$order_id]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        die("Order not found.");
    }
    
    // FIX: Join order_items with products to get the name and calculate subtotal
    $stmtItems = $pdo->prepare("
        SELECT 
            oi.quantity, 
            oi.price, 
            (oi.quantity * oi.price) AS subtotal,
            p.name AS product_name 
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$order_id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Determine the customer display name
$customer_name = h($order['customer_name'] ?: ($order['user_id'] ? "Registered User #{$order['user_id']}" : 'Guest Customer'));

// 💡 NEW LOGIC: Paid Stamp Display Logic
// Stamp is shown automatically if status is 'delivered' or 'completed'.
$is_paid_status = in_array(strtolower($order['status']), ['completed']);

// Stamp is ultimately shown if:
// 1. It's an automatically paid status, AND the user hasn't explicitly set stamped=false in the URL, OR
// 2. The user has explicitly set stamped=true in the URL (manual override).
$show_stamp = (isset($_GET['stamped']) && $_GET['stamped'] === 'true') || 
              ($is_paid_status && (!isset($_GET['stamped']) || $_GET['stamped'] !== 'false'));


// Base URL for links
$base_url = "?order_id={$order_id}";
$stamped_url = $base_url . "&stamped=true";
$unstamped_url = $base_url . "&stamped=false"; // Explicitly turn off the stamp

// Use JavaScript to print the current view
$print_script = "window.print();";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Receipt - Order #<?= h($order['id']) ?></title>
    <style>
        /* General Reset and Font */
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 20px; background: #f4f4f4; color: #333; }
        
        /* Receipt Box Style */
        .receipt-box { 
            position: relative; 
            max-width: 800px; 
            margin: 20px auto; 
            padding: 30px; 
            border: 1px solid #ddd; 
            background: #fff; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            font-size: 14px; 
            line-height: 1.6;
        }

        /* PAID Stamp Style */
        .paid-stamp {
            position: absolute;
            top: 30px; 
            right: 30px; 
            color: #10B981; 
            font-size: 3em;
            font-weight: 700;
            border: 4px solid #10B981;
            padding: 10px 20px;
            border-radius: 10px;
            transform: rotate(-15deg);
            opacity: 0.8;
            pointer-events: none;
            z-index: 100;
        }
        
        /* Header Style */
        .receipt-box .header { text-align: center; margin-bottom: 25px; }
        .receipt-box .header h1 { 
            margin: 0; 
            font-size: 28px; 
            color: #2563eb; 
            border-bottom: 2px solid #2563eb; 
            padding-bottom: 5px; 
            display: inline-block;
            font-weight: 700;
        }
        .receipt-box .header p { margin: 5px 0 0; font-size: 12px; color: #666; }

        /* Info Grid (Better layout) */
        .info-grid { display: flex; flex-wrap: wrap; justify-content: space-between; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 15px;}
        .info-col { width: 48%; min-width: 300px; margin-bottom: 10px; }
        .info-item { display: flex; margin-bottom: 5px; }
        .info-label { font-weight: bold; width: 140px; color: #555; flex-shrink: 0; }
        .info-value { flex-grow: 1; }
        
        /* Items Table */
        .receipt-box .items-table { width: 100%; border-collapse: collapse; margin-top: 25px; }
        .receipt-box .items-table th, .receipt-box .items-table td { 
            padding: 10px 15px; 
            text-align: left; 
            border-bottom: 1px dashed #ccc; 
        }
        .receipt-box .items-table th { 
            background-color: #f8f8f8; 
            font-weight: 700; 
            text-transform: uppercase; 
            font-size: 12px;
            border-bottom: 2px solid #ccc;
        }
        .receipt-box .text-right { text-align: right; }

        /* Summary Table */
        .receipt-box .summary-table { width: 320px; margin-top: 20px; margin-left: auto; font-size: 15px; }
        .receipt-box .summary-table td { padding: 6px 0; }
        .receipt-box .summary-table tr:last-child td { font-size: 1.2em; font-weight: 700; color: #2563eb; border-top: 2px solid #333; padding-top: 10px; }

        /* Footer and Sign-Off - SIMPLIFIED */
        .footer-section { 
            margin-top: 50px; 
            border-top: 1px solid #ddd; 
            padding-top: 20px; 
            font-size: 13px; 
            text-align: center; /* Center the final confirmation text */
        }
        .footer-section p { 
            font-style: italic; 
            margin-top: 5px; 
            color: #666; 
        }

        /* Print Controls (Visible only when not printing) */
        .print-controls {
            text-align: center; 
            margin-top: 20px; 
            padding: 15px; 
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .print-controls .action-link {
            padding: 10px 15px;
            margin: 0 10px;
            text-decoration: none;
            color: #fff;
            border-radius: 5px;
            font-size: 14px;
            display: inline-block;
            border: none;
            cursor: pointer;
        }
        .print-controls .action-link.print {
            background-color: #333;
        }
        .print-controls .action-link.stamp-link {
            background-color: #10B981;
        }
        .print-controls .action-link.back {
            background-color: #64748b; 
        }
        
        @media print {
            .print-controls { display: none; }
            .receipt-box { box-shadow: none; border: none; padding: 0; max-width: 100%; margin: 0; }
            body { background: #fff; padding: 0; }
            .paid-stamp { 
                border-color: #10B981 !important; 
                color: #10B981 !important;
            }
        }
    </style>
</head>
<body>

<div class="print-controls">
    <p style="margin-bottom: 10px; font-weight: bold; color: #555;">Select the desired action/view:</p>
    
    <button class="action-link back" onclick="window.history.back()">
        &laquo; Back to List
    </button>
    
    <button class="action-link print" onclick="<?= $print_script ?>">Print Current View (<?php echo $show_stamp ? 'Stamped' : 'Unstamped'; ?>)</button>

    <?php if ($show_stamp): ?>
        <a href="<?= $unstamped_url ?>" class="action-link" style="background-color: #eab308;">View Unstamped</a>
    <?php else: ?>
        <a href="<?= $stamped_url ?>" class="action-link stamp-link">View with PAID Stamp</a>
    <?php endif; ?>
</div>

<div class="receipt-box">
    
    <?php if ($show_stamp): ?>
    <div class="paid-stamp">PAID</div>
    <?php endif; ?>

    <div class="header">
        <h1>INTERNAL SALES ACKNOWLEDGEMENT</h1>
        <p>[Your Company Name] | [Your Company Address] | [Your Contact Number]</p>
    </div>

    <div class="info-grid">
        <div class="info-col">
            <div class="info-item">
                <span class="info-label">Customer Name:</span>
                <span class="info-value"><strong><?= $customer_name ?></strong></span>
            </div>
            <div class="info-item">
                <span class="info-label">Contact Email:</span>
                <span class="info-value"><?= h($order['email'] ?? 'N/A') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Delivery Address:</span>
                <span class="info-value"><?= h($order['address'] ?? 'N/A') ?></span>
            </div>
        </div>
        
        <div class="info-col">
            <div class="info-item">
                <span class="info-label">Order ID:</span>
                <span class="info-value">**#<?= h($order['id']) ?>**</span>
            </div>
            <div class="info-item">
                <span class="info-label">Order Date:</span>
                <span class="info-value"><?= date("M d, Y", strtotime($order['created_at'])) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Delivery Date:</span>
                <span class="info-value"><?= $order['delivery_date'] ? date("M d, Y", strtotime($order['delivery_date'])) : 'N/A (Not Scheduled)' ?></span>
            </div>
        </div>
    </div>

    <table class="items-table">
        <thead>
            <tr>
                <th>Item/Service</th>
                <th class="text-right" style="width: 100px;">Price</th>
                <th class="text-right" style="width: 80px;">Qty</th>
                <th class="text-right" style="width: 120px;">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?= h($item['product_name'] ?? 'N/A') ?></td>
                <td class="text-right"><?= number_format($item['price'], 2) ?></td>
                <td class="text-right"><?= h($item['quantity']) ?></td>
                <td class="text-right"><?= number_format($item['subtotal'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td>Subtotal:</td>
            <td class="text-right"><?= number_format($order['total'] - ($order['shipping_fee'] ?? 0), 2) ?></td>
        </tr>
        <tr>
            <td>Shipping Fee:</td>
            <td class="text-right"><?= number_format($order['shipping_fee'] ?? 0, 2) ?></td>
        </tr>
        <tr>
            <td>Payment Method:</td>
            <td class="text-right"><?= h($order['payment_method'] ?? 'N/A') ?></td>
        </tr>
        <tr>
            <td>GRAND TOTAL:</td>
            <td class="text-right">₱<?= number_format($order['total'], 2) ?></td>
        </tr>
    </table>

    <div style="clear: both;"></div>

    <div class="footer-section">
        <p>
            *** End of Internal Sales Acknowledgment ***
        </p>
    </div>
</div>

</body>
</html>