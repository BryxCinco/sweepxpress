<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';

// Helper function for basic HTML escaping
if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// --- UPDATED HELPER FUNCTION ---
function get_status_badge_class($status) {
    $status = strtolower($status);
    
    return match($status) {
        'delivered', 'completed' => 'bg-success text-white',
        'preparing', 'shipped' => 'bg-info text-white',
        'pending' => 'bg-warning text-dark',
        'cancelled' => 'bg-danger text-white',
        default => 'bg-secondary text-white'
    };
}
// --- END HELPER FUNCTION ---

$orderId = intval($_GET['id'] ?? 0);
if (!$orderId) {
    echo "<div class='container p-5'><div class='alert alert-danger'>❌ Order not found or ID is missing.</div></div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$message = '';

// =======================================================================
// 1. HANDLE DELIVERY UPDATE (PHP Logic)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    $status = $_POST['status'] ?? null;
    $date = $_POST['delivery_date'] ?? null;

    if ($status) {
        $finalStatus = ($status === 'shipped') ? 'preparing' : $status;

        $stmt = $pdo->prepare("SELECT id FROM deliveries WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $deliveryExists = $stmt->fetch();

        try {
            if ($deliveryExists) {
                $updateStmt = $pdo->prepare("UPDATE deliveries SET status=?, delivery_date=? WHERE order_id=?");
                $updateStmt->execute([$finalStatus, $date, $orderId]);
            } else {
                $infoStmt = $pdo->prepare("SELECT o.address, o.customer_name FROM orders o WHERE o.id = ?");
                $infoStmt->execute([$orderId]);
                $customer_info = $infoStmt->fetch();

                $insertStmt = $pdo->prepare("INSERT INTO deliveries 
                    (order_id, customer_name, address, status, delivery_date) 
                    VALUES (?, ?, ?, ?, ?)");
                $insertStmt->execute([
                    $orderId,
                    $customer_info['customer_name'] ?? 'Unknown',
                    $customer_info['address'] ?? 'Unknown',
                    $finalStatus,
                    $date
                ]);
            }

            $updateOrderStmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $updateOrderStmt->execute([$finalStatus, $orderId]);
            
            $message = 'success';
        } catch (PDOException $e) {
            error_log("Delivery update failed: " . $e->getMessage());
            $message = 'error';
        }
    }
}

// =======================================================================
// 2. FETCH ALL ORDER DATA (PHP Logic)
// =======================================================================
try {
    $stmtOrder = $pdo->prepare("
        SELECT o.*, u.name AS user_name, u.email AS user_email 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmtOrder->execute([$orderId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo "<div class='container p-5'><div class='alert alert-danger'>Order not found.</div></div>";
        require_once __DIR__ . '/../includes/footer.php';
        exit;
    }

    $order['user_name'] = $order['user_name'] ?? h($order['customer_name']) . ' (Guest)';
    $order['user_email'] = $order['user_email'] ?? 'N/A';
    
    $stmtItems = $pdo->prepare("
        SELECT oi.*, p.name 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ?
    ");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $stmtDelivery = $pdo->prepare("SELECT * FROM deliveries WHERE order_id = ?");
    $stmtDelivery->execute([$orderId]);
    $delivery = $stmtDelivery->fetch(PDO::FETCH_ASSOC);

    $isCompleted = (strtolower($order['status']) === 'completed');

    // --- NEW LOGIC FOR PO DATE CONSTRAINTS ---
    $isPurchaseOrder = preg_match('/^Net (30|45|60)$/i', $order['payment_terms'] ?? '');
    $maxDeliveryDate = null;
    $poDueDateDisplay = 'N/A';

    if ($isPurchaseOrder) {
        $poTerms = (int)filter_var($order['payment_terms'], FILTER_SANITIZE_NUMBER_INT);
        $orderDate = new DateTime($order['created_at']);
        
        $poDueDate = clone $orderDate;
        $poDueDate->modify("+$poTerms days");
        $poDueDateDisplay = $poDueDate->format('Y-m-d');
        
        $maxDeliveryDate = $poDueDateDisplay; 
    }
    // --- END NEW LOGIC ---

} catch (PDOException $e) {
    die("<div class='container p-5'><div class='alert alert-danger'>Database error: " . h($e->getMessage()) . "</div></div>");
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if ($message === 'success'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: 'success',
        title: 'Updated Successfully!',
        text: 'Delivery status has been updated',
        confirmButtonColor: '#8b5cf6',
        timer: 2000,
        showConfirmButton: false
    });
});
</script>
<?php elseif ($message === 'error'): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: 'error',
        title: 'Update Failed',
        text: 'Could not update delivery status',
        confirmButtonColor: '#ef4444'
    });
});
</script>
<?php endif; ?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap');

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Plus Jakarta Sans', sans-serif;
}

