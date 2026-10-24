<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

require_once __DIR__ . '/../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php

// ====================================================================
// 1. Handle delivery status and date update (UNCHANGED LOGIC)
// NOTE: The status update logic remains here to allow the delivery date 
// to be updated, which requires submitting a status value. The status 
// field is removed from the UI, but the logic is needed for the date update.
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $orderId = $_POST['order_id'];
    // $status is retained from the hidden input, though no longer directly changed by user in UI
    $status = $_POST['status']; 
    $deliveryDate = $_POST['delivery_date'] ?? null;

    $currentOrderStmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $currentOrderStmt->execute([$orderId]);
    $currentStatus = $currentOrderStmt->fetchColumn();

    if ($currentStatus === 'cancelled' || $currentStatus === 'cancellation_requested') {
        echo "<script>
            Swal.fire({
                icon: 'warning',
                title: 'Cannot Update',
                text: 'Order is currently " . h(ucfirst(str_replace('_', ' ', $currentStatus))) . "',
                confirmButtonColor: '#3b82f6'
            });
        </script>";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM deliveries WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $deliveryExists = $stmt->fetch();

            if ($deliveryExists) {
                // Since status is non-editable, we mainly focus on delivery date update
                if (isset($_POST['status']) && $_POST['status'] !== 'skip_update') {
                    // Update delivery record, using existing order status if not explicitly skipped
                    $updateStmt = $pdo->prepare("UPDATE deliveries SET status = ?, delivery_date = ? WHERE order_id = ?");
                    $updateStmt->execute([$status, $deliveryDate, $orderId]);
                } else if ($deliveryDate !== null) {
                    $updateStmt = $pdo->prepare("UPDATE deliveries SET delivery_date = ? WHERE order_id = ?");
                    $updateStmt->execute([$deliveryDate, $orderId]);
                }
            } else {
                // If no delivery exists, we must insert both
                $infoStmt = $pdo->prepare("SELECT o.address, u.name AS customer_name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
                $infoStmt->execute([$orderId]);
                $customer_info = $infoStmt->fetch();

                $insertStmt = $pdo->prepare("INSERT INTO deliveries (order_id, customer_name, address, status, delivery_date) VALUES (?, ?, ?, ?, ?)");
                $insertStmt->execute([
                    $orderId,
                    $customer_info['customer_name'] ?? 'Unknown',
                    $customer_info['address'] ?? 'Unknown',
                    $status,
                    $deliveryDate
                ]);
            }

            // Update order status only if a real status change was attempted (not just date)
            if (isset($_POST['status']) && $_POST['status'] !== 'skip_update') {
                $updateOrderStmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
                $updateOrderStmt->execute([$status, $orderId]);
            }

            echo "<script>
                Swal.fire({
                    icon: 'success',
                    title: 'Updated!',
                    text: 'Delivery information updated successfully',
                    showConfirmButton: false,
                    timer: 1500
                });
            </script>";

        } catch (PDOException $e) {
            error_log("Delivery update failed: " . $e->getMessage());
            echo "<script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to update delivery information',
                    confirmButtonColor: '#ef4444'
                });
            </script>";
        }
    }
}


// ====================================================================
// 2. FETCH DATA
// ====================================================================
$sql = "
    SELECT 
        o.id AS order_id,
        u.name AS customer_name,
        o.total,
        o.created_at,
        o.status AS order_status, 
        d.id AS delivery_id,
        d.status AS delivery_status,
        d.delivery_date
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN deliveries d ON o.id = d.order_id
    WHERE u.role = 'customer'
    ORDER BY o.created_at DESC
";

$orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Helper function to get status details (Added for consistency, adapted from B2B)
function get_status_details_b2c($currentStatus) {
    // Delivery date is disabled for editing if it's already cancelled or completed
    $disableStatus = in_array($currentStatus, ['cancellation_requested', 'cancelled', 'completed']);
    
    $statusBadgeClass = match($currentStatus) {
        'pending'   => 'pending',
        'shipped'   => 'preparing', // Uses 'preparing' class for 'shipped' color
        'delivered' => 'delivered',
        'completed' => 'completed',
        'cancellation_requested', 'cancelled' => 'cancelled',
        default     => 'pending'
    };
    
    $displayStatusText = match($currentStatus) {
        'pending'   => 'Pending',
        'shipped'   => 'Preparing/Processing',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancellation_requested', 'cancelled' => 'Cancelled',
        default     => 'Pending'
    };

    return [$disableStatus, $statusBadgeClass, $displayStatusText];
}
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

* {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
}

body {
    background: #f8f9fb;
}

