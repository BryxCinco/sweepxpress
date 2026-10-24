<?php
// purchaseorder.php - ENTERPRISE B2B PO SYSTEM
// Responsive 320px–2560px | Light Mode | Human-crafted

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/send_email.php';
// -------------------------------------------------
// INITIAL STATE / SECURITY
// -------------------------------------------------
$message = '';
$user = $_SESSION['user'] ?? null;
$userId = $user['id'] ?? null;
$order_submitted = false;

// Role check
if (!$user || ($user['role'] !== 'business' && $user['role'] !== 'admin')) {
    $_SESSION['error_message'] = "You must be logged in as a Business or Admin to create a Purchase Order.";
    header("Location: /sweepxpress/login.php");
    exit;
}

// -------------------------------------------------
// CART LOAD
// -------------------------------------------------
$cart  = $_SESSION['cart'] ?? [];
$items = [];
$total = 0;

if ($cart) {
    $ids  = implode(',', array_map('intval', array_keys($cart)));
    $stmt = $pdo->query("SELECT * FROM products WHERE id IN ($ids)");

    foreach ($stmt as $row) {
        $qty = (int)($cart[$row['id']] ?? 0);
        if ($qty > 0) {
            $subtotal = $qty * (float)$row['price'];
            $items[]  = ['p' => $row, 'qty' => $qty, 'subtotal' => $subtotal];
            $total   += $subtotal;
        }
    }
}

// Empty cart protection
if (!$items && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['info_message'] = "Your cart is empty. Please add items to create a Purchase Order.";
    header("Location: /sweepxpress/cart.php");
    exit;
}