body {
    background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
    min-height: 10vh;
    padding: 0;
}

.order-page {
    max-width: 1400px;
    margin: 0 auto;
    padding: 140px 24px 80px;
}

/* TOP NAV */
.top-nav {
    display: flex;
    gap: 16px;
    margin-bottom: 32px;
}

.nav-link {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 28px;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    color: #0f172a;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.nav-link:hover {
    background: #f8fafc;
    border-color: #8b5cf6;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.15);
    color: #0f172a;
}

.nav-link.pdf {
    background: linear-gradient(135deg, #ec4899 0%, #f43f5e 100%);
    border-color: transparent;
    color: #ffffff;
}

.nav-link.pdf:hover {
    background: linear-gradient(135deg, #db2777 0%, #e11d48 100%);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(236, 72, 153, 0.3);
    color: #ffffff;
}

/* HERO CARD (Purple for Archive) */
.hero-card {
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    border-radius: 24px;
    padding: 48px;
    margin-bottom: 32px;
    box-shadow: 0 20px 50px rgba(139, 92, 246, 0.25);
    position: relative;
    overflow: hidden;
}

.hero-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 500px;
    height: 500px;
    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
    pointer-events: none;
}

.hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 24px;
}

.hero-left {
    flex: 1;
}

.order-label {
    font-size: 0.85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 12px;
}

.order-id-big {
    font-size: 3.5rem;
    font-weight: 900;
    color: #ffffff;
    line-height: 1;
    margin-bottom: 16px;
    text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.order-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    color: rgba(255, 255, 255, 0.95);
    font-size: 0.95rem;
    font-weight: 500;
}

.hero-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 16px;
}

.status-display {
    padding: 16px 32px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 100px;
    color: #0f172a;
    font-weight: 700;
    font-size: 1.1rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
}

.total-display-hero {
    text-align: right;
}

.total-label-hero {
    font-size: 0.85rem;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 8px;
    font-weight: 600;
}

.total-amount-hero {
    font-size: 2.5rem;
    font-weight: 900;
    color: #ffffff;
    text-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

/* GRID */
.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 24px;
}

/* WHITE CARD */
.white-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 24px;
    padding: 32px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
    transition: all 0.3s;
}

.white-card:hover {
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.card-title {
    font-size: 1.4rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.card-title i {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    border-radius: 12px;
    font-size: 1.1rem;
    color: #ffffff;
}

/* DATA ROW */
.data-row {
    margin-bottom: 24px;
}

.data-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #64748b;
    margin-bottom: 8px;
}

.data-value {
    font-size: 1rem;
    font-weight: 500;
    color: #0f172a;
    line-height: 1.6;
}

.data-value a {
    color: #8b5cf6;
    text-decoration: none;
}

.data-value a:hover {
    text-decoration: underline;
}

/* LOCK INFO */
.lock-info {
    background: #fef3c7;
    border: 2px solid #fbbf24;
    border-radius: 16px;
    padding: 16px 20px;
    color: #92400e;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
}

/* PO DUE BOX */
.po-due-box {
    background: #dbeafe;
    border: 2px solid #93c5fd;
    border-radius: 16px;
    padding: 16px 20px;
    color: #1e40af;
    font-weight: 600;
    margin-bottom: 24px;
}

/* FORM CARD */
.form-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 28px;
    margin-top: 24px;
}

.input-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}

.input-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 10px;
}

.input-group select,
.input-group input {
    width: 100%;
    padding: 14px 18px;
    background: #ffffff;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    color: #0f172a;
    font-size: 0.95rem;
    font-weight: 500;
    transition: all 0.3s;
}

.input-group select:focus,
.input-group input:focus {
    outline: none;
    border-color: #8b5cf6;
    box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1);
}

