<?php
// require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';

$order_id = (int)($_GET['order_id'] ?? 0);
if (!$order_id) {
    echo "<p class='alert error'>Invalid order ID.</p>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// fetch order (IMPORTANT: Fetches the current 'status' of the order)
$stmt = $pdo->prepare("
    SELECT o.*, u.name AS customer_name, u.email 
    FROM orders o 
    LEFT JOIN users u ON o.user_id=u.id 
    WHERE o.id=?
");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    echo "<p class='alert error'>Order not found.</p>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// fetch delivery if exists
$stmt = $pdo->prepare("SELECT * FROM deliveries WHERE order_id=? ORDER BY id DESC LIMIT 1");
$stmt->execute([$order_id]);
$delivery = $stmt->fetch();

// ====================================================================
// NEW: Handle form with transaction to keep Orders table synced
// ====================================================================
if ($_SERVER['REQUEST_METHOD']==='POST') {
    
    // --- NEW CHECK: PREVENT UPDATE IF ORDER IS ALREADY FINAL ---
    if ($order['status'] === 'delivered' || $order['status'] === 'cancelled') {
        echo '<div class="alert alert-warning">⚠️ Cannot change status. Order is already **' . ucfirst($order['status']) . '** and finalized.</div>';
    } else {
        // --- ORIGINAL UPDATE LOGIC (Now inside the else block) ---
        $status = $_POST['status'];
        $date = empty($_POST['delivery_date']) ? NULL : $_POST['delivery_date'];

        $pdo->beginTransaction(); // Start transaction for atomicity
        try {
            if ($delivery) {
                // update deliveries
                $stmt_delivery = $pdo->prepare("UPDATE deliveries SET status=?, delivery_date=? WHERE id=?");
                $stmt_delivery->execute([$status, $date, $delivery['id']]);
            } else {
                // insert delivery
                $stmt_delivery = $pdo->prepare("
                    INSERT INTO deliveries (order_id, customer_name, address, status, delivery_date)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt_delivery->execute([
                    $order_id,
                    $order['customer_name'] ?? 'Guest',
                    $order['address'] ?? 'Unknown',
                    $status,
                    $date
                ]);
            }

            // Update orders table to keep statuses aligned
            $stmt_order = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt_order->execute([$status, $order_id]);
            
            $pdo->commit(); // Commit all changes

            echo "<p class='alert alert-success'>✅ Delivery and Order updated!</p>"; 
            
            // reload delivery info from the database
            $stmt = $pdo->prepare("SELECT * FROM deliveries WHERE order_id=? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$order_id]);
            $delivery = $stmt->fetch();

            // Refresh the $order array with the new status
            $order['status'] = $status; 

        } catch (PDOException $e) {
            $pdo->rollBack(); // Rollback on error
            error_log("Delivery edit failed for Order #{$order_id}: " . $e->getMessage());
            echo "<p class='alert alert-danger'>❌ Error updating delivery and order: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}
?>

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Manage Delivery for Order #<?php echo $order_id; ?></h1>
        <a href="orders.php" class="btn btn-secondary">⬅ Back to Orders</a>
    </div>

    <div class="card shadow-sm p-4">
        <p><strong>Customer:</strong> <?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($order['email']); ?></p>
        <p><strong>Shipping Address:</strong> <?php echo htmlspecialchars($order['address'] ?? 'N/A'); ?></p>
        <p><strong>Order Total:</strong> ₱<?php echo number_format($order['total'],2); ?></p>
        <p><strong>Current Order Status:</strong> 
            <span class="badge 
                <?php 
                    echo match($order['status']) {
                        'delivered' => 'bg-success text-white',
                        'cancelled' => 'bg-danger text-white',
                        'shipped'   => 'bg-info text-dark',
                        default     => 'bg-warning text-dark'
                    };
                ?>">
                <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
            </span>
        </p>

        <hr>
        
        <?php 
            // Check again for display purposes
            $is_final_status = ($order['status'] === 'delivered' || $order['status'] === 'cancelled'); 
        ?>

        <?php if ($is_final_status): ?>
            <div class="alert alert-info">
                This order is finalized (<?php echo ucfirst($order['status']); ?>). Status cannot be modified.
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="mb-3">
                <label for="status" class="form-label fw-bold">Delivery Status</label>
                <select name="status" id="status" class="form-select" required 
                        <?php echo $is_final_status ? 'disabled' : ''; ?>>
                    <?php
                    $statuses = ['pending','shipped','delivered','cancelled'];
                    $current_status = $delivery ? $delivery['status'] : 'pending';
                    foreach ($statuses as $s) {
                        $sel = ($current_status === $s) ? 'selected' : '';
                        echo "<option value='{$s}' {$sel}>".ucfirst($s)."</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-4">
                <label for="delivery_date" class="form-label fw-bold">Delivery Date (Expected/Actual)</label>
                <input type="date" name="delivery_date" id="delivery_date" class="form-control"
                       value="<?php echo htmlspecialchars($delivery['delivery_date'] ?? ''); ?>"
                       <?php echo $is_final_status ? 'disabled' : ''; ?>>
            </div>

            <button type="submit" class="btn btn-primary" <?php echo $is_final_status ? 'disabled' : ''; ?>>
                Save Delivery Details
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>