// -------------------------------------------------
// HANDLE PURCHASE ORDER SUBMISSION
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$order_submitted) {

    $companyName    = trim($_POST['company_name'] ?? '');
    $contactName    = trim($_POST['contact_name'] ?? '');
    $email          = $user['email'] ?? '';
    $phoneNumber    = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
    $billingAddress = trim($_POST['billing_address'] ?? '');
    $shippingAddress = trim($_POST['shipping_address'] ?? $billingAddress);
    $paymentTerms   = trim($_POST['payment_terms'] ?? '');

    if (empty($companyName) || empty($contactName) || empty($billingAddress) || empty($paymentTerms)) {
        $message = 'error|Please fill in all required fields marked with *';
    } elseif ($items) {

        $business_details_note =
            "--- PURCHASE ORDER REQUEST DETAILS ---\n" .
            "Buyer Company Name: " . $companyName . "\n" .
            "Buyer Contact Name: " . $contactName . "\n" .
            "Buyer Email: " . $email . "\n" .
            "Buyer Phone: " . (!empty($phoneNumber) ? $phoneNumber : 'N/A') . "\n" .
            "Billing Address: " . $billingAddress . "\n" .
            "------------------------------------";

        $pdo->beginTransaction();
        try {
            // Create order
            $stmt = $pdo->prepare("
                INSERT INTO orders (user_id, total, address, customer_name, notes, payment_terms, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending PO', NOW())
            ");

            $stmt->execute([
                $userId,
                $total,
                $shippingAddress,
                $contactName,
                $business_details_note,
                $paymentTerms
            ]);

            $orderId = $pdo->lastInsertId();

            // Order items
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, quantity, price)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($items as $it) {
                $itemStmt->execute([
                    $orderId,
                    $it['p']['id'],
                    $it['qty'],
                    $it['p']['price']
                ]);
            }

            // Stock deduction (location_id = 1)
            $getInventory        = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND location_id = 1 FOR UPDATE");
            $updateInventory     = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
            $insertStockHistory  = $pdo->prepare("INSERT INTO product_stock_history (product_id, action, quantity, note, created_at) VALUES (?, 'OUT', ?, ?, NOW())");
            $insertStockMovement = $pdo->prepare("INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at) VALUES (?, 1, 'OUT', ?, ?, ?, ?, NOW())");

            $user_name = $user['name'] ?? 'B2B Customer';

            foreach ($items as $it) {
                $pid = (int)$it['p']['id'];
                $qty = (int)$it['qty'];

                $getInventory->execute([$pid]);
                $inv = $getInventory->fetch(PDO::FETCH_ASSOC);
                $getInventory->closeCursor();

                if ($inv) {
                    $invId  = $inv['id'];
                    $newQty = max(0, (int)$inv['quantity'] - $qty);

                    $updateInventory->execute([$newQty, $invId]);

                    $note = "Purchase Order #$orderId - $companyName";
                    $insertStockHistory->execute([$pid, $qty, $note]);
                    $insertStockMovement->execute([$pid, $qty, $note, $user_name, $userId]);
                }
            }

            $pdo->commit();

            // --- SEND EMAIL NOTIFICATION ---
            $customer_name  = $contactName; 
            $customer_email = $email ?? '';

            if ($customer_email) {
                require_once __DIR__ . '/../includes/send_email.php'; // siguraduhin naka-include

                $subject = "Your Purchase Order #$orderId Has Been Submitted!";
                $body = "
                    <h2>Thank you for your Purchase Order!</h2>
                    <p>Hello <b>$customer_name</b>,</p>
                    <p>Your Purchase Order has been successfully submitted and is now pending review.</p>
                    <p><b>PO Number:</b> $orderId</p>
                    <p>Total: <b>₱" . number_format($total, 2) . "</b></p>
                    <br>
                    <p>You can track the status of your order on your account dashboard.</p>
                    <br>
                    <p>Thank you for doing business with SweepXpress!</p>
                ";

                send_email($customer_email, $subject, $body);
            }

            // Silent AI stock alert
            try {
                $silent_mode   = true;
                $ai_alert_path = __DIR__ . '/../admin/cron_stock_alert.php';
                if (file_exists($ai_alert_path)) {
                    include $ai_alert_path;
                }
            } catch (Exception $e) {
                error_log("AI PO Alert Error: " . $e->getMessage());
            }

            unset($_SESSION['cart']);
            $order_submitted = true;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Purchase Order Error: " . $e->getMessage());
            $message = 'error|An error occurred: ' . $e->getMessage();
        }
    } else {
        $message = 'warning|The cart was empty during submission.';
    }
}

// Safe HTML helper if not globally defined
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Purchase Order - SweepXpress</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
/* =====================================================
   ENTERPRISE PURCHASE ORDER - LIGHT MODE
   ===================================================== */
:root {
    --white:#ffffff; --black:#000000;
    --gray-25:#fcfcfd; --gray-50:#f9fafb; --gray-100:#f3f4f6; --gray-200:#e5e7eb;
    --gray-300:#d1d5db; --gray-400:#9ca3af; --gray-500:#6b7280; --gray-600:#4b5563;
    --gray-700:#374151; --gray-800:#1f2937; --gray-900:#111827;
    --primary-50:#eff6ff; --primary-100:#dbeafe; --primary-200:#bfdbfe;
    --primary-500:#3b82f6; --primary-600:#2563eb; --primary-700:#1d4ed8;
    --success-50:#f0fdf4; --success-100:#dcfce7; --success-500:#22c55e;
    --success-600:#16a34a; --success-700:#15803d;
    --warning-50:#fffbeb; --warning-500:#f59e0b; --warning-600:#d97706;
    --error-50:#fef2f2; --error-100:#fee2e2; --error-500:#ef4444; --error-600:#dc2626;

    --space-1:0.25rem; --space-2:0.5rem; --space-3:0.75rem;
    --space-4:1rem; --space-5:1.25rem; --space-6:1.5rem;
    --space-8:2rem; --space-10:2.5rem; --space-12:3rem; --space-16:4rem;

    --text-xs:0.75rem; --text-sm:0.875rem; --text-base:1rem;
    --text-lg:1.125rem; --text-xl:1.25rem; --text-2xl:1.5rem; --text-3xl:1.875rem;

    --radius-sm:6px; --radius-md:8px; --radius-lg:12px;
    --radius-xl:16px; --radius-2xl:20px; --radius-full:9999px;

    --shadow-xs:0 1px 2px rgba(0,0,0,0.05);
    --shadow-sm:0 1px 3px rgba(0,0,0,0.1),0 1px 2px rgba(0,0,0,0.06);
    --shadow-md:0 4px 6px -1px rgba(0,0,0,0.1),0 2px 4px -2px rgba(0,0,0,0.1);
    --shadow-lg:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);

    --transition-fast:150ms cubic-bezier(0.4,0,0.2,1);
    --transition-base:200ms cubic-bezier(0.4,0,0.2,1);

    --container-max:880px;
    --header-height:64px;
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{font-size:16px;-webkit-text-size-adjust:100%;scroll-behavior:smooth;}
body{
    font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    font-size:var(--text-base);line-height:1.6;color:var(--gray-800);
    background:linear-gradient(180deg,var(--gray-50) 0%,var(--white) 50%);
    min-height:100vh;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;
}

/* Layout */
.po-page{width:100%;min-height:calc(100vh - var(--header-height));padding:var(--space-6) var(--space-4);}
.po-container{width:100%;max-width:var(--container-max);margin:0 auto;}

/* Header */
.po-header{margin-bottom:var(--space-8);}
.po-breadcrumb{display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm);color:var(--gray-500);margin-bottom:var(--space-4);}
.po-breadcrumb a{color:var(--primary-600);text-decoration:none;font-weight:500;transition:color var(--transition-fast);}
.po-breadcrumb a:hover{color:var(--primary-700);text-decoration:underline;}
.po-breadcrumb i{font-size:12px;color:var(--gray-400);}