.input-group select:disabled,
.input-group input:disabled {
    background: #f1f5f9;
    opacity: 0.6;
    cursor: not-allowed;
}

.input-group-custom {
    display: flex;
    gap: 0;
}

.input-group-custom input {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}

.input-group-text-custom {
    padding: 14px 18px;
    background: #f1f5f9;
    border: 2px solid #e2e8f0;
    border-left: none;
    border-top-right-radius: 12px;
    border-bottom-right-radius: 12px;
    color: #64748b;
}

.form-hint {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 6px;
}

.btn-update {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    border: none;
    border-radius: 12px;
    color: #ffffff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
}

.btn-update:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(139, 92, 246, 0.4);
}

.btn-update:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* TABLE CARD */
.table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
}

.table-header {
    padding: 28px 32px;
    border-bottom: 1px solid #e2e8f0;
}

.modern-table {
    width: 100%;
    border-collapse: collapse;
}

.modern-table thead th {
    padding: 20px 32px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #64748b;
    background: #f8fafc;
}

.modern-table tbody td {
    padding: 24px 32px;
    color: #0f172a;
    border-top: 1px solid #f1f5f9;
    font-size: 0.95rem;
}

.modern-table tbody tr {
    transition: background 0.2s;
}

.modern-table tbody tr:hover {
    background: #f8fafc;
}

.item-name {
    font-weight: 600;
    color: #0f172a;
}

.item-price {
    color: #64748b;
}

.item-qty {
    font-weight: 700;
    text-align: center;
    color: #0f172a;
}

.item-total {
    font-weight: 700;
    color: #8b5cf6;
    text-align: right;
}

.table-summary {
    padding: 24px 32px;
    background: #f8fafc;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 2px solid #e2e8f0;
}

.summary-label {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
}

