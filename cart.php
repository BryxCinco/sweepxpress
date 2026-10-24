<?php
// cart.php
require_once __DIR__ . '/config.php';


// Helper function from checkout.php for consistency
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}


// HELPER FUNCTION: To format markdown **bold** to HTML <strong>bold</strong>
if (!function_exists('format_notification')) {
    function format_notification($text) {
        // Replaces **text** with <strong>text</strong>
        return preg_replace('/\*\*(.*?)\*\*\s?/s', '<strong>$1</strong> ', $text);
    }
}


$message = '';
$notifications = []; // Array to store stock limit notifications


// Update quantities (STOCK LIMITING LOGIC ADDED HERE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qty'])) {
    
    // 1. Fetch current stock and product name for all products being updated
    $pidsToUpdate = array_keys($_POST['qty']);
    
    // Safety check: only proceed if there are product IDs to check
    if (!empty($pidsToUpdate)) {
        $placeholders = implode(',', array_fill(0, count($pidsToUpdate), '?'));


        $stockData = [];
        // SQL query to calculate real stock: join products with inventory
        $sql = "SELECT p.id, p.name, IFNULL(SUM(i.quantity), 0) as real_stock 
                FROM products p 
                LEFT JOIN inventory i ON i.product_id = p.id 
                WHERE p.id IN ($placeholders) 
                GROUP BY p.id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($pidsToUpdate);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stockData[(int)$row['id']] = [
                'name' => $row['name'],
                'stock' => (int)$row['real_stock']
            ];
        }
    } else {
        $stockData = [];
    }
    
    // 2. Process updates and apply stock limit
    foreach ($_POST['qty'] as $pid => $q) {
        $pid = (int)$pid;
        // Ensure quantity is a non-negative integer
        $q = max(0, (int)$q);
        
        // Get stock and name for this product
        $productInfo = $stockData[$pid] ?? ['name' => 'Unknown Product', 'stock' => 0];
        $stock = $productInfo['stock'];
        $productName = $productInfo['name'];
        
        $requestedQuantity = $q; // Store the quantity the user requested


        // CRITICAL: Limit the requested quantity to the available stock
        $q = min($q, $stock); 
        
        // IMPROVED SERVER-SIDE NOTIFICATION TEXT
        if ($requestedQuantity > $stock && $stock > 0) {
            // Add a notification that the quantity was reduced
            $notifications[] = "The quantity you requested (**" . $requestedQuantity . " for " . h($productName) . "**) has been officially limited to the available stock: **" . $stock . "**.";
        } elseif ($requestedQuantity > 0 && $stock === 0) {
            // Add a notification if the item was requested but is now out of stock
            $notifications[] = "**" . h($productName) . "** is currently **Out of Stock** and has been removed from your cart.";
        }


        if ($q === 0) {
            unset($_SESSION['cart'][$pid]);
        } else {
            $_SESSION['cart'][$pid] = $q;
        }
    }
    
    // Store notifications in the session for display on the next page load
    if (!empty($notifications)) {
        $_SESSION['notifications'] = $notifications;
    }


    // Refresh the page to reflect the new cart state without resubmitting the form
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}


// Check for and display notifications from the previous request
if (isset($_SESSION['notifications'])) {
    // Add existing session notifications to the local array
    $notifications = array_merge($notifications, $_SESSION['notifications']);
    // Clear the session variable so the notification doesn't persist
    unset($_SESSION['notifications']); 
}


$cart = $_SESSION['cart'] ?? [];
$items = [];
$total = 0;
$itemCount = 0; // Tracks the number of unique products (for display: X items)
$totalQuantity = 0; // Tracks the total number of units