.po-title-row{display:flex;align-items:flex-start;gap:var(--space-4);}
.po-icon{width:48px;height:48px;background:linear-gradient(135deg,var(--primary-500),var(--primary-700));border-radius:var(--radius-lg);display:flex;align-items:center;justify-content:center;color:var(--white);font-size:var(--text-xl);flex-shrink:0;box-shadow:0 4px 12px rgba(37,99,235,0.3);}
.po-title{font-size:var(--text-2xl);font-weight:700;color:var(--gray-900);line-height:1.3;margin-bottom:var(--space-1);}
.po-subtitle{font-size:var(--text-sm);color:var(--gray-500);}

/* Progress */
.po-progress{display:flex;align-items:center;justify-content:center;background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius-xl);padding:var(--space-4) var(--space-6);margin-bottom:var(--space-6);box-shadow:var(--shadow-xs);}
.po-step{display:flex;align-items:center;gap:var(--space-2);}
.po-step-circle{width:28px;height:28px;border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;font-size:var(--text-xs);font-weight:600;transition:all var(--transition-base);}
.po-step-label{font-size:var(--text-sm);font-weight:500;color:var(--gray-400);}
.po-step-line{width:48px;height:2px;background:var(--gray-200);margin:0 var(--space-3);}
.po-step-line.done{background:var(--success-500);}
.po-step.completed .po-step-circle{background:var(--success-500);color:#fff;}
.po-step.active .po-step-circle{background:var(--primary-600);color:#fff;box-shadow:0 0 0 4px var(--primary-100);}
.po-step.pending .po-step-circle{background:var(--gray-200);color:var(--gray-500);}
.po-step.completed .po-step-label{color:var(--gray-600);}
.po-step.active .po-step-label{color:var(--gray-900);}

/* Alerts */
.po-alert{display:flex;align-items:flex-start;gap:var(--space-3);padding:var(--space-4);border-radius:var(--radius-lg);margin-bottom:var(--space-6);font-size:var(--text-sm);}
.po-alert-icon{width:20px;height:20px;flex-shrink:0;margin-top:1px;}
.po-alert-error{background:var(--error-50);border:1px solid var(--error-100);color:var(--error-600);}
.po-alert-warning{background:var(--warning-50);border:1px solid #fde68a;color:var(--warning-600);}

/* Card */
.po-card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius-xl);box-shadow:var(--shadow-sm);margin-bottom:var(--space-6);overflow:hidden;transition:box-shadow var(--transition-base);}
.po-card:hover{box-shadow:var(--shadow-md);}
.po-card-header{display:flex;align-items:center;gap:var(--space-3);padding:var(--space-5) var(--space-6);background:var(--gray-50);border-bottom:1px solid var(--gray-200);}
.po-card-icon{width:40px;height:40px;background:var(--primary-600);border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;color:#fff;font-size:var(--text-lg);flex-shrink:0;}
.po-card-title{font-size:var(--text-base);font-weight:600;color:var(--gray-900);}
.po-card-desc{font-size:var(--text-xs);color:var(--gray-500);margin-top:2px;}
.po-card-body{padding:var(--space-6);}

/* Forms */
.po-form-grid{display:grid;gap:var(--space-5);}
.po-form-row{display:grid;grid-template-columns:repeat(2,1fr);gap:var(--space-5);}
.po-form-group{display:flex;flex-direction:column;}
.po-form-group.full{grid-column:1 / -1;}
.po-label{display:flex;align-items:center;gap:var(--space-1);font-size:var(--text-sm);font-weight:600;color:var(--gray-700);margin-bottom:var(--space-2);}
.po-required{color:var(--error-500);font-weight:400;}
.po-optional{color:var(--gray-400);font-weight:400;font-size:var(--text-xs);}
.po-input,.po-textarea,.po-select{width:100%;height:48px;padding:0 var(--space-4);font-size:var(--text-base);font-family:inherit;color:var(--gray-900);background:#fff;border:1.5px solid var(--gray-300);border-radius:var(--radius-md);outline:none;transition:all var(--transition-fast);}
.po-input:hover:not(:disabled){border-color:var(--gray-400);}
.po-input:focus{border-color:var(--primary-500);box-shadow:0 0 0 3px var(--primary-100);}
.po-input:disabled{background:var(--gray-100);color:var(--gray-500);cursor:not-allowed;}
.po-textarea{height:auto;min-height:100px;padding:var(--space-3) var(--space-4);resize:vertical;line-height:1.5;}
.po-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 10.5L3.5 6h9L8 10.5z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:44px;cursor:pointer;}
.po-hint{display:flex;align-items:center;gap:var(--space-1);font-size:var(--text-xs);color:var(--gray-500);margin-top:var(--space-2);}
.po-hint i{font-size:12px;color:var(--gray-400);}
.po-input-wrap{position:relative;}
.po-input-icon{position:absolute;right:var(--space-4);top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:var(--text-sm);}

/* Table */
.po-table-wrap{border:1px solid var(--gray-200);border-radius:var(--radius-lg);overflow:hidden;}
.po-table{width:100%;border-collapse:collapse;font-size:var(--text-sm);}
.po-table thead{background:var(--gray-50);}
.po-table th{padding:var(--space-4);font-size:var(--text-xs);font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--gray-600);text-align:left;border-bottom:1px solid var(--gray-200);}
.po-table th.text-center{text-align:center;}
.po-table th.text-right{text-align:right;}
.po-table td{padding:var(--space-4);color:var(--gray-700);border-bottom:1px solid var(--gray-100);vertical-align:middle;}
.po-table tbody tr:last-child td{border-bottom:none;}
.po-table tbody tr:hover{background:var(--gray-25);}
.po-table .product-name{font-weight:600;color:var(--gray-900);}
.po-table .text-center{text-align:center;}
.po-table .text-right{text-align:right;}
.po-table tfoot{background:var(--primary-50);}
.po-table tfoot td{padding:var(--space-4);font-weight:700;color:var(--primary-700);border-bottom:none;}

/* Buttons */
.po-actions{display:flex;flex-direction:column;gap:var(--space-3);margin-top:var(--space-6);}
.po-btn{display:inline-flex;align-items:center;justify-content:center;gap:var(--space-2);height:52px;padding:0 var(--space-6);font-size:var(--text-base);font-weight:600;font-family:inherit;text-decoration:none;border-radius:var(--radius-lg);border:none;cursor:pointer;transition:all var(--transition-fast);outline:none;}
.po-btn:focus-visible{box-shadow:0 0 0 3px var(--primary-200);}
.po-btn-primary{background:linear-gradient(135deg,var(--primary-600),var(--primary-700));color:#fff;box-shadow:var(--shadow-md),0 4px 14px rgba(37,99,235,.35);}
.po-btn-primary:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg),0 8px 20px rgba(37,99,235,.4);}
.po-btn-primary:active{transform:translateY(0);}
.po-btn-secondary{background:#fff;color:var(--gray-700);border:1.5px solid var(--gray-300);}
.po-btn-secondary:hover{background:var(--gray-50);border-color:var(--gray-400);}
.po-btn-success{background:linear-gradient(135deg,var(--success-500),var(--success-600));color:#fff;box-shadow:var(--shadow-md),0 4px 14px rgba(34,197,94,.35);}
.po-btn-success:hover{transform:translateY(-2px);box-shadow:var(--shadow-lg),0 8px 20px rgba(34,197,94,.4);}

/* Success */
.po-success{text-align:center;padding:var(--space-12) var(--space-6);}
.po-success-icon{width:88px;height:88px;background:linear-gradient(135deg,var(--success-500),var(--success-600));border-radius:var(--radius-full);display:flex;align-items:center;justify-content:center;margin:0 auto var(--space-6);box-shadow:0 12px 32px rgba(34,197,94,.35);animation:successPop .5s cubic-bezier(.175,.885,.32,1.275);}
@keyframes successPop{0%{transform:scale(0);opacity:0;}100%{transform:scale(1);opacity:1;}}
.po-success-icon i{font-size:40px;color:#fff;}
.po-success-title{font-size:var(--text-2xl);font-weight:700;color:var(--gray-900);margin-bottom:var(--space-2);}
.po-success-order{font-size:var(--text-3xl);font-weight:700;color:var(--primary-600);margin:var(--space-4) 0;}
.po-success-text{font-size:var(--text-base);color:var(--gray-600);margin-bottom:var(--space-8);line-height:1.6;}
.po-success-actions{display:flex;flex-direction:column;gap:var(--space-3);max-width:360px;margin:0 auto;}

/* Skeleton */
.po-skeleton{display:block;}
.sk-animate{background:linear-gradient(90deg,var(--gray-200) 25%,var(--gray-100) 50%,var(--gray-200) 75%);background-size:200% 100%;animation:skeleton 1.5s ease-in-out infinite;border-radius:var(--radius-sm);}
@keyframes skeleton{0%{background-position:200% 0;}100%{background-position:-200% 0;}}
.sk-card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius-xl);padding:var(--space-6);margin-bottom:var(--space-6);}
.sk-header{display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-6);}
.sk-icon{width:40px;height:40px;border-radius:var(--radius-md);}
.sk-title{height:20px;width:140px;}
.sk-input{height:48px;margin-bottom:var(--space-4);}
.sk-row{display:grid;grid-template-columns:repeat(2,1fr);gap:var(--space-5);}
.po-content{display:none;}
body.po-ready .po-skeleton{display:none;}
body.po-ready .po-content{display:block;}