.summary-amount {
    font-size: 2rem;
    font-weight: 900;
    background: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* EMPTY */
.empty-state {
    text-align: center;
    padding: 80px;
    color: #94a3b8;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
}

/* RESPONSIVE */
@media (max-width: 992px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .order-page {
        padding: 100px 16px 60px;
    }
    
    .hero-card {
        padding: 32px 24px;
    }
    
    .order-id-big {
        font-size: 2.5rem;
    }
    
    .hero-content {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .hero-right {
        align-items: flex-start;
        width: 100%;
    }
    
    .total-display-hero {
        text-align: left;
    }
    
    .input-row {
        grid-template-columns: 1fr;
    }
    
    .white-card {
        padding: 24px;
    }
    
    .top-nav {
        flex-direction: column;
    }
}
</style>

<div class="order-page">
    <div class="top-nav">
        <a href="deliveries.php" class="nav-link">
            <i class="fas fa-arrow-left"></i>
            Back to deliveries
        </a>
        <a href="generate_order_pdf.php?id=<?= $orderId ?>" target="_blank" class="nav-link pdf">
            <i class="fas fa-file-pdf"></i>
            Generate PDF
        </a>
      
        <a href="adminreceipt.php?order_id=<?= $orderId ?>" class="nav-link receipt-link"> 
            <i class="fas fa-receipt"></i> View Receipt
        </a>
    </div>

    <div class="hero-card">
        <div class="hero-content">
            <div class="hero-left">
                <div class="order-label">Order Details</div>
                <div class="order-id-big">#<?= $orderId ?></div>
                <div class="order-meta">
                    <i class="fas fa-calendar-alt"></i>
                    <?= date("F j, Y • h:i A", strtotime($order['created_at'])) ?>
                </div>
            </div>
            <div class="hero-right">
                <div class="status-display">
                    <?= ucfirst(h($order['status'])) ?>
                </div>
                <div class="total-display-hero">
                    <div class="total-label-hero">Order Total</div>
                    <div class="total-amount-hero">₱<?= number_format($order['total'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="content-grid">
        <div class="white-card">
            <div class="card-title">
                <i class="fas fa-user"></i>
                Customer Information
            </div>

            <div class="data-row">
                <div class="data-label">Customer Name</div>
                <div class="data-value">
                    <?php if ($order['user_id']): ?>
                        <a href="user_actions.php?id=<?= $order['user_id'] ?>"><?= $order['user_name'] ?></a>
                    <?php else: ?>
                        <?= $order['user_name'] ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="data-row">
                <div class="data-label">Email Address</div>
                <div class="data-value"><?= $order['user_email'] ?></div>
            </div>

            <div class="data-row">
                <div class="data-label">Name on Order</div>
                <div class="data-value"><?= h($order['customer_name']) ?></div>
            </div>

            <div class="data-row">
                <div class="data-label">Order Notes</div>
                <div class="data-value"><?= nl2br(h($order['notes'] ?? 'No notes')) ?></div>
            </div>
        </div>

        <div class="white-card">
            <div class="card-title">
                <i class="fas fa-truck-fast"></i>
                Delivery Management
            </div>

            <?php if ($isCompleted): ?>
                <div class="lock-info">
                    <i class="fas fa-lock"></i>
                    Order completed and locked
                </div>
            <?php endif; ?>

            <?php if ($isPurchaseOrder): ?>
                <div class="po-due-box">
                    <strong>PO Due Date (<?= h($order['payment_terms']) ?>):</strong> <?= $poDueDateDisplay ?>
                </div>
            <?php endif; ?>

            <div class="data-row">
                <div class="data-label">Shipping Address</div>
                <div class="data-value"><?= nl2br(h($order['address'])) ?></div>
            </div>

            <div class="data-row">
                <div class="data-label">Payment Method</div>
                <div class="data-value"><?= h($order['payment_method'] ?? 'N/A') ?></div>
            </div>

            <div class="data-row">
                <div class="data-label">Payment Status</div>
                <div class="data-value"><?= h($order['payment_status'] ?? 'N/A') ?></div>
            </div>

            <div class="data-row">
                <div class="data-label">Payment Terms</div>
                <div class="data-value"><?= h($order['payment_terms'] ?? 'N/A') ?></div>
            </div>

            <form method="post" class="form-card">
                <div class="input-row">
                    <div class="input-group">
                        <label>Delivery Status</label>
                        <select name="status" required <?= $isCompleted ? 'disabled' : '' ?>>
                            <?php
                            $statuses = ['pending','preparing','delivered','cancelled','completed'];
                            $currentStatus = $delivery['status'] ?? 'pending';
                            $displayStatus = (strtolower($currentStatus) === 'shipped') ? 'preparing' : $currentStatus;

                            foreach ($statuses as $s) {
                                if ($isCompleted && $s !== 'completed') continue;
                                $sel = (strtolower($displayStatus) === $s) ? 'selected' : '';
                                echo "<option value='$s' $sel>".ucfirst(h($s))."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Delivery Date</label>
                        <div class="input-group-custom">
                            <input type="date" name="delivery_date" 
                                value="<?= $delivery['delivery_date'] ?? '' ?>" 
                                min="<?= date('Y-m-d') ?>"
                                <?= $maxDeliveryDate ? 'max="' . $maxDeliveryDate . '"' : '' ?>
                                <?= $isCompleted ? 'disabled' : '' ?>>
                            <?php if ($isPurchaseOrder): ?>
                            <span class="input-group-text-custom"><i class="fas fa-lock"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isPurchaseOrder): ?>
                        <div class="form-hint">Max date: <?= $maxDeliveryDate ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn-update" <?= $isCompleted ? 'disabled' : '' ?>>
                    <i class="fas fa-truck-moving"></i>
                    Update Delivery
                </button>
            </form>
        </div>
    </div>

    <div class="table-card">
        <div class="table-header">
            <div class="card-title" style="margin: 0;">
                <i class="fas fa-box"></i>
                Order Items
            </div>
        </div>
        <?php if (count($items) > 0): ?>
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Product Name</th>
                        <th style="text-align: right;">Unit Price</th>
                        <th style="text-align: center;">Qty</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $itemTotal = 0;
                    foreach ($items as $item): 
                        $subtotal = $item['price'] * $item['quantity'];
                        $itemTotal += $subtotal;
                    ?>
                        <tr>
                            <td class="item-name"><?= h($item['name']) ?></td>
                            <td class="item-price" style="text-align: right;">₱<?= number_format($item['price'], 2) ?></td>
                            <td class="item-qty"><?= $item['quantity'] ?></td>
                            <td class="item-total">₱<?= number_format($subtotal, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="table-summary">
                <div class="summary-label">Grand Total</div>
                <div class="summary-amount">₱<?= number_format($itemTotal, 2) ?></div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <div>No items found</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>