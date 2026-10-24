<?php
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php

// ====================================================================
// 1. CANCELLATION REQUEST ACTIONS (Must run first)
// ====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancellation_action'])) {
    $order_id = intval($_POST['order_id']);
    $action = $_POST['cancellation_action']; // 'approve' or 'reject'
    $admin_note = trim($_POST['admin_note'] ?? '');
    $success_message = '';
    $is_error = false;
    
    if ($action === 'approve') {
        $new_status = 'cancelled';
        $success_message = 'Cancellation Approved! Order #' . $order_id . ' is now cancelled.';
        
        $pdo->beginTransaction();
        try {
            // 1. Update order status in orders table. (Uses admin_note)
            $stmt_order = $pdo->prepare("
                UPDATE orders 
                SET status = ?, 
                    admin_note = ?,
                    cancellation_reason = NULL  /* Clear the reason after decision */
                WHERE id = ? AND status = 'cancellation_requested'
            ");
            $stmt_order->execute([$new_status, $admin_note, $order_id]);

            // Check if the order was actually updated
            if ($stmt_order->rowCount() === 0) {
                 throw new Exception("Order status was not 'cancellation_requested'. Status remains unchanged.");
            }

            // 2. Update deliveries status (only updates if a delivery record exists)
            $stmt_delivery = $pdo->prepare("
                UPDATE deliveries 
                SET status = ? 
                WHERE order_id = ?
            ");
            $stmt_delivery->execute([$new_status, $order_id]);

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $is_error = true;
            error_log("Cancellation Approval Error for Order #{$order_id}: " . $e->getMessage());
            $success_message = 'Database Error on Approval. Details: ' . h($e->getMessage());
        }

    } elseif ($action === 'reject') {
        // Rejecting sets the order status back to 'pending'
        $new_status = 'pending';
        $success_message = 'Cancellation Rejected! Order #' . $order_id . ' status reset to pending.';
        $is_error = false;
        
        try {
            // Rejection also uses 'admin_note' to record why it was rejected
            $stmt_order = $pdo->prepare("
                UPDATE orders 
                SET status = ?, 
                    admin_note = ?,
                    cancellation_reason = NULL /* Clear the reason after decision */
                WHERE id = ? AND status = 'cancellation_requested'
            ");
            $stmt_order->execute([$new_status, $admin_note, $order_id]);
        } catch (PDOException $e) {
             $is_error = true;
             error_log("Cancellation Rejection Error for Order #{$order_id}: " . $e->getMessage());
             $success_message = 'Database Error on Rejection. Details: ' . h($e->getMessage());
        }
    }
    
    // Display SweetAlert message and redirect regardless of success or failure
    $icon = $is_error ? 'error' : 'success';

    echo "<script>
    document.addEventListener('DOMContentLoaded', () => {
      Swal.fire({
        icon: '{$icon}',
        title: 'Action Complete!',
        text: '" . h($success_message) . "',
        confirmButtonColor: '#3085d6'
      }).then(() => {
        window.location.href = 'orders.php'; 
      });
    });
    </script>";
    exit; 
}

// ====================================================================
// 2. Handle delivery status and date update (Existing Logic)
// ====================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'], $_POST['status'])) {
    $orderId = $_POST['order_id'];
    $status = $_POST['status'];
    $deliveryDate = $_POST['delivery_date'] ?? null;

    // First, check the current order status to prevent updating a cancelled order
    $currentOrderStmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $currentOrderStmt->execute([$orderId]);
    $currentStatus = $currentOrderStmt->fetchColumn();

    // Do NOT update status if the order is already cancelled or cancellation requested
    if ($currentStatus === 'cancelled' || $currentStatus === 'cancellation_requested') {
         echo '<div class="alert alert-warning mt-3">⚠️ Cannot update status: Order is currently ' . h(ucfirst(str_replace('_', ' ', $currentStatus))) . '.</div>';
    } else {
        try {
            // Logic to update delivery or insert new delivery record
            $stmt = $pdo->prepare("SELECT id FROM deliveries WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $deliveryExists = $stmt->fetch();
            
            if ($deliveryExists) {
                $updateStmt = $pdo->prepare("UPDATE deliveries SET status=?, delivery_date=? WHERE order_id=?");
                $updateStmt->execute([$status, $deliveryDate, $orderId]);
            } else {
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

            // --- Update the main orders table status ---
            $updateOrderStmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $updateOrderStmt->execute([$status, $orderId]);

            echo '<div class="alert alert-success mt-3">✅ Delivery status updated.</div>';
        } catch (PDOException $e) {
            error_log("Delivery update failed: " . $e->getMessage());
            echo '<div class="alert alert-danger mt-3">❌ Error updating delivery status.</div>';
        }
    }
}

// ====================================================================
// 3. FETCH DATA 
// ====================================================================

// Fetch Cancellation Requests 
$stmt_cancellations = $pdo->query("
    SELECT 
        o.id, 
        o.total, 
        o.created_at, 
        o.cancellation_reason, 
        o.user_id, 
        u.name AS full_name, 
        u.email 
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.status = 'cancellation_requested' 
    ORDER BY o.created_at ASC
");
$cancellation_requests = $stmt_cancellations->fetchAll(PDO::FETCH_ASSOC);

// Fetch all orders and their delivery details 
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
    ORDER BY o.created_at DESC
";
$orders = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3"> Orders Management</h1>
        <a href="dashboard.php" class="btn btn-secondary">⬅ Back to Dashboard</a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 mt-5">
        <h2 class="h4 m-0">🚨 Pending Cancellation Requests</h2>
        <span class="badge bg-danger fs-6 text-white"><?= count($cancellation_requests) ?> Pending</span>
    </div>

    <div class="card shadow-sm border-0 mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-danger">
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Requested On</th>
                            <th>Reason (Snippet)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($cancellation_requests): ?>
                            <?php foreach ($cancellation_requests as $request): ?>
                                <tr class="cancellation-row" 
                                    data-id="<?= h($request['id']) ?>"
                                    data-name="<?= h($request['full_name']) ?>"
                                    data-email="<?= h($request['email']) ?>"
                                    data-total="₱<?= number_format($request['total'], 2) ?>"
                                    data-reason="<?= h($request['cancellation_reason']) ?>">
                                    
                                    <td>#<?= h($request['id']) ?></td>
                                    <td><?= h($request['full_name']) ?></td>
                                    <td>₱<?= number_format($request['total'], 2) ?></td>
                                    <td><?= date('M j, Y', strtotime($request['created_at'])) ?></td>
                                    <td style="max-width: 300px;" class="text-truncate">
                                        <?= h($request['cancellation_reason']) ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-review-cancellation">
                                            Review
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-muted py-4 text-center">No pending cancellation requests. 🎉</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <h2 class="h4 m-0 mb-3">📦 All Orders</h2>
    <?php if ($orders): ?>
        <div class="table-responsive shadow-sm rounded">
            <table class="table table-striped table-hover align-middle table-bordered">
                <thead class="table-dark text-center">
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Order Date</th>
                        <th>Status</th>
                        <th>Delivery Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
               <?php foreach ($orders as $o): ?>
                        <?php
                            $currentStatus = $o['order_status'];

                            // Badge class
                            $statusClass = match($currentStatus) {
                                'pending'   => 'bg-warning text-dark',
                                'shipped'   => 'bg-info text-dark',
                                'delivered' => 'bg-success text-white',
                                'cancellation_requested', 'cancelled' => 'bg-danger text-white',
                                'completed' => 'bg-success text-white', // Customer confirmed
                                default => 'bg-secondary text-white'
                            };

                            // Disable editing for cancelled, cancellation_requested, and completed
                            $disableStatus = in_array($currentStatus, ['cancellation_requested', 'cancelled', 'completed']);
                        ?>
                        <tr <?= $currentStatus === 'completed' ? 'class="table-success"' : '' ?>>
                            <td class="fw-bold text-center">#<?= (int)$o['order_id']; ?></td>
                            <td><?= h($o['customer_name'] ?? 'Guest'); ?></td>
                            <td class="fw-semibold text-end">₱<?= number_format($o['total'], 2); ?></td>
                            <td class="text-muted"><?= h($o['created_at']); ?></td>

                          <td class="text-center">
                            <?php 
                            // Include 'completed' in disabled statuses
                            $disableStatus = in_array($currentStatus, ['cancellation_requested', 'cancelled', 'completed']); 
                            ?>
                            
                            <?php if ($disableStatus): ?>
                                <span class="badge <?= $statusClass; ?>">
                                    <?= $currentStatus === 'completed' ? 'Received' : ucfirst(str_replace('_', ' ', $currentStatus)); ?>
                                </span>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['order_id']; ?>">
                                    <select name="status" class="form-select form-select-sm <?= $statusClass; ?>" onchange="this.form.submit()">
                                        <?php 
                                            $statusOptions = [
                                                'pending' => 'Pending',
                                                'shipped' => 'Preparing', 
                                                'delivered' => 'Delivered'
                                            ];
                                        ?>
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <option value="<?= $value ?>" <?php if ($currentStatus === $value) echo 'selected'; ?>>
                                                <?= $label ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>
                        </td>


                            <!-- Delivery Date -->
                            <td class="text-center">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="order_id" value="<?= (int)$o['order_id']; ?>">
                                    <input type="hidden" name="status" value="<?= h($o['delivery_status'] ?? 'pending'); ?>">
                                    <input type="date" name="delivery_date" class="form-control form-control-sm" 
                                        value="<?= h($o['delivery_date'] ?? ''); ?>" onchange="this.form.submit()"
                                        <?= $disableStatus ? 'disabled' : ''; ?>>
                                </form>
                            </td>

                            <!-- Actions -->
                            <td class="text-center">
                                <a class="btn btn-sm btn-outline-primary" href="order_details.php?id=<?= (int)$o['order_id']; ?>">
                                    View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info">📭 No orders found.</div>
    <?php endif; ?>
</div>

<div class="modal fade" id="cancellationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Review Cancellation Request: Order #<span id="cancellationModalOrderId"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>Customer:</strong> <span id="cancellationModalName"></span> (<span id="cancellationModalEmail"></span>)</p>
        <p><strong>Order Total:</strong> <span id="cancellationModalTotal"></span></p>
        <hr>
        <h6>Reason for Cancellation:</h6>
        <p id="cancellationModalReason" class="alert alert-warning text-break"></p>
        <hr>
        
        <form method="POST" id="cancellationActionForm">
          <input type="hidden" name="order_id" id="cancellationModalOrderIdInput">
          <input type="hidden" name="cancellation_action" id="cancellationModalActionInput">

          <div class="mb-3">
            <label for="adminNote" class="form-label">Admin Note (Optional):</label>
            <textarea name="admin_note" id="cancellationAdminNote" class="form-control" rows="3" placeholder="e.g., 'Refund processed', 'Customer booked wrong service.'"></textarea>
          </div>
          
          <div class="d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-success" id="btnApprove">
                <i class="bi bi-check-circle"></i> Approve & Cancel Order
            </button>
            <button type="button" class="btn btn-secondary" id="btnReject">
                <i class="bi bi-x-circle"></i> Reject & Keep Pending
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
  .cancellation-row:hover { cursor: pointer; }
  #cancellationModalReason { white-space: pre-wrap; }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    
  // ====================================================================
  // CANCELLATION REQUEST JAVASCRIPT
  // ====================================================================
  const cancellationModal = new bootstrap.Modal(document.getElementById('cancellationModal'));
  const cModalOrderId = document.getElementById('cancellationModalOrderId');
  const cModalName = document.getElementById('cancellationModalName');
  const cModalEmail = document.getElementById('cancellationModalEmail');
  const cModalTotal = document.getElementById('cancellationModalTotal');
  const cModalReason = document.getElementById('cancellationModalReason');
  const cModalOrderIdInput = document.getElementById('cancellationModalOrderIdInput');
  const cModalActionInput = document.getElementById('cancellationModalActionInput');
  const cBtnApprove = document.getElementById('btnApprove');
  const cBtnReject = document.getElementById('btnReject');
  const cForm = document.getElementById('cancellationActionForm');

  // 📬 Handle request click
  document.querySelectorAll('.btn-review-cancellation').forEach(button => {
    button.addEventListener('click', e => {
      e.stopPropagation(); 
      const row = button.closest('.cancellation-row');

      cModalOrderId.textContent = row.dataset.id;
      cModalName.textContent = row.dataset.name;
      cModalEmail.textContent = row.dataset.email;
      cModalTotal.textContent = row.dataset.total;
      cModalReason.textContent = row.dataset.reason;
      cModalOrderIdInput.value = row.dataset.id;
      
      cancellationModal.show();
    });
  });
  
  // ✅ Approve button handler
  cBtnApprove.addEventListener('click', () => {
    Swal.fire({
      title: 'Confirm Approval?',
      text: "This will set the order and delivery status to 'Cancelled'.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#28a745',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, Approve Cancellation!'
    }).then(result => {
      if (result.isConfirmed) {
        cModalActionInput.value = 'approve';
        cForm.submit();
      }
    });
  });

  // ❌ Reject button handler
  cBtnReject.addEventListener('click', () => {
    Swal.fire({
      title: 'Confirm Rejection?',
      text: "This will set the order status back to 'Pending'.",
      icon: 'info',
      showCancelButton: true,
      confirmButtonColor: '#ffc107',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, Reject Request!'
    }).then(result => {
      if (result.isConfirmed) {
        cModalActionInput.value = 'reject';
        cForm.submit();
      }
    });
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