if ($cart) {
    // 1. Get product IDs from the cart session
    $productIds = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));


    // 2. Prepare and execute the statement (MODIFIED to include real_stock)
    $sql = "SELECT p.*, IFNULL(SUM(i.quantity), 0) as real_stock 
            FROM products p 
            LEFT JOIN inventory i ON i.product_id = p.id 
            WHERE p.id IN ($placeholders) 
            GROUP BY p.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($productIds);


    // 3. Process results
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$row['id'];
        $qty = (int)($cart[$pid] ?? 0);
        $stock = (int)$row['real_stock']; // Get the stock
        $productName = $row['name']; // Get product name for notification


        // SERVER-SIDE SANITIZATION: Check if the cart quantity exceeds stock. 
        if ($qty > $stock) {
             // LOGIC: Add a notification for quantity reduction during initial load/sanitization
            if ($stock > 0) {
                $notifications[] = "The quantity for **" . h($productName) . "** was updated to **" . $stock . "** because only that amount is currently in stock.";
            } else {
                $notifications[] = "**" . h($productName) . "** is currently **Out of Stock** and has been removed from your cart.";
            }


            $qty = $stock;
            $_SESSION['cart'][$pid] = $stock; // Update session immediately
        }


        if ($qty > 0) {
            $subtotal = $qty * (float)$row['price'];
            $row['stock'] = $stock; // Add stock to product array
            $items[] = ['p'=>$row, 'qty'=>$qty, 'subtotal'=>$subtotal];
            $total += $subtotal;
            $itemCount++; // Count unique products
            $totalQuantity += $qty; // Count total units
        } else {
            // Remove item if stock is zero and cart quantity was reset to 0
            unset($_SESSION['cart'][$pid]);
        }
    }
}



// Determine checkout path based on user role
$checkoutUrl = '/sweepxpress/checkout.php';
$checkoutText = 'Proceed to Checkout';


if (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'business') {
    $checkoutUrl = '/sweepxpress/business/purchaseorder.php';
    $checkoutText = 'Generate Purchase Order';
}


// INCLUDE HEADER HERE
require_once __DIR__ . '/includes/header.php';
?>


<style>
/* ALERT/NOTIFICATION STYLES */
.alert-container {
    position: sticky;
    top: 80px;
    z-index: 100;
    margin-bottom: 20px;
}


.alert {
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}


.alert-warning {
    background: #fef3c7; /* yellow-100 */
    color: #92400e; /* yellow-800 */
    border: 1px solid #fde68a; /* yellow-200 */
}


.alert-icon {
    flex-shrink: 0;
    font-size: 18px;
    line-height: 1;
}


.alert-text strong {
    /* Ensures the converted <strong> tag is properly bolded */
    font-weight: 700;
}


/* Enhanced animations for client-side alerts */
.client-temp-alert {
    animation: slideInDown 0.3s ease-out;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}


@keyframes slideInDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
/* ============================================================= */



/* ==================== COMPACT E-COMMERCE CART (EXISTING STYLES FOLLOW) ==================== */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');


:root {
    --primary: #3b82f6;
    --primary-hover: #2563eb;
    --dark: #18181b;
    --gray-900: #27272a;
    --gray-700: #3f3f46;
    --gray-500: #71717a;
    --gray-300: #d4d4d8;
    --gray-200: #e4e4e7;
    --gray-100: #f4f4f5;
    --white: #ffffff;
    --red: #ef4444;
    --shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}


* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}


body {
    background: var(--gray-100);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    -webkit-font-smoothing: antialiased;
    font-size: 14px;
}


.cart-wrapper {
    max-width: 1400px;          /* keep your preferred width */
    margin: 0 auto;
    padding: 16px 24px;
    min-height: calc(100vh - 80px); 
    /* 100vh minus approx header height so the footer starts below */
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
}


/* ========== HEADER ========== */
.cart-header {
    margin-bottom: 20px;
}


.cart-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 4px;
}


.cart-subtitle {
    font-size: 14px;
    color: var(--gray-500);
}


/* ========== LAYOUT ========== */
.cart-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 16px;
    align-items: flex-start;
    width: 100%;
    margin-top: 8px;
}


/* ========== ITEMS CARD ========== */
.items-card {
    background: var(--white);
    border-radius: 8px;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
}


.cart-item {
    display: grid;
    grid-template-columns: 80px 1fr;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--gray-100);
    align-items: flex-start;
}


.cart-item:last-child {
    border-bottom: none;
}


/* Image */
.item-image {
    width: 80px;
    height: 80px;
    border-radius: 6px;
    overflow: hidden;
    background: var(--gray-100);
    flex-shrink: 0;
    border: 1px solid var(--gray-200);
}


.item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}


/* Details */
.item-details {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}


.item-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}


.item-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--dark);
    line-height: 1.4;
    flex: 1;
}


.item-price {
    font-size: 15px;
    font-weight: 700;
    color: var(--primary);
    white-space: nowrap;
}


/* Controls Row */
.item-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}