.orders-b2c-container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.orders-b2c-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-orders-b2c {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-orders-b2c i {
    color: #3b82f6;
}

.btn-back-orders-b2c {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    text-decoration: none;
    font-size: 0.95rem;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.2s;
}

.btn-back-orders-b2c:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* STATS CARD */
.stats-card-orders-b2c {
    background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
    border-radius: 16px;
    padding: 20px 28px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 10px 24px rgba(59, 130, 246, 0.3);
    margin-bottom: 24px;
}

.stats-icon-orders-b2c {
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.25);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 1.5rem;
}

.stats-content-orders-b2c h2 {
    font-size: 0.85rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px 0;
}

.stats-content-orders-b2c .stats-value-orders-b2c {
    font-size: 2rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1;
}

/* TABLE CARD (Desktop) */
.table-card-orders-b2c {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow-x: auto;
}

.table-orders-b2c {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-orders-b2c thead {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
}

.table-orders-b2c thead th {
    padding: 18px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #1e40af;
    border-bottom: 2px solid #3b82f6;
    white-space: nowrap;
}

.table-orders-b2c thead th.text-center {
    text-align: center;
}

.table-orders-b2c tbody td {
    padding: 18px 20px;
    font-size: 0.95rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
    white-space: nowrap;
}

.table-orders-b2c tbody tr:hover {
    background: #eff6ff;
}

.table-orders-b2c tbody tr:last-child td {
    border-bottom: none;
}

/* ORDER ID BADGE */
.order-id-badge-b2c {
    display: inline-flex;
    padding: 6px 12px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-weight: 700;
    color: #0f172a;
}

/* CUSTOMER NAME */
.customer-name-b2c {
    font-weight: 600;
    color: #0f172a;
}

/* PRICE */
.price-display-b2c {
    font-weight: 700;
    color: #10b981;
}

/* STATUS BADGES */
.status-badge-b2c {
    display: inline-flex;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-badge-b2c.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge-b2c.preparing {
    background: #dbeafe;
    color: #1e40af;
}

.status-badge-b2c.delivered {
    background: #d1fae5;
    color: #065f46;
}

.status-badge-b2c.completed {
    background: #e0e7ff;
    color: #3730a3;
}

.status-badge-b2c.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

/* FORM CONTROLS */
.date-input-b2c {
    padding: 8px 12px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #ffffff;
    transition: all 0.2s;
    min-width: 150px;
}

.date-input-b2c:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.date-input-b2c:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background: #f3f4f6;
}

/* VIEW DETAILS BUTTON */
.btn-view-details-b2c {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #ffffff;
    color: #3b82f6;
    border: 1.5px solid #3b82f6;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
    white-space: nowrap;
}

.btn-view-details-b2c:hover {
    background: #3b82f6;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

/* EMPTY STATE */
.empty-state-orders-b2c {
    text-align: center;
    padding: 80px 32px;
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
}

.empty-state-orders-b2c i {
    font-size: 4rem;
    color: #94a3b8;
    margin-bottom: 20px;
}

.empty-state-orders-b2c h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
}

.empty-state-orders-b2c p {
    font-size: 0.95rem;
    color: #94a3b8;
}


/* --- MOBILE CARD STYLES (CLEAN LAYOUT - COPIED FROM B2B STRUCTURE) --- */

.orders-b2c-mobile {
    display: none; /* Hidden by default, shown on mobile */
}

.order-cards-b2c {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-card-b2c { /* Renamed from .order-card to .order-card-b2c */
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.order-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 1px solid #f3f4f6;
    padding-bottom: 12px;
    margin-bottom: 12px;
}

.order-card-id {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
}

.order-card-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 16px;
}

.detail-item {
    font-size: 0.9rem;
    color: #4b5563;
}

.detail-label {
    font-weight: 600;
    color: #94a3b8;
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.order-card-actions {
    padding-top: 12px;
    border-top: 1px solid #f3f4f6;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.order-card-action-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.order-card-action-item label {
    font-weight: 600;
    color: #0f172a;
    font-size: 0.9rem;
    white-space: nowrap;
}

/* Mobile form control sizing adjustment (using b2c class) */
.order-cards-b2c .date-input-b2c {
    flex-grow: 1;
    min-width: 150px; 
    width: auto;
    font-size: 0.85rem; 
    padding: 6px 10px;
}

.order-cards-b2c .btn-view-details-b2c {
    width: 100%;
    justify-content: center;
    margin-top: 10px;
}

/* Media Query to switch views (Hiding the table and showing the clean cards) */
@media (max-width: 1024px) {
    .table-card-orders-b2c { 
        display: none; /* Hide Table */
    } 
    .orders-b2c-mobile { 
        display: block; /* Show Cards */
    }
}


@media (max-width: 768px) {
    .orders-b2c-container {
        padding: 24px 16px 40px;
    }
    
    .orders-b2c-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .btn-back-orders-b2c {
        width: 100%;
        justify-content: center;
    }
    
    .stats-card-orders-b2c {
        width: 100%;
    }
}

/* REMOVED: Old mobile specific styles that clashed with the new layout */
</style>

<div class="orders-b2c-container">
    <div class="orders-b2c-header">
        <h1 class="page-title-orders-b2c">
            <i class="fas fa-shopping-cart"></i>
            Customer Orders Management
        </h1>
        <a href="allorders.php" class="btn-back-orders-b2c">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

    <div class="stats-card-orders-b2c">
        <div class="stats-icon-orders-b2c">
            <i class="fas fa-users"></i>
        </div>
        <div class="stats-content-orders-b2c">
            <h2>All Customer Orders</h2>
            <span class="stats-value-orders-b2c"><?= number_format(count($orders)) ?></span>
        </div>
    </div>

    <?php if ($orders): ?>
        
        <div class="table-card-orders-b2c">
            <div class="table-responsive">
                <table class="table-orders-b2c">
                    <thead>
                        <tr>
                            <th class="text-center">Order ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Order Date</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Delivery Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): 
                            [$disableStatus, $statusBadgeClass, $displayStatusText] = get_status_details_b2c($o['order_status']);
                        ?>
                            <tr>
                                <td class="text-center">
                                    <span class="order-id-badge-b2c">#<?= (int)$o['order_id'] ?></span>
                                </td>
                                <td class="customer-name-b2c"><?= h($o['customer_name'] ?? 'Guest') ?></td>
                                <td>
                                    <span class="price-display-b2c">₱<?= number_format($o['total'], 2) ?></span>
                                </td>
                                <td style="color: #64748b;">
                                    <?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge-b2c <?= $statusBadgeClass ?>">
                                        <?= h($displayStatusText) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">
                                        <input type="hidden" name="status" value="<?= h($o['order_status'] ?? 'pending') ?>">
                                        <input type="date" name="delivery_date" class="date-input-b2c" 
                                               value="<?= h($o['delivery_date'] ?? '') ?>" 
                                               min="<?= date('Y-m-d') ?>" 
                                               onchange="this.form.submit()"
                                               <?= $disableStatus ? 'disabled' : '' ?>>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <a class="btn-view-details-b2c" href="order_details_b2c.php?id=<?= (int)$o['order_id'] ?>">
                                        <i class="fas fa-eye"></i>
                                        View Details
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="orders-b2c-mobile">
            <div class="order-cards-b2c">
                <?php foreach ($orders as $o): 
                    [$disableStatus, $statusBadgeClass, $displayStatusText] = get_status_details_b2c($o['order_status']);
                ?>
                    <div class="order-card-b2c">
                        <div class="order-card-header">
                            <span class="order-card-id">Order #<?= (int)$o['order_id'] ?></span>
                            <span class="status-badge-b2c <?= $statusBadgeClass ?>">
                                <?= h($displayStatusText) ?>
                            </span>
                        </div>

                        <div class="order-card-details">
                            <div class="detail-item">
                                <span class="detail-label">Customer Name</span>
                                <span><?= h($o['customer_name'] ?? 'Guest') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Total</span>
                                <span class="price-display-b2c">₱<?= number_format($o['total'], 2) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Order Date</span>
                                <span><?= date('M d, Y', strtotime($o['created_at'])) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Time</span>
                                <span><?= date('h:i A', strtotime($o['created_at'])) ?></span>
                            </div>
                        </div>

                        <div class="order-card-actions">
                            
                            <div class="order-card-action-item">
                                <label for="delivery-date-<?= $o['order_id'] ?>">Delivery Date</label>
                                <form method="post" class="d-inline" style="flex-grow: 1;">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">
                                    <input type="hidden" name="status" value="<?= h($o['order_status'] ?? 'pending') ?>">
                                    <input type="date" id="delivery-date-<?= $o['order_id'] ?>" name="delivery_date" class="date-input-b2c" 
                                            value="<?= h($o['delivery_date'] ?? '') ?>" 
                                            min="<?= date('Y-m-d') ?>" 
                                            onchange="this.form.submit()"
                                            <?= $disableStatus ? 'disabled' : '' ?>>
                                </form>
                            </div>

                            <a class="btn-view-details-b2c" href="order_details_b2c.php?id=<?= (int)$o['order_id'] ?>">
                                <i class="fas fa-eye"></i>
                                View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
        <div class="empty-state-orders-b2c">
            <i class="fas fa-inbox"></i>
            <h3>No Customer Orders</h3>
            <p>There are currently no customer orders to display</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>