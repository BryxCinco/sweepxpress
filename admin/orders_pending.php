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

// ====================================================================
// 1. Handle delivery status and date update (POST)
// Note: This logic now only expects a status (the current one via hidden field) 
// and delivery date, as the status dropdown is removed from the UI.
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $orderId = $_POST['order_id'];
    $status = $_POST['status']; // Retain current status
    $deliveryDate = $_POST['delivery_date'] ?? null;

    $currentOrderStmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $currentOrderStmt->execute([$orderId]);
    $currentStatus = $currentOrderStmt->fetchColumn();

    if ($currentStatus === 'cancelled' || $currentStatus === 'cancellation_requested') {
        error_log('Attempt to update cancelled order: ' . $orderId);
        $post_warning = 'update_denied_cancelled';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id FROM deliveries WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $deliveryExists = $stmt->fetch();

            if ($deliveryExists) {
                // Only update status in deliveries table if status is actually changing (though UI prevents it now)
                $updateStmt = $pdo->prepare("UPDATE deliveries SET status=?, delivery_date=? WHERE order_id=?");
                $updateStmt->execute([$status, $deliveryDate, $orderId]);
            } else {
                $infoStmt = $pdo->prepare("SELECT o.address, o.customer_name FROM orders o WHERE o.id = ?");
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

            // Since only date is user-editable, only update delivery_date implicitly via POST
            // The order status update is still technically possible but driven by hidden fields now.
            
            $redirectStatus = urlencode(ucfirst(str_replace('_', ' ', $status)));
            header("Location: orders_pending.php?success={$orderId}&status={$redirectStatus}&dated=true");
            exit;

        } catch (PDOException $e) {
            error_log("Delivery update failed: " . $e->getMessage());
            $post_warning = 'update_failed';
        }
    }

    if (!isset($post_warning)) {
        header("Location: orders_pending.php");
        exit;
    }
}

// ====================================================================
// 2. Include header
// ====================================================================
require_once __DIR__ . '/../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php
// ====================================================================
// 3. Display success/warning messages
// ====================================================================
$swal_title = '';
$swal_text = '';
$swal_icon = '';

if (isset($_GET['success'], $_GET['status'], $_GET['dated'])) {
    $orderId = h($_GET['success']);
    $newStatus = h($_GET['status']);

    $swal_title = 'Delivery Date Updated!';
    $swal_text = "Order #{$orderId} delivery date has been set/updated.";
    $swal_icon = 'success';

} elseif (isset($post_warning)) {
    if ($post_warning === 'update_denied_cancelled') {
        $swal_title = 'Action Denied';
        $swal_text = 'Cannot update status/date: Order is currently cancelled or cancellation requested.';
        $swal_icon = 'warning';
    } elseif ($post_warning === 'update_failed') {
        $swal_title = 'Update Failed';
        $swal_text = 'An error occurred while updating the delivery status. Please check logs.';
        $swal_icon = 'error';
    }
}

if (!empty($swal_title)):
?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    Swal.fire({
        icon: '<?= $swal_icon ?>',
        title: '<?= $swal_title ?>',
        text: '<?= $swal_text ?>',
        confirmButtonColor: '#f59e0b'
    }).then(() => {
        if (history.replaceState) {
            history.replaceState(null, null, window.location.pathname);
        }
    });
});
</script>
<?php endif; ?>

<?php
// ====================================================================
// 4. Fetch business orders
// ====================================================================
$sql = "
    SELECT 
        o.id AS order_id,
        u.name AS business_name,
        o.total,
        o.created_at,
        o.status AS order_status,
        d.id AS delivery_id,
        d.status AS delivery_status,
        d.delivery_date
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    LEFT JOIN deliveries d ON o.id = d.order_id
    WHERE u.role = 'business'
    ORDER BY o.created_at DESC