.qty-group {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--gray-100);
    border: 1px solid var(--gray-200);
    border-radius: 6px;
    padding: 4px 6px;
    transition: all 0.2s ease;
}


/* Visual feedback when input exceeds stock */
.qty-group.over-limit {
    border-color: #ef4444;
    background: #fee2e2;
}


.qty-btn {
    width: 28px;
    height: 28px;
    border: none;
    background: var(--white);
    color: var(--gray-700);
    border-radius: 4px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}


.qty-btn:hover:not(:disabled) {
    background: var(--primary);
    color: white;
}


.qty-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}


.qty-input {
    /* Set a width based on character size (8ch = 8 zeros wide) */
    width: 8ch; 
    height: 28px;
    text-align: center;
    border: none;
    background: transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--dark);
    transition: all 0.2s ease;
}


/* Visual feedback for over-limit input */
.qty-input.over-limit {
    color: #dc2626;
    font-weight: 700;
}


/* FIX: Remove default spinners from type="number" inputs in all browsers */
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.qty-input[type=number] {
    -moz-appearance: textfield;
    appearance: textfield;
}


.qty-input:focus {
    outline: none;
}


.item-right {
    display: flex;
    align-items: center;
    gap: 12px;
}


.item-subtotal {
    font-size: 16px;
    font-weight: 700;
    color: var(--dark);
}


.item-remove {
    background: none;
    border: none;
    color: var(--gray-500);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.15s ease;
}


.item-remove:hover {
    background: var(--gray-100);
    color: var(--red);
}


/* ========== SUMMARY CARD ========== */
.summary-card {
    position: sticky;
    top: 80px;
    background: var(--white);
    border-radius: 8px;
    padding: 20px;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
}


.summary-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 16px;
}


.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--gray-100);
}


.summary-row:last-of-type {
    border-bottom: none;
}


.summary-label {
    font-size: 14px;
    color: var(--gray-500);
    font-weight: 500;
}


.summary-value {
    font-size: 14px;
    color: var(--dark);
    font-weight: 600;
}


.summary-total {
    background: var(--gray-50);
    margin: 16px -20px -20px;
    padding: 16px 20px;
    border-radius: 0 0 8px 8px;
}


.summary-total .summary-label {
    font-size: 14px;
    font-weight: 700;
    color: var(--dark);
}


.summary-total .summary-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--dark);
}


/* ========== BUTTONS ========== */
.summary-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 16px;
}


.btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 12px 20px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}


.btn-primary {
    background: var(--primary);
    color: white;
}


.btn-primary:hover {
    background: var(--primary-hover);
}


.btn-secondary {
    background: var(--white);
    color: var(--dark);
    border: 1px solid var(--gray-200);
}


.btn-secondary:hover {
    background: var(--gray-50);
}


/* ========== EMPTY STATE ========== */
.cart-empty {
    text-align: center;
    padding: 60px 20px;
    background: var(--white);
    border-radius: 8px;
    box-shadow: var(--shadow);
    border: 1px solid var(--gray-200);
}


.empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.3;
}


.empty-title {
    font-size: 18px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 8px;
}


.empty-text {
    font-size: 14px;
    color: var(--gray-500);
    margin-bottom: 20px;
}


/* ========== SKELETON LOADER ========== */
.skeleton {
    background: linear-gradient(90deg, #e4e4e7 25%, #d4d4d8 50%, #e4e4e7 75%);
    background-size: 200% 100%;
    animation: skeleton-shimmer 1.5s ease-in-out infinite;
    border-radius: 6px;
}


@keyframes skeleton-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}


.skeleton-item {
    display: grid;
    grid-template-columns: 80px 1fr;
    gap: 12px;
    padding: 16px;
    border-bottom: 1px solid var(--gray-100);
}


.skeleton-item:last-child {
    border-bottom: none;
}


.skeleton-image {
    width: 80px;
    height: 80px;
    border-radius: 6px;
}


.skeleton-content {
    display: flex;
    flex-direction: column;
    gap: 10px;
}


.skeleton-title {
    height: 20px;
    width: 70%;
    border-radius: 4px;
}


.skeleton-price {
    height: 18px;
    width: 80px;
    border-radius: 4px;
}


.skeleton-controls {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-top: 4px;
}


.skeleton-qty {
    height: 36px;
    width: 120px;
    border-radius: 6px;
}


