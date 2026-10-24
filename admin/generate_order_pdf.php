<?php
// NOTE: This file requires the Dompdf library, installed via Composer.

// =======================================================================
// 🟢 TEMPORARY FIX: ENABLE FULL ERROR REPORTING FOR DEBUGGING 
// If you see a red screen or text, that text IS the error.
// =======================================================================
ini_set('display_errors', 1); 
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); 

// Paths (Corrected for config.php in project root)
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/../includes/auth.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Only admin can view this page
if (!is_admin()) {
    http_response_code(403);
    die("Access Denied.");
}

$orderId = intval($_GET['id'] ?? 0);
if (!$orderId) {
    http_response_code(400);
    die("Invalid Order ID.");
}

// --- Helper function for currency formatting (using ₱) ---
if (!function_exists('format_currency')) { 
    function format_currency($amount) {
        return '₱' . number_format($amount, 2);
    }
}

// =======================================================================
// 1. FETCH ALL REQUIRED DATA
// =======================================================================
try {
    // Fetch order and user details
    $stmtOrder = $pdo->prepare("
        SELECT 
            o.*, 
            o.phone_number AS order_phone_number, 
            u.name AS user_name, 
            u.email AS user_email, 
            u.role
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmtOrder->execute([$orderId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        die("Order not found.");
    }
    
    // Fetch order items
    $stmtItems = $pdo->prepare("
        SELECT oi.*, p.name 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch delivery details
    $stmtDelivery = $pdo->prepare("SELECT * FROM deliveries WHERE order_id = ?");
    $stmtDelivery->execute([$orderId]);
    $delivery = $stmtDelivery->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("PDF Data Fetch Error: " . $e->getMessage());
    http_response_code(500);
    die("Database error while fetching order data: " . $e->getMessage()); 
}

// Set fallback name/email for guest orders
$order['user_name'] = $order['user_name'] ?? htmlspecialchars($order['customer_name']) . ' (Guest)';
$order['user_email'] = $order['user_email'] ?? 'N/A';


// =======================================================================
// 🔑 NEW LOGIC: EXTRACT PHONE NUMBER FROM NOTES IF MAIN FIELD IS EMPTY
// =======================================================================

$phone_display_value = $order['order_phone_number'];

// 1. Check if the dedicated database field is empty.
if (empty($phone_display_value) && !empty($order['notes'])) {
    // 2. Search the 'notes' text for 'Buyer Phone: [The number]'
    // The regex /Buyer Phone: (\S+)/i looks for "Buyer Phone: " followed by 
    // any non-whitespace characters (\S+), capturing the number.
    if (preg_match('/Buyer Phone: (\S+)/i', $order['notes'], $matches)) {
        // 3. If a match is found, use the captured number (index 1 of $matches)
        $phone_display_value = $matches[1];
    }
}

$phone_display = htmlspecialchars($phone_display_value ?? 'N/A');

$delivery_date_display = $delivery['delivery_date'] ? date("F j, Y", strtotime($delivery['delivery_date'])) : 'N/A';


// --- Status Badge Helper (Unchanged) ---
function get_status_badge_html($status) {
    $status = strtolower($status);
    $text_status = ucfirst($status);
    $bg_class = 'background-color: #6c757d; color: #fff;'; 

    if ($status === 'delivered' || $status === 'completed') {
        $bg_class = 'background-color: #198754; color: #fff;';
    } elseif ($status === 'preparing' || $status === 'shipped') {
        $bg_class = 'background-color: #0dcaf0; color: #000;';
    } elseif ($status === 'pending') {
        $bg_class = 'background-color: #ffc107; color: #000;';
    } elseif ($status === 'cancelled') {
        $bg_class = 'background-color: #dc3545; color: #fff;';
    }

    return '<span style="display: inline-block; padding: 2px 5px; border-radius: 3px; font-size: 12px; font-weight: bold; ' . $bg_class . '">' . $text_status . '</span>';
}


// =======================================================================
// 2. BUILD HTML CONTENT FOR PDF 
// =======================================================================

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Order Invoice #' . $orderId . '</title>
    <style>
        /* ADDED FONT-FAMILY DEJAVU SANS FOR WIDER UNICODE (₱) SUPPORT */
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.5; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0d6efd; padding-bottom: 10px; }
        .header h1 { color: #0d6efd; margin: 0; font-size: 24px; }
        .order-info { margin-bottom: 20px; border: 1px solid #ccc; padding: 10px; border-radius: 5px; }
        .order-info h2 { font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 0; }
        .col-half { width: 48%; float: left; }
        .col-half.right { margin-left: 4%; }
        .clearfix::after { content: ""; clear: both; display: table; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row td { border-top: 3px double #000; font-weight: bold; background-color: #f9f9f9; }
    </style>
</head>
<body>
    <div class="header">
        <h1>SWEEPXPRESS Order Invoice</h1>
        <p>Order ID: <strong>' . htmlspecialchars($orderId) . '</strong> | Generated: ' . date('Y-m-d H:i:s') . '</p>
    </div>

    <div class="order-info clearfix">
        <div class="col-half">
            <h2>Order Summary</h2>
            <p><strong>Date Placed:</strong> ' . date("F j, Y h:i A", strtotime($order['created_at'])) . '</p>
            <p><strong>Payment Method:</strong> ' . htmlspecialchars($order['payment_terms'] ?? $order['payment_method']) . '</p>
            <h3 style="color: #198754;">Total Amount: ' . format_currency($order['total']) . '</h3>
        </div>
        
        <div class="col-half right">
            <h2>Customer & Delivery</h2>
            <p><strong>Customer:</strong> ' . htmlspecialchars($order['user_name']) . '</p>
            <p><strong>Email:</strong> ' . htmlspecialchars($order['user_email']) . '</p>
            <p><strong>Name on Order:</strong> ' . htmlspecialchars($order['customer_name']) . '</p>
            <p><strong>Phone Number:</strong> ' . $phone_display . '</p> <p><strong>Delivery Address:</strong> ' . nl2br(htmlspecialchars($order['address'])) . '</p>
            <p><strong>Delivery Date:</strong> ' . $delivery_date_display . '</p>
        </div>
    </div>

    <h2>Items Ordered</h2>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th class="text-right">Unit Price</th>
                <th class="text-center">Quantity</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>';

if (empty($items)) {
    $html .= '<tr><td colspan="4" class="text-center">No items found for this order.</td></tr>';
} else {
    $itemTotal = 0;
    foreach ($items as $item) {
        $subtotal = $item['price'] * $item['quantity'];
        $itemTotal += $subtotal;
        $html .= '
            <tr>
                <td>' . htmlspecialchars($item['name']) . '</td>
                <td class="text-right">' . format_currency($item['price']) . '</td>
                <td class="text-center">' . htmlspecialchars($item['quantity']) . '</td>
                <td class="text-right">' . format_currency($subtotal) . '</td>
            </tr>';
    }
    
    // Add total row
    $html .= '
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" class="text-right">GRAND TOTAL</td>
                <td class="text-right" style="color: #198754; font-size: 14px;">' . format_currency($order['total']) . '</td>
            </tr>
        </tfoot>';
}

$html .= '
    </table>

    <div style="margin-top: 30px; border-top: 1px solid #ccc; padding-top: 10px;">
        <p><strong>Notes:</strong> ' . nl2br(htmlspecialchars($order['notes'] ?? 'N/A')) . '</p>
    </div>

</body>
</html>';


// =======================================================================
// 3. GENERATE PDF (With Critical Fixes for PDF Loading)
// =======================================================================

// 🛑 CRITICAL FIX 1: Clean any stray output buffer contents.
// This prevents the "Failed to load PDF document" error.
if (ob_get_level() > 0) {
    ob_clean(); 
}

// 🛑 CRITICAL FIX 2: Turn off error reporting now that we are ready to output the PDF stream.
ini_set('display_errors', 0); 
error_reporting(0);


$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false); 

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output the generated PDF to the browser
$filename = 'SweepXpress_Invoice_' . $orderId . '_' . date('Ymd') . '.pdf';

$dompdf->stream($filename, ["Attachment" => true]);
exit;