/* Responsive */
@media(max-width:768px){
    :root{--space-6:1.25rem;--space-8:1.5rem;}
    .po-page{padding:var(--space-5) var(--space-4);}
    .po-title{font-size:var(--text-xl);}
    .po-icon{width:44px;height:44px;font-size:var(--text-lg);}
    .po-progress{padding:var(--space-3) var(--space-4);}
    .po-step-label{display:none;}
    .po-step-line{width:32px;}
    .po-form-row{grid-template-columns:1fr;}
    .po-card-header{padding:var(--space-4) var(--space-5);}
    .po-card-body{padding:var(--space-5);}
    .po-table th,.po-table td{padding:var(--space-3);font-size:var(--text-xs);}
    .sk-row{grid-template-columns:1fr;}
}
@media(max-width:480px){
    :root{--text-2xl:1.375rem;--text-xl:1.125rem;}
    .po-page{padding:var(--space-4) var(--space-3);}
    .po-title-row{gap:var(--space-3);}
    .po-icon{width:40px;height:40px;font-size:var(--text-base);border-radius:var(--radius-md);}
    .po-breadcrumb{font-size:var(--text-xs);margin-bottom:var(--space-3);}
    .po-card{border-radius:var(--radius-lg);}
    .po-card-header{padding:var(--space-4);}
    .po-card-body{padding:var(--space-4);}
    .po-card-icon{width:36px;height:36px;font-size:var(--text-base);}
    .po-input{height:46px;font-size:16px;}
    .po-btn{height:50px;font-size:var(--text-sm);}
    .po-success{padding:var(--space-8) var(--space-4);}
    .po-success-icon{width:72px;height:72px;}
    .po-success-icon i{font-size:32px;}
    .po-success-title{font-size:var(--text-xl);}
    .po-success-order{font-size:var(--text-2xl);}
}
@media(max-width:360px){
    .po-page{padding:var(--space-3) var(--space-2);}
    .po-card-header{flex-wrap:wrap;gap:var(--space-2);}
    .po-table-wrap{margin:0 calc(var(--space-4) * -1);border-radius:0;border-left:none;border-right:none;}
    .po-progress{padding:var(--space-2) var(--space-3);}
    .po-step-circle{width:24px;height:24px;font-size:10px;}
    .po-step-line{width:20px;margin:0 var(--space-2);}
}
@media(min-width:1440px){
    :root{--container-max:920px;}
    .po-page{padding:var(--space-10) var(--space-6);}
}
@media(min-width:2560px){
    :root{--container-max:1000px;--text-base:1.0625rem;--text-sm:0.9375rem;}
    .po-page{padding:var(--space-12) var(--space-8);}
}