.skeleton-subtotal {
    height: 20px;
    width: 80px;
    border-radius: 4px;
}


.skeleton-remove {
    height: 16px;
    width: 60px;
    border-radius: 4px;
}


.skeleton-summary-title {
    height: 22px;
    width: 140px;
    margin-bottom: 16px;
    border-radius: 4px;
}


.skeleton-summary-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
}


.skeleton-summary-label {
    height: 18px;
    width: 100px;
    border-radius: 4px;
}


.skeleton-summary-value {
    height: 18px;
    width: 70px;
    border-radius: 4px;
}


.skeleton-button {
    height: 44px;
    width: 100%;
    border-radius: 6px;
    margin-bottom: 10px;
}


/* Hide skeleton after load */
#cart-skeleton {
    display: grid !important;
}


#cart-real {
    display: none !important;
}


body.content-loaded #cart-skeleton {
    display: none !important;
}


body.content-loaded #cart-real {
    display: grid !important;
}


/* ========== UPDATING STATE ========== */
.updating {
    pointer-events: none;
    opacity: 0.5;
}


/* ========== RESPONSIVE ========== */
@media (max-width: 1024px) {
    .cart-grid {
        grid-template-columns: 1fr;
    }
    
    .summary-card {
        position: static;
    }


    .cart-wrapper {
        max-width: 100%;
        padding: 16px;
        min-height: calc(100vh - 72px);
    }
}


@media (max-width: 768px) {
    .cart-wrapper {
        padding: 12px;
    }
    
    .cart-header {
        margin-bottom: 16px;
    }
    
    .cart-title {
        font-size: 18px;
    }
    
    .skeleton-item {
        grid-template-columns: 70px 1fr;
    }
    
    .skeleton-image {
        width: 70px;
        height: 70px;
    }
}


@media (max-width: 640px) {
    .cart-item {
        grid-template-columns: 70px 1fr;
        gap: 12px;
        padding: 12px;
    }
    
    .item-image {
        width: 70px;
        height: 70px;
    }
    
    .item-name {
        font-size: 14px;
    }
    
    .item-price {
        font-size: 14px;
    }
    
    .item-subtotal {
        font-size: 15px;
    }
    
    .item-controls {
        flex-wrap: wrap;
    }
    
    .summary-card {
        padding: 16px;
    }
    
    .summary-total {
        margin: 16px -16px -16px;
        padding: 16px;
    }


    .cart-wrapper {
        padding: 12px 10px;
        min-height: calc(100vh - 64px);
    }
}