";
$orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Helper function to get status details
function get_status_details($currentStatus) {
    // Delivery date is disabled for editing if it's already cancelled or completed
    $disableStatus = in_array($currentStatus, ['cancellation_requested', 'cancelled', 'completed', 'delivered']);
    
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

.orders-b2b-container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.orders-b2b-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-orders-b2b {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-orders-b2b i {
    color: #f59e0b;
}

.btn-back-orders-b2b {
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

.btn-back-orders-b2b:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* STATS CARD */
.stats-card-orders-b2b {
    background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
    border-radius: 16px;
    padding: 20px 28px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 10px 24px rgba(245, 158, 11, 0.3);
    margin-bottom: 24px;
}

.stats-icon-orders-b2b {
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

.stats-content-orders-b2b h2 {
    font-size: 0.85rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px 0;
}

.stats-content-orders-b2b .stats-value-orders-b2b {
    font-size: 2rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1;
}

/* TABLE CARD (Desktop) */
.table-card-orders-b2b {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow: hidden;
}

.table-orders-b2b {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-orders-b2b thead {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
}

.table-orders-b2b thead th {
    padding: 18px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #92400e;
    border-bottom: 2px solid #f59e0b;
    white-space: nowrap;
}

.table-orders-b2b thead th.text-center {
    text-align: center;
}

.table-orders-b2b tbody td {
    padding: 18px 20px;
    font-size: 0.95rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.table-orders-b2b tbody tr:hover {
    background: #fffbeb;
}

.table-orders-b2b tbody tr:last-child td {
    border-bottom: none;
}

/* ORDER ID BADGE */
.order-id-badge-b2b {
    display: inline-flex;
    padding: 6px 12px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-weight: 700;
    color: #0f172a;
}

/* BUSINESS NAME */
.business-name-b2b {
    font-weight: 600;
    color: #0f172a;
}

/* PRICE */
.price-display-b2b {
    font-weight: 700;
    color: #10b981;
}

/* STATUS BADGES */
.status-badge-b2b {
    display: inline-flex;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: capitalize;
    white-space: nowrap;
}

.status-badge-b2b.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge-b2b.preparing {
    background: #dbeafe;
    color: #1e40af;
}

.status-badge-b2b.delivered {
    background: #d1fae5;
    color: #065f46;
}

.status-badge-b2b.completed {
    background: #e0e7ff;
    color: #3730a3;
}

.status-badge-b2b.cancelled {
    background: #fee2e2;
    color: #991b1b;
}

/* FORM CONTROLS */
/* Kept styles here in case they are used elsewhere */
.form-select-b2b {
    padding: 8px 12px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.2s;
    min-width: 180px;
}

.form-select-b2b:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
}

.date-input-b2b {
    padding: 8px 12px;
    font-size: 0.85rem;
    font-weight: 500;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #ffffff;
    transition: all 0.2s;
    min-width: 150px;
}

.date-input-b2b:focus {
    outline: none;
    border-color: #f59e0b;
    box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
}

.date-input-b2b:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background: #f3f4f6;
}

/* VIEW DETAILS BUTTON */
.btn-view-details-b2b {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #ffffff;
    color: #f59e0b;
    border: 1.5px solid #f59e0b;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-view-details-b2b:hover {
    background: #f59e0b;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
}

/* EMPTY STATE */
.empty-state-orders-b2b {
    text-align: center;
    padding: 80px 32px;
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
}

.empty-state-orders-b2b i {
    font-size: 4rem;
    color: #94a3b8;
    margin-bottom: 20px;
}

.empty-state-orders-b2b h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
}

.empty-state-orders-b2b p {
    font-size: 0.95rem;
    color: #94a3b8;
}


/* --- MOBILE CARD STYLES (CLEAN LAYOUT) --- */
.orders-b2b-mobile {
    display: none; /* Hidden by default, shown on mobile */
}

.order-cards-b2b {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.order-card-b2b {
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

/* Mobile form control sizing adjustment */
.order-cards-b2b .date-input-b2b {
    flex-grow: 1;
    min-width: 150px; 
    width: auto;
    font-size: 0.85rem; 
    padding: 6px 10px;
}

.order-cards-b2b .btn-view-details-b2b {
    width: 100%;
    justify-content: center;
    margin-top: 10px;
}

/* Media Query to switch views (Hiding the table and showing the clean cards) */
@media (max-width: 1024px) {
    .table-card-orders-b2b { 
        display: none; /* Hide Table */
    } 
    .orders-b2b-mobile { 
        display: block; /* Show Cards */
    }
    .orders-b2b-container {
        padding: 24px 16px 40px;
    }
}
</style>

<div class="orders-b2b-container">
    <div class="orders-b2b-header">
        <h1 class="page-title-orders-b2b">
            <i class="fas fa-building"></i>
            Business Orders Management
        </h1>
        <a href="allorders.php" class="btn-back-orders-b2b">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

    <div class="stats-card-orders-b2b">
        <div class="stats-icon-orders-b2b">
            <i class="fas fa-briefcase"></i>
        </div>
        <div class="stats-content-orders-b2b">
            <h2>Business Orders</h2>
            <span class="stats-value-orders-b2b"><?= number_format(count($orders)) ?></span>
        </div>
    </div>

    <?php if ($orders): ?>
    
        <div class="table-card-orders-b2b">
            <div class="table-responsive">
                <table class="table-orders-b2b">
                    <thead>
                        <tr>
                            <th class="text-center">Order ID</th>
                            <th>Business Name</th>
                            <th>Total</th>
                            <th>Order Date</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Delivery Date</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): 
                            [$disableStatus, $statusBadgeClass, $displayStatusText] = get_status_details($o['order_status']);
                        ?>
                            <tr>
                                <td class="text-center" data-label="Order ID">
                                    <span class="order-id-badge-b2b">#<?= (int)$o['order_id'] ?></span>
                                </td>
                                <td class="business-name-b2b" data-label="Business Name"><?= h($o['business_name'] ?? 'Business') ?></td>
                                <td data-label="Total">
                                    <span class="price-display-b2b">₱<?= number_format($o['total'], 2) ?></span>
                                </td>
                                <td style="color: #64748b;" data-label="Order Date">
                                    <?= date('M d, Y • h:i A', strtotime($o['created_at'])) ?>
                                </td>
                                <td class="text-center" data-label="Status">
                                    <span class="status-badge-b2b <?= $statusBadgeClass ?>">
                                        <?= h($displayStatusText) ?>
                                    </span>
                                </td>
                                <td class="text-center" data-label="Delivery Date">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="order_id" value="<?= (int)$o['order_id'] ?>">
                                        <input type="hidden" name="status" value="<?= h($o['order_status'] ?? 'pending') ?>">
                                        <input type="date" name="delivery_date" class="date-input-b2b" 
                                                value="<?= h($o['delivery_date'] ?? '') ?>" 
                                                min="<?= date('Y-m-d') ?>" 
                                                onchange="this.form.submit()"
                                                <?= $disableStatus ? 'disabled' : '' ?>>
                                    </form>
                                </td>
                                <td class="text-center" data-label="Actions">
                                    <a class="btn-view-details-b2b" href="order_details_b2b.php?id=<?= (int)$o['order_id'] ?>">
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


        <div class="orders-b2b-mobile">
            <div class="order-cards-b2b">
                <?php foreach ($orders as $o): 
                    [$disableStatus, $statusBadgeClass, $displayStatusText] = get_status_details($o['order_status']);
                ?>
                    <div class="order-card-b2b">
                        <div class="order-card-header">
                            <span class="order-card-id">Order #<?= (int)$o['order_id'] ?></span>
                            <span class="status-badge-b2b <?= $statusBadgeClass ?>">
                                <?= h($displayStatusText) ?>
                            </span>
                        </div>

                        <div class="order-card-details">
                            <div class="detail-item">
                                <span class="detail-label">Business Name</span>
                                <span><?= h($o['business_name'] ?? 'Business') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Total</span>
                                <span class="price-display-b2b">₱<?= number_format($o['total'], 2) ?></span>
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
                                    <input type="date" id="delivery-date-<?= $o['order_id'] ?>" name="delivery_date" class="date-input-b2b" 
                                            value="<?= h($o['delivery_date'] ?? '') ?>" 
                                            min="<?= date('Y-m-d') ?>" 
                                            onchange="this.form.submit()"
                                            <?= $disableStatus ? 'disabled' : '' ?>>
                                </form>
                            </div>

                            <a class="btn-view-details-b2b" href="order_details_b2b.php?id=<?= (int)$o['order_id'] ?>">
                                <i class="fas fa-eye"></i>
                                View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
        <div class="empty-state-orders-b2b">
            <i class="fas fa-inbox"></i>
            <h3>No Pending Business Orders</h3>
            <p>All business orders have been processed or there are none to display.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>