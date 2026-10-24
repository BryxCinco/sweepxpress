<?php
// NOTE: This file requires the Dompdf library, installed via Composer.

require_once __DIR__ . '/../vendor/autoload.php'; 
require_once __DIR__ . '/../config.php'; // Corrected path: Assumes config.php is in the project root
require_once __DIR__ . '/../includes/auth.php';


use Dompdf\Dompdf;
use Dompdf\Options;

// Only admin can view this page
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// --- FILTER HANDLING ---
$status = $_GET['status'] ?? 'all'; 
$filter_year = $_GET['year'] ?? '';
$filter_month = $_GET['month'] ?? '';

// 1. Build the dynamic SQL query
$query = "SELECT * FROM orders WHERE 1=1";
$params = [];
$report_title_suffix = "";

// Status filter
if ($status !== 'all') {
    $valid_statuses = ['pending', 'shipped', 'delivered', 'cancelled', 'cancellation_requested']; 
    if (in_array($status, $valid_statuses)) {
        $query .= " AND status = :status";
        $params[':status'] = $status;
        $report_title_suffix .= " - " . ucfirst(str_replace('_', ' ', $status));
    } else {
        $status = 'all'; 
    }
}

// Year filter (using 'created_at')
if (!empty($filter_year) && is_numeric($filter_year)) {
    $query .= " AND YEAR(created_at) = :year";
    $params[':year'] = $filter_year;
    $report_title_suffix .= " for Year: " . $filter_year;
}

// Month filter (using 'created_at')
if (!empty($filter_month) && is_numeric($filter_month) && $filter_month >= 1 && $filter_month <= 12) {
    $query .= " AND MONTH(created_at) = :month";
    $params[':month'] = $filter_month;
    $monthName = date('F', mktime(0, 0, 0, $filter_month, 10)); // Get month name
    $report_title_suffix .= " (" . $monthName . ")";
}

$query .= " ORDER BY created_at DESC";

// Execute the query
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage()); 
}

// --- 2. Build the HTML content for the PDF ---

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Order Report</title>
    <style>
        body { font-family: sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        h1 { color: #0d6efd; }
        .status-badge { 
            display: inline-block; 
            padding: 2px 5px; 
            border-radius: 3px; 
            font-size: 10px; 
            color: #fff;
            text-transform: uppercase;
        }
        .bg-success { background-color: #198754; }
        .bg-info { background-color: #0dcaf0; color: #000!important; }
        .bg-warning { background-color: #ffc107; color: #000!important; }
        .bg-danger { background-color: #dc3545; }
        .bg-secondary { background-color: #6c757d; }
    </style>
</head>
<body>
    <h1>SweepXpress Order Report</h1>
    <p><strong>Report Filters:</strong> ' . (empty($report_title_suffix) ? 'All Orders' : trim($report_title_suffix)) . '</p>
    <p><strong>Generated On:</strong> ' . date('Y-m-d H:i:s') . '</p>
    
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Order Date</th>
                <th>Status</th>
                <th>Total ($)</th>
                <th>Customer ID</th>
            </tr>
        </thead>
        <tbody>';

if (empty($orders)) {
    $html .= '<tr><td colspan="5">No orders found matching the filter criteria.</td></tr>';
} else {
    foreach ($orders as $order) {
        $displayStatus = $order['status'];
        $badgeClass = 'bg-secondary';
        
        if ($displayStatus == 'delivered') {
            $badgeClass = 'bg-success';
        } elseif ($displayStatus == 'shipped') {
            $displayStatus = 'Preparing'; 
            $badgeClass = 'bg-info';
        } elseif ($displayStatus == 'pending') {
            $badgeClass = 'bg-warning';
        } elseif ($displayStatus == 'cancelled' || $displayStatus == 'cancellation_requested') {
            $badgeClass = 'bg-danger';
        }

        $html .= '
            <tr>
                <td>' . htmlspecialchars($order['id']) . '</td>
                <td>' . htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))) . '</td>
                <td><span class="status-badge ' . $badgeClass . '">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $displayStatus))) . '</span></td>
                <td>' . htmlspecialchars(number_format($order['total'] ?? 0, 2)) . '</td>
                <td>' . htmlspecialchars($order['user_id']) . '</td>
            </tr>';
    }
}

$html .= '
        </tbody>
    </table>
</body>
</html>';


// --- 3. Initialize and configure Dompdf ---

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false); 

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

$dompdf->setPaper('A4', 'portrait');

// Render the HTML as PDF
$dompdf->render();

// Output the generated PDF to the browser
$filename = 'Order_Report' . (empty($report_title_suffix) ? '' : $report_title_suffix) . '_' . date('Ymd_His') . '.pdf';
$filename = str_replace(' ', '_', trim($filename));

$dompdf->stream($filename, ["Attachment" => true]);
exit;