/* Print & Accessibility */
@media print{
    .po-page{padding:0;background:#fff;}
    .po-progress,.po-actions,.po-btn{display:none!important;}
    .po-card{box-shadow:none;border:1px solid #ddd;}
}
@media(prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;}
}
.po-input:focus-visible,.po-btn:focus-visible,.po-select:focus-visible{outline:2px solid var(--primary-500);outline-offset:2px;}
    </style>
</head>
<body>
<div class="po-page">
    <div class="po-container">

        <!-- Skeleton -->
        <div class="po-skeleton">
            <div class="sk-card">
                <div class="sk-header">
                    <div class="sk-animate sk-icon"></div>
                    <div class="sk-animate sk-title"></div>
                </div>
                <div class="sk-row">
                    <div class="sk-animate sk-input"></div>
                    <div class="sk-animate sk-input"></div>
                </div>
                <div class="sk-row">
                    <div class="sk-animate sk-input"></div>
                    <div class="sk-animate sk-input"></div>
                </div>
            </div>
            <div class="sk-card">
                <div class="sk-header">
                    <div class="sk-animate sk-icon"></div>
                    <div class="sk-animate sk-title"></div>
                </div>
                <div class="sk-animate sk-input"></div>
                <div class="sk-animate sk-input"></div>
            </div>
        </div>

        <!-- Content -->
        <div class="po-content">
            <!-- Header -->
            <div class="po-header">
                <nav class="po-breadcrumb">
                    <a href="/sweepxpress/cart.php">Cart</a>
                    <i class="bi bi-chevron-right"></i>
                    <span>Purchase Order</span>
                </nav>

                <div class="po-title-row">
                    <div class="po-icon"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="po-title-content">
                        <h1 class="po-title">Purchase Order Request</h1>
                        <p class="po-subtitle">
                            Complete your business order securely. Required fields marked with
                            <span style="color:var(--error-500)">*</span>
                        </p>
                    </div>
                </div>
            </div>

            <?php
            // Dynamic stepper states: if order_submitted, move to Confirm
            $step1Class = 'completed';
            $step2Class = $order_submitted ? 'completed' : 'active';
            $step3Class = $order_submitted ? 'active' : 'pending';

            $line1Done  = 'done';
            $line2Done  = $order_submitted ? 'done' : '';
            ?>

            <!-- Progress -->
            <div class="po-progress">
                <div class="po-step <?= $step1Class ?>">
                    <span class="po-step-circle"><i class="bi bi-check"></i></span>
                    <span class="po-step-label">Cart</span>
                </div>
                <div class="po-step-line <?= $line1Done ?>"></div>
                <div class="po-step <?= $step2Class ?>">
                    <span class="po-step-circle">
                        <?= $order_submitted ? '<i class="bi bi-check"></i>' : '2' ?>
                    </span>
                    <span class="po-step-label">Details</span>
                </div>
                <div class="po-step-line <?= $line2Done ?>"></div>
                <div class="po-step <?= $step3Class ?>">
                    <span class="po-step-circle">3</span>
                    <span class="po-step-label">Confirm</span>
                </div>
            </div>

            <!-- Top message -->
            <?php if ($message && !$order_submitted): ?>
                <?php
                    $parts      = explode('|', $message, 2);
                    $type       = $parts[0];
                    $text       = $parts[1] ?? '';
                    $icon_class = $type === 'error'
                        ? 'exclamation-triangle-fill'
                        : 'exclamation-circle-fill';
                ?>
                <div class="po-alert po-alert-<?= $type ?>">
                    <i class="bi bi-<?= $icon_class ?> po-alert-icon"></i>
                    <div class="po-alert-content">
                        <span class="po-alert-title"><?= h($text) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$order_submitted && $items): ?>

                <!-- FORM STATE -->
                <form method="POST" id="poForm" novalidate>
                    <!-- Buyer Info -->
                    <div class="po-card">
                        <div class="po-card-header">
                            <div class="po-card-icon"><i class="bi bi-building"></i></div>
                            <div>
                                <div class="po-card-title">Buyer Information</div>
                                <div class="po-card-desc">Enter your company and contact details</div>
                            </div>
                        </div>
                        <div class="po-card-body">
                            <div class="po-form-grid">
                                <div class="po-form-row">
                                    <div class="po-form-group">
                                        <label class="po-label" for="company_name">
                                            Company Name <span class="po-required">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="company_name"
                                            name="company_name"
                                            class="po-input"
                                            placeholder="e.g., ABC Corporation"
                                            value="<?= h($_POST['company_name'] ?? '') ?>"
                                            required
                                        >
                                        <span class="po-hint"><i class="bi bi-info-circle"></i> Your registered business name</span>
                                    </div>
                                    <div class="po-form-group">
                                        <label class="po-label" for="contact_name">
                                            Contact Person <span class="po-required">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="contact_name"
                                            name="contact_name"
                                            class="po-input"
                                            placeholder="e.g., Juan Dela Cruz"
                                            value="<?= h($_POST['contact_name'] ?? ($user['name'] ?? '')) ?>"
                                            required
                                        >
                                        <span class="po-hint"><i class="bi bi-info-circle"></i> Primary contact for this order</span>
                                    </div>
                                </div>
                                <div class="po-form-row">
                                    <div class="po-form-group">
                                        <label class="po-label" for="buyer_email">Email Address</label>
                                        <div class="po-input-wrap">
                                            <input
                                                type="email"
                                                id="buyer_email"
                                                class="po-input"
                                                value="<?= h($user['email'] ?? '') ?>"
                                                disabled
                                            >
                                            <i class="bi bi-lock po-input-icon"></i>
                                        </div>
                                        <span class="po-hint"><i class="bi bi-shield-check"></i> Your registered email (secured)</span>
                                    </div>
                                    <div class="po-form-group">
                                        <label class="po-label" for="phone_number">
                                            Phone Number <span class="po-optional">(Optional)</span>
                                        </label>
                                        <input
                                            type="tel"
                                            id="phone_number"
                                            name="phone_number"
                                            class="po-input"
                                            placeholder="e.g., 09171234567"
                                            pattern="[0-9]{7,15}"
                                            title="7-15 digits"
                                            value="<?= h($_POST['phone_number'] ?? '') ?>"
                                        >
                                        <span class="po-hint"><i class="bi bi-info-circle"></i> For delivery updates</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Shipping & Payment -->
                    <div class="po-card">
                        <div class="po-card-header">
                            <div class="po-card-icon"><i class="bi bi-geo-alt"></i></div>
                            <div>
                                <div class="po-card-title">Shipping & Payment</div>
                                <div class="po-card-desc">Delivery location and payment terms</div>
                            </div>
                        </div>
                        <div class="po-card-body">
                            <div class="po-form-grid">
                                <div class="po-form-row">
                                    <div class="po-form-group">
                                        <label class="po-label" for="billing_address">
                                            Billing Address <span class="po-required">*</span>
                                        </label>
                                        <textarea
                                            id="billing_address"
                                            name="billing_address"
                                            class="po-input po-textarea"
                                            placeholder="Street, Barangay, City, Province, ZIP"
                                            required
                                        ><?= h($_POST['billing_address'] ?? '') ?></textarea>
                                        <span class="po-hint"><i class="bi bi-info-circle"></i> Complete address for invoice</span>
                                    </div>
                                    <div class="po-form-group">
                                        <label class="po-label" for="shipping_address">
                                            Shipping Address <span class="po-optional">(Optional)</span>
                                        </label>
                                        <textarea
                                            id="shipping_address"
                                            name="shipping_address"
                                            class="po-input po-textarea"
                                            placeholder="Leave blank if same as billing"
                                        ><?= h($_POST['shipping_address'] ?? '') ?></textarea>
                                        <span class="po-hint"><i class="bi bi-info-circle"></i> If different from billing</span>
                                    </div>
                                </div>
                                <div class="po-form-row">
                                    <div class="po-form-group full">
                                        <label class="po-label" for="payment_terms">
                                            Payment Terms <span class="po-required">*</span>
                                        </label>
                                        <select
                                            id="payment_terms"
                                            name="payment_terms"
                                            class="po-input po-select"
                                            required
                                        >
                                            <?php $selectedTerm = $_POST['payment_terms'] ?? ''; ?>
                                            <option value="" disabled <?= empty($selectedTerm) ? 'selected' : '' ?>>
                                                -- Select payment terms --
                                            </option>
                                            <option value="Net 30" <?= $selectedTerm === 'Net 30' ? 'selected' : '' ?>>
                                                Net 30 — Payment due within 30 days
                                            </option>
                                            <option value="Net 45" <?= $selectedTerm === 'Net 45' ? 'selected' : '' ?>>
                                                Net 45 — Payment due within 45 days
                                            </option>
                                            <option value="Net 60" <?= $selectedTerm === 'Net 60' ? 'selected' : '' ?>>
                                                Net 60 — Payment due within 60 days
                                            </option>
                                            <option value="Upon Delivery" <?= $selectedTerm === 'Upon Delivery' ? 'selected' : '' ?>>
                                                COD — Cash on Delivery
                                            </option>
                                        </select>
                                        <span class="po-hint"><i class="bi bi-info-circle"></i> When payment is expected</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Summary -->
                    <div class="po-card">
                        <div class="po-card-header">
                            <div class="po-card-icon"><i class="bi bi-cart-check"></i></div>
                            <div>
                                <div class="po-card-title">Order Summary</div>
                                <div class="po-card-desc">
                                    <?= count($items) ?> item<?= count($items) > 1 ? 's' : '' ?> in your order
                                </div>
                            </div>
                        </div>
                        <div class="po-card-body">
                            <div class="po-table-wrap">
                                <table class="po-table">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th class="text-center">Qty</th>
                                            <th class="text-right">Price</th>
                                            <th class="text-right">Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($items as $it): ?>
                                            <tr>
                                                <td><span class="product-name"><?= h($it['p']['name']) ?></span></td>
                                                <td class="text-center"><?= (int)$it['qty'] ?></td>
                                                <td class="text-right">₱<?= number_format($it['p']['price'], 2) ?></td>
                                                <td class="text-right">₱<?= number_format($it['subtotal'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" class="text-right">Grand Total</td>
                                            <td class="text-right">₱<?= number_format($total, 2) ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <div class="po-actions">
                                <button type="submit" class="po-btn po-btn-primary">
                                    <i class="bi bi-send"></i> Submit Purchase Order
                                </button>
                                <a href="/sweepxpress/cart.php" class="po-btn po-btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Back to Cart
                                </a>
                            </div>
                        </div>
                    </div>
                </form>

            <?php elseif ($order_submitted): ?>

                <!-- SUCCESS STATE -->
                <div class="po-card">
                    <div class="po-success">
                        <div class="po-success-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>
                        <h2 class="po-success-title">Order Submitted Successfully!</h2>
                        <div class="po-success-order">#<?= h($orderId) ?></div>
                        <p class="po-success-text">
                            Your Purchase Order has been received and stocks have been reserved.<br>
                            You will be notified once it is approved.
                        </p>
                        <div class="po-success-actions">
                            <!-- Use the latest path you prefer: Invoice.php (from your first UI snippet) -->
                            <a href="/sweepxpress/Invoice.php?order_id=<?= h($orderId) ?>" class="po-btn po-btn-success">
                                <i class="bi bi-file-earmark-text"></i> View / Print PO
                            </a>
                            <a href="/sweepxpress/customers/my_orders.php" class="po-btn po-btn-secondary">
                                <i class="bi bi-list-ul"></i> View All Orders
                            </a>
                            <a href="/sweepxpress/index.php" class="po-btn po-btn-secondary">
                                <i class="bi bi-house"></i> Back to Home
                            </a>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <!-- Safety fallback -->
                <div class="po-alert po-alert-warning">
                    <i class="bi bi-info-circle po-alert-icon"></i>
                    <div class="po-alert-content">
                        <span class="po-alert-title">
                            Your cart is empty. Please add items to create a Purchase Order.
                        </span>
                    </div>
                </div>
                <div style="text-align:center;margin-top:var(--space-6);">
                    <a href="/sweepxpress/cart.php" class="po-btn po-btn-primary">Go to Cart</a>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Skeleton reveal
    function revealContent() {
        document.body.classList.add('po-ready');
    }
    if (document.readyState === 'complete') {
        setTimeout(revealContent, 200);
    } else {
        window.addEventListener('load', function () {
            setTimeout(revealContent, 200);
        });
    }
    setTimeout(revealContent, 1200); // fallback

    // Form validation
    const form = document.getElementById('poForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                field.style.borderColor = '';
                field.style.boxShadow = '';
                if (!field.value.trim()) {
                    field.style.borderColor = 'var(--error-500)';
                    field.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.2)';
                    isValid = false;
                }
            });

            if (!isValid) {
                e.preventDefault();
                const firstError = form.querySelector('[style*="var(--error-500)"]');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