@media (max-width: 480px) {
    .cart-wrapper {
        padding: 8px;
    }
    
    .cart-item {
        grid-template-columns: 60px 1fr;
        gap: 10px;
        padding: 12px;
    }
    
    .item-image {
        width: 60px;
        height: 60px;
        border-radius: 4px;
    }
    
    .item-details {
        gap: 10px;
    }
    
    .item-name {
        font-size: 13px;
    }
    
    .item-price {
        font-size: 13px;
    }
    
    .item-controls {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .item-right {
        width: 100%;
        justify-content: space-between;
    }
    
    .qty-group {
        padding: 3px 5px;
        gap: 6px;
    }
    
    .qty-btn {
        width: 26px;
        height: 26px;
        font-size: 14px;
    }
    
    /* Removed fixed width here, relying on 8ch from main style */
    .qty-input {
        height: 26px;
        font-size: 13px;
    }
    
    .item-subtotal {
        font-size: 14px;
    }
    
    .item-remove {
        font-size: 12px;
        padding: 4px 6px;
    }
    
    .summary-card {
        padding: 14px;
    }
    
    .summary-title {
        font-size: 15px;
        margin-bottom: 12px;
    }
    
    .summary-row {
        padding: 8px 0;
    }
    
    .summary-label {
        font-size: 13px;
    }
    
    .summary-value {
        font-size: 13px;
    }
    
    .summary-total {
        margin: 12px -14px -14px;
        padding: 12px 14px;
    }
    
    .summary-total .summary-value {
        font-size: 18px;
    }
    
    .btn {
        padding: 11px 16px;
        font-size: 13px;
    }
    
    .skeleton-item {
        grid-template-columns: 60px 1fr;
        gap: 10px;
        padding: 12px;
    }
    
    .skeleton-image {
        width: 60px;
        height: 60px;
    }
}


@media (max-width: 375px) {
    body {
        font-size: 13px;
    }
    
    .cart-item {
        grid-template-columns: 56px 1fr;
        gap: 8px;
        padding: 10px;
    }
    
    .item-image {
        width: 56px;
        height: 56px;
    }
    
    .item-name {
        font-size: 12.5px;
    }
    
    .item-price,
    .item-subtotal {
        font-size: 13px;
    }
    
    .qty-btn {
        width: 24px;
        height: 24px;
        font-size: 13px;
    }
    
    /* Removed fixed width here, relying on 8ch from main style */
    .qty-input {
        height: 24px;
    }
    
    .skeleton-item {
        grid-template-columns: 56px 1fr;
        gap: 8px;
        padding: 10px;
    }
    
    .skeleton-image {
        width: 56px;
        height: 56px;
    }
    
    .cart-wrapper {
        padding: 10px 8px;
        min-height: calc(100vh - 56px);
    }
}
</style>


<div class="cart-wrapper">
    <header class="cart-header">
        <h1 class="cart-title">Shopping Cart</h1>
        <p class="cart-subtitle">Review and manage your items</p>
    </header>
    
    <?php if ($notifications): // Display server-side notifications if any ?>
        <div class="alert-container">
            <?php foreach ($notifications as $note): ?>
                <div class="alert alert-warning">
                    <div class="alert-icon">⚠️</div>
                    <div class="alert-text"><?php echo format_notification($note); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$items): // Only create alert container if notifications exist OR cart is not empty ?>
        <!-- Empty container for client-side notifications -->
    <?php else: ?>
        <div class="alert-container"></div>
    <?php endif; ?>
    
    <?php if (!$items): ?>
        <div class="cart-empty">
            <div class="empty-icon">🛒</div>
            <h2 class="empty-title">Your cart is empty</h2>
            <p class="empty-text">Add products to get started</p>
            <a href="/sweepxpress/index.php" class="btn btn-primary">
                Browse Products
            </a>
        </div>
    <?php else: ?>
        <div class="cart-grid" id="cart-skeleton">
        </div>


        <div class="cart-grid" id="cart-real">
            <div class="items-card">
                <form id="cartForm" method="post">
                    <?php foreach ($items as $it): ?>
                        <div class="cart-item" data-product-id="<?php echo (int)$it['p']['id']; ?>">
                            <div class="item-image">
                                <img src="<?php echo h($it['p']['image_path']); ?>" 
                                     alt="<?php echo h($it['p']['name']); ?>"
                                     onerror="this.src='https://via.placeholder.com/80x80?text=Product'">
                            </div>
                            
                            <div class="item-details">
                                <div class="item-top">
                                    <h3 class="item-name"><?php echo h($it['p']['name']); ?></h3>
                                    <div class="item-price">₱<?php echo number_format($it['p']['price'], 2); ?></div>
                                </div>
                                
                                <div class="item-controls">
                                    <div class="qty-group" data-product-id="<?php echo (int)$it['p']['id']; ?>">
                                        <button type="button" class="qty-btn qty-decrease" data-product-id="<?php echo (int)$it['p']['id']; ?>">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <input type="number" 
                                               class="qty-input" 
                                               name="qty[<?php echo (int)$it['p']['id']; ?>]" 
                                               value="<?php echo (int)$it['qty']; ?>"
                                               min="0"
                                               max="<?php echo (int)$it['p']['stock']; ?>"
                                               data-max-qty="<?php echo (int)$it['p']['stock']; ?>"
                                               data-product-name="<?php echo h($it['p']['name']); ?>"> 
                                        <button type="button" class="qty-btn qty-increase" data-product-id="<?php echo (int)$it['p']['id']; ?>">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                    
                                    <div class="item-right">
                                        <div class="item-subtotal">₱<?php echo number_format($it['subtotal'], 2); ?></div>
                                        <button type="button" class="item-remove" data-product-id="<?php echo (int)$it['p']['id']; ?>">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </form>
            </div>
            
            <aside class="summary-card">
                <h2 class="summary-title">Order Summary</h2>
                
                <div class="summary-row">
                    <span class="summary-label">Subtotal (<?php echo $totalQuantity; ?> units)</span>
                    <span class="summary-value">₱<?php echo number_format($total, 2); ?></span>
                </div>
                
                <div class="summary-row">
                    <span class="summary-label">Shipping</span>
                    <span class="summary-value">At checkout</span>
                </div>
                
                <div class="summary-total">
                    <div class="summary-row" style="padding: 0; border: none;">
                        <span class="summary-label">Total</span>
                        <span class="summary-value">₱<?php echo number_format($total, 2); ?></span>
                    </div>
                </div>
                
                <div class="summary-actions">
                    <a href="<?= h($checkoutUrl) ?>" class="btn btn-primary">
                        <?= h($checkoutText) ?>
                    </a>
                    <a href="/sweepxpress/index.php" class="btn btn-secondary">
                        Continue Shopping
                    </a>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>


<?php require_once __DIR__ . '/includes/footer.php'; ?>


<script>
// Show content after page load
document.addEventListener('DOMContentLoaded', function() {
    const loadStart = performance.now();
    
    function showContent() {
        const loadTime = performance.now() - loadStart;
        const minSkeletonTime = Math.min(1500, Math.max(300, loadTime * 0.5));
        
        setTimeout(function() {
            document.body.classList.add('content-loaded');
        }, minSkeletonTime);
    }
    
    if (document.readyState === 'complete') {
        showContent();
    } else {
        window.addEventListener('load', showContent);
        setTimeout(showContent, 2000);
    }


    // Cart functionality
    const cartForm = document.getElementById('cartForm');
    if (!cartForm) return;
    
    const increaseButtons = document.querySelectorAll('.qty-increase');
    const decreaseButtons = document.querySelectorAll('.qty-decrease');
    const removeButtons = document.querySelectorAll('.item-remove');
    const qtyInputs = document.querySelectorAll('.qty-input'); 
    
    /**
     * Helper function to display an immediate, in-page notification.
     * This message is temporary and is cleared by the next server-side refresh.
     */
    function displayClientNotification(message) {
        let alertContainer = document.querySelector('.alert-container');
        
        // Create alert container if it doesn't exist
        if (!alertContainer) {
            alertContainer = document.createElement('div');
            alertContainer.className = 'alert-container';
            const cartHeader = document.querySelector('.cart-header');
            if (cartHeader) {
                cartHeader.after(alertContainer);
            }
        }


        // Clear any previous temporary alerts to prevent clutter
        document.querySelectorAll('.client-temp-alert').forEach(el => el.remove());


        const alertDiv = document.createElement('div');
        alertDiv.className = 'alert alert-warning client-temp-alert';
        alertDiv.innerHTML = `
            <div class="alert-icon">⚠️</div>
            <div class="alert-text">${message}</div>
        `;
        // Prepend the new alert so it appears at the top of the container
        alertContainer.prepend(alertDiv);
    }
    
    /**
     * Updates the input value and submits the form after a delay.
     * @param {HTMLElement} input The quantity input element.
     * @param {number} newValue The new quantity value.
     * @param {number} delay The delay in milliseconds before submitting.
     */
    function updateQuantity(input, newValue, delay = 200) {
        const cartItem = input.closest('.cart-item');
        // Check if the quantity is actually changing before adding 'updating' class
        const currentValue = parseInt(input.value) || 0;
        if (newValue !== currentValue) {
            cartItem.classList.add('updating');
        }
        
        input.value = newValue;
        
        // Use the specified delay before submitting
        setTimeout(() => {
            cartForm.submit();
        }, delay);
    }
    
    /**
     * Adds visual feedback to the quantity group
     * @param {HTMLElement} input The quantity input element
     * @param {boolean} isOverLimit Whether the input exceeds the limit
     */
    function setVisualFeedback(input, isOverLimit) {
        const qtyGroup = input.closest('.qty-group');
        if (isOverLimit) {
            qtyGroup.classList.add('over-limit');
            input.classList.add('over-limit');
        } else {
            qtyGroup.classList.remove('over-limit');
            input.classList.remove('over-limit');
        }
    }
    
    // 1. Listen for clicks on the increase buttons (Uses data-max-qty for stock limit)
    increaseButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const productId = this.dataset.productId;
            const input = cartForm.querySelector(`input[name="qty[${productId}]"]`);
            
            const maxStock = parseInt(input.dataset.maxQty) || 999; 
            const productName = input.dataset.productName || 'This item';


            const currentValue = parseInt(input.value) || 0;
            const requestedValue = currentValue + 1;
            
            // Use Math.min with the maxStock for the limit
            const newValue = Math.min(requestedValue, maxStock);
            
            let submitDelay = 200; // Default delay
            
            // IMMEDIATE IN-PAGE FEEDBACK LOGIC
            if (requestedValue > maxStock) {
                const message = `<strong>${productName}</strong> only has <strong>${maxStock}</strong> in stock. Your quantity has been limited to this amount. The page will reload shortly for confirmation.`;
                displayClientNotification(message); // Show the in-page alert instantly
                setVisualFeedback(input, true);
                submitDelay = 1200; // 👈 INCREASED DELAY to make alert visible
            } else {
                setVisualFeedback(input, false);
            }
            
            updateQuantity(input, newValue, submitDelay); // Pass the delay
        });
    });
    
    // 2. Listen for clicks on the decrease buttons
    decreaseButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const productId = this.dataset.productId;
            const input = cartForm.querySelector(`input[name="qty[${productId}]"]`);
            const currentValue = parseInt(input.value) || 0;
            const newValue = Math.max(currentValue - 1, 0);
            
            setVisualFeedback(input, false); // Clear any visual feedback
            
            if (newValue === 0) {
                if (confirm('Remove this item?')) {
                    updateQuantity(input, 0);
                }
            } else {
                updateQuantity(input, newValue);
            }
        });
    });


    // 3. ENHANCED: Listen for manual changes in the input field with real-time validation
    qtyInputs.forEach(input => {
        let typingTimer;
        const doneTypingInterval = 800; // Wait 800ms after user stops typing
        
        // Real-time feedback as user types (debounced)
        input.addEventListener('input', function() {
            clearTimeout(typingTimer);
            const maxStock = parseInt(this.dataset.maxQty) || 999;
            const productName = this.dataset.productName || 'This item';
            const requestedValue = parseInt(this.value) || 0;
            
            // Show visual indicator immediately if over limit
            if (requestedValue > maxStock && requestedValue > 0) {
                setVisualFeedback(this, true);
                
                // Don't submit yet, just show visual feedback
                const message = `⚠️ <strong>${productName}</strong> only has <strong>${maxStock}</strong> in stock. Your input will be adjusted when you finish typing.`;
                displayClientNotification(message);
            } else {
                setVisualFeedback(this, false);
                // Clear temporary alerts when input is valid
                document.querySelectorAll('.client-temp-alert').forEach(el => el.remove());
            }
            
            // Set timer to validate and submit after user stops typing
            typingTimer = setTimeout(() => {
                validateAndSubmit(this);
            }, doneTypingInterval);
        });
        
        // Also validate when user leaves the field
        input.addEventListener('blur', function() {
            clearTimeout(typingTimer);
            validateAndSubmit(this);
        });
        
        // Original change event as fallback
        input.addEventListener('change', function() {
            clearTimeout(typingTimer);
            validateAndSubmit(this);
        });
    });
    
    /**
     * Extract validation logic into a reusable function
     * @param {HTMLElement} input The quantity input element
     */
    function validateAndSubmit(input) {
        const maxStock = parseInt(input.dataset.maxQty) || 999;
        const productName = input.dataset.productName || 'This item';
        const requestedValue = parseInt(input.value) || 0;
        let newValue = Math.min(Math.max(requestedValue, 0), maxStock);
        
        let submitDelay = 200;
        
        // IMMEDIATE IN-PAGE FEEDBACK LOGIC
        if (requestedValue > maxStock && requestedValue > 0) {
            const message = `<strong>${productName}</strong> only has <strong>${maxStock}</strong> in stock. Your quantity has been limited to this amount. The page will reload shortly for confirmation.`;
            displayClientNotification(message);
            setVisualFeedback(input, true);
            submitDelay = 1200; // 👈 INCREASED DELAY to make alert visible
        } else {
            setVisualFeedback(input, false);
        }
        
        updateQuantity(input, newValue, submitDelay);
    }
    
    // 4. Listen for clicks on the remove buttons
    removeButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            if (confirm('Remove this item from cart?')) {
                const productId = this.dataset.productId;
                const input = cartForm.querySelector(`input[name="qty[${productId}]"]`);
                setVisualFeedback(input, false); // Clear any visual feedback
                updateQuantity(input, 0);
            }
        });
    });
});
</script>
