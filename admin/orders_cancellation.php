<?php
// orders_cancellation.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php'; 
require_once __DIR__ . '/../vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer; 
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// Mail credentials galing sa includes/email_config.php (.env) — nilo-load sa loob ng send_cancellation_email().

function send_cancellation_email($pdo, $recipientEmail, $recipientName, $orderId, $action, $adminNote) {
    $mail = null;

    try {
        require_once __DIR__ . '/../includes/email_config.php';
        $mail = make_mailer();
        $mail->setFrom(MAIL_USERNAME, 'SweepXpress Admin Team');
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addBCC(MAIL_USERNAME, 'Admin Team'); // admin copy = mail account

        $mail->isHTML(true);
        $orderLink = rtrim(env('APP_URL', 'http://localhost/sweepxpress'), '/')
            . "/customers/order_details.php?id=" . (int)$orderId;
        
        if ($action === 'approved') {
            $mail->Subject = 'Order Cancellation Confirmed (Order #' . $orderId . ')';
            $body = "
                <h2>✅ Cancellation Approved for Order #{$orderId}</h2>
                <p>Dear " . h($recipientName) . ",</p>
                <p>This is to confirm that your cancellation request for Order #{$orderId} has been APPROVED by the administration.</p>
                <p>The order status is now Cancelled.</p>
                <p><strong>Admin Note:</strong> " . (empty($adminNote) ? 'N/A' : h($adminNote)) . "</p>
                <p>You can view your order details here: <a href='" . h($orderLink) . "'>View Order #{$orderId}</a></p>
                <p>Thank you for choosing SweepXpress.</p>
            ";
        } else {
            $mail->Subject = 'Update on Cancellation Request (Order #' . $orderId . ')';
            $body = "
                <h2>❌ Cancellation Request Rejected for Order #{$orderId}</h2>
                <p>Dear " . h($recipientName) . ",</p>
                <p>We regret to inform you that your cancellation request for Order #{$orderId} has been REJECTED by the administration.</p>
                <p>The order status has been reset to Pending.</p>
                <p><strong>Admin Note:</strong> " . (empty($adminNote) ? 'No note provided.' : h($adminNote)) . "</p>
                <p>If you have any questions, please reply to this email.</p>
                <p>You can view your order details here: <a href='" . h($orderLink) . "'>View Order #{$orderId}</a></p>
            ";
        }

        $mail->Body = $body;
        $mail->AltBody = strip_tags($body); 

        $mail->send();
        return true;

    } catch (Throwable $e) {
        $error = $mail ? $mail->ErrorInfo : $e->getMessage();
        error_log("Email Error for Order #{$orderId} ({$action}): {$error}");
        return true; 
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancellation_action'])) {
    $order_id = intval($_POST['order_id']);
    $action = $_POST['cancellation_action']; 
    $admin_note = trim($_POST['admin_note'] ?? '');
    $success_message = '';
    $is_error = false;
    
    $stmt_user = $pdo->prepare("
        SELECT u.name, u.email 
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        WHERE o.id = ?
    ");
    $stmt_user->execute([$order_id]);
    $user_info = $stmt_user->fetch(PDO::FETCH_ASSOC);

    $recipient_email = $user_info['email'] ?? '';
    $recipient_name = $user_info['name'] ?? 'Customer';
    
    if ($action === 'approve') {
        $new_status = 'cancelled';
        $success_message = 'Cancellation Approved! Order #' . $order_id . ' is now cancelled.';
        
        $pdo->beginTransaction();
        try {
            $stmt_order = $pdo->prepare("
                UPDATE orders 
                SET status = ?, 
                    admin_note = ?,
                    cancellation_reason = NULL  
                WHERE id = ? AND status = 'cancellation_requested'
            ");
            $stmt_order->execute([$new_status, $admin_note, $order_id]);

            if ($stmt_order->rowCount() === 0) {
                 throw new Exception("Order status was not 'cancellation_requested'. Status remains unchanged.");
            }

            $stmt_delivery = $pdo->prepare("
                UPDATE deliveries 
                SET status = ? 
                WHERE order_id = ?
            ");
            $stmt_delivery->execute([$new_status, $order_id]);

            $pdo->commit();
            
            if (!empty($recipient_email)) {
                send_cancellation_email($pdo, $recipient_email, $recipient_name, $order_id, 'approved', $admin_note);
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $is_error = true;
            error_log("Cancellation Approval Error for Order #{$order_id}: " . $e->getMessage());
            $success_message = 'Database Error on Approval. Details: ' . h($e->getMessage());
        }

    } elseif ($action === 'reject') {
        $new_status = 'pending';
        $success_message = 'Cancellation Rejected! Order #' . $order_id . ' status reset to pending.';
        $is_error = false;
        
        try {
            $stmt_order = $pdo->prepare("
                UPDATE orders 
                SET status = ?, 
                    admin_note = ?,
                    cancellation_reason = NULL 
                WHERE id = ? AND status = 'cancellation_requested'
            ");
            $stmt_order->execute([$new_status, $admin_note, $order_id]);
            
            if ($stmt_order->rowCount() === 0) {
                 throw new Exception("Order status was not 'cancellation_requested'. Status remains unchanged.");
            }
            
            if (!empty($recipient_email)) {
                send_cancellation_email($pdo, $recipient_email, $recipient_name, $order_id, 'rejected', $admin_note);
            }
            
        } catch (PDOException $e) {
             $is_error = true;
             error_log("Cancellation Rejection Error for Order #{$order_id}: " . $e->getMessage());
             $success_message = 'Database Error on Rejection. Details: ' . h($e->getMessage());
        } catch (Exception $e) { 
             $is_error = true;
             $success_message = 'Rejection Failed. Order status was already changed (e.g., manually or already pending).';
        }
    }
    
    $icon = $is_error ? 'error' : 'success';

    echo "<script>
    document.addEventListener('DOMContentLoaded', () => {
      Swal.fire({
        icon: '{$icon}',
        title: 'Action Complete!',
        text: '" . h($success_message) . "',
        confirmButtonColor: '#3085d6'
      }).then(() => {
        window.location.href = 'orders_cancellation.php'; 
      });
    });
    </script>";
    exit; 
}

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

.cancellation-orders-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 40px 32px 64px;
}

/* HEADER */
.cancellation-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-title-cancellation {
    font-size: 2rem;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.page-title-cancellation i {
    color: #ef4444;
}

.btn-back-cancellation {
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

.btn-back-cancellation:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    color: #0f172a;
}

/* STATS CARD */
.stats-card-cancellation {
    background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    border-radius: 16px;
    padding: 20px 28px;
    display: inline-flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 10px 24px rgba(239, 68, 68, 0.3);
    margin-bottom: 32px;
}

.stats-icon-cancellation {
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

.stats-content-cancellation h2 {
    font-size: 0.85rem;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 4px 0;
}

.stats-content-cancellation .stats-value-cancellation {
    font-size: 2rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1;
}

/* TABLE CARD */
.table-card-cancellation {
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
    overflow: hidden;
}

.table-cancellation {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.table-cancellation thead {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
}

.table-cancellation thead th {
    padding: 18px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #991b1b;
    border-bottom: 2px solid #ef4444;
    white-space: nowrap;
}

.table-cancellation thead th.text-center {
    text-align: center;
}

.table-cancellation tbody td {
    padding: 18px 20px;
    font-size: 0.95rem;
    color: #0f172a;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.table-cancellation tbody tr {
    transition: background 0.2s;
    cursor: pointer;
}

.table-cancellation tbody tr:hover {
    background: #f9fafb;
}

.table-cancellation tbody tr:last-child td {
    border-bottom: none;
}

/* ORDER ID BADGE */
.order-id-badge-cancellation {
    display: inline-flex;
    padding: 6px 12px;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-weight: 700;
    color: #0f172a;
}

/* CUSTOMER NAME */
.customer-name-cell {
    font-weight: 600;
    color: #0f172a;
}

/* PRICE */
.price-display-cancellation {
    font-weight: 700;
    color: #10b981;
}

/* REASON SNIPPET */
.reason-snippet-cancellation {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #64748b;
}

/* REVIEW BUTTON */
.btn-review-cancellation {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #ffffff;
    color: #ef4444;
    border: 1.5px solid #ef4444;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
    cursor: pointer;
}

.btn-review-cancellation:hover {
    background: #ef4444;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* EMPTY STATE */
.empty-state-cancellation {
    text-align: center;
    padding: 80px 32px;
    background: #ffffff;
    border-radius: 20px;
    border: 1px solid #e5e7eb;
    box-shadow: 0 10px 32px rgba(0,0,0,0.12);
}

.empty-state-cancellation i {
    font-size: 4rem;
    color: #94a3b8;
    margin-bottom: 20px;
}

.empty-state-cancellation h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 8px;
}

.empty-state-cancellation p {
    font-size: 0.95rem;
    color: #94a3b8;
}

/* INFO BANNER */
.info-banner-cancellation {
    background: #dbeafe;
    border: 1px solid #93c5fd;
    border-radius: 12px;
    padding: 16px 20px;
    color: #1e40af;
    font-size: 0.95rem;
    margin-top: 32px;
}

.info-banner-cancellation a {
    color: #1e40af;
    font-weight: 600;
    text-decoration: underline;
}

/* MODAL STYLING */
.modal-content-cancellation {
    border-radius: 16px;
    border: none;
}

.modal-header-cancellation {
    background: linear-gradient(135deg, #ef4444 0%, #f87171 100%);
    color: #ffffff;
    border-radius: 16px 16px 0 0;
    padding: 20px 24px;
}

.modal-header-cancellation h5 {
    font-weight: 700;
    margin: 0;
}

.modal-body-cancellation {
    padding: 28px;
}

.info-row-modal {
    margin-bottom: 12px;
    font-size: 0.95rem;
    color: #64748b;
}

.info-row-modal strong {
    color: #0f172a;
    font-weight: 600;
}

.reason-box-modal {
    background: #fef3c7;
    border: 1px solid #fde047;
    border-radius: 8px;
    padding: 16px;
    white-space: pre-wrap;
    word-break: break-word;
    color: #854d0e;
}

.form-label-modal {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 8px;
}

.form-control-modal {
    width: 100%;
    padding: 12px 14px;
    font-size: 0.95rem;
    color: #0f172a;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    resize: vertical;
    transition: border-color 0.2s;
}

.form-control-modal:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.modal-footer-cancellation {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 0;
    margin-top: 24px;
}

.btn-approve-modal {
    padding: 12px 24px;
    background: #10b981;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-approve-modal:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-reject-modal {
    padding: 12px 24px;
    background: #6b7280;
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-reject-modal:hover {
    background: #4b5563;
    transform: translateY(-1px);
}

/* RESPONSIVE */
@media (max-width: 768px) {
    .cancellation-orders-container {
        padding: 24px 16px 40px;
    }
    
    .cancellation-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .table-cancellation {
        font-size: 0.85rem;
    }
    
    .table-cancellation thead th,
    .table-cancellation tbody td {
        padding: 12px 14px;
    }
}
</style>

<div class="cancellation-orders-container">
    <!-- HEADER -->
    <div class="cancellation-header">
        <h1 class="page-title-cancellation">
            <i class="fas fa-exclamation-triangle"></i>
            Cancellation Requests
        </h1>
        <a href="allorders.php" class="btn-back-cancellation">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
    </div>

    <!-- STATS -->
    <div class="stats-card-cancellation">
        <div class="stats-icon-cancellation">
            <i class="fas fa-ban"></i>
        </div>
        <div class="stats-content-cancellation">
            <h2>Pending Requests</h2>
            <span class="stats-value-cancellation"><?= count($cancellation_requests) ?></span>
        </div>
    </div>

    <!-- TABLE -->
    <?php if ($cancellation_requests): ?>
        <div class="table-card-cancellation">
            <div class="table-responsive">
                <table class="table-cancellation">
                    <thead>
                        <tr>
                            <th class="text-center">Order ID</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Requested On</th>
                            <th>Reason</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cancellation_requests as $request): ?>
                            <tr class="cancellation-row" 
                                data-id="<?= h($request['id']) ?>"
                                data-name="<?= h($request['full_name']) ?>"
                                data-email="<?= h($request['email']) ?>"
                                data-total="₱<?= number_format($request['total'], 2) ?>"
                                data-reason="<?= h($request['cancellation_reason']) ?>">
                                
                                <td class="text-center">
                                    <span class="order-id-badge-cancellation">#<?= h($request['id']) ?></span>
                                </td>
                                <td class="customer-name-cell">
                                    <?= h($request['full_name']) ?>
                                </td>
                                <td>
                                    <span class="price-display-cancellation">₱<?= number_format($request['total'], 2) ?></span>
                                </td>
                                <td style="color: #64748b;">
                                    <?= date('M d, Y • h:i A', strtotime($request['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="reason-snippet-cancellation"><?= h($request['cancellation_reason']) ?></div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn-review-cancellation btn-review-cancellation-action">
                                        <i class="fas fa-eye"></i>
                                        Review
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state-cancellation">
            <i class="fas fa-check-circle"></i>
            <h3>All Requests Processed</h3>
            <p>No pending cancellation requests at this time</p>
        </div>
    <?php endif; ?>
    
    <!-- INFO BANNER -->
    <div class="info-banner-cancellation">
        Return to the <a href="allorders.php">Orders Dashboard</a> to manage all other order statuses.
    </div>

</div>

<!-- MODAL -->
<div class="modal fade" id="cancellationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content modal-content-cancellation">
      <div class="modal-header modal-header-cancellation">
        <h5>Review Cancellation: Order #<span id="cancellationModalOrderId"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body modal-body-cancellation">
        <div class="info-row-modal">
          <strong>Customer:</strong> <span id="cancellationModalName"></span> (<span id="cancellationModalEmail"></span>)
        </div>
        <div class="info-row-modal">
          <strong>Order Total:</strong> <span id="cancellationModalTotal"></span>
        </div>
        
        <hr style="border-color: #e5e7eb; margin: 20px 0;">
        
        <h6 style="font-weight: 700; margin-bottom: 12px; font-size: 1rem;">Reason for Cancellation:</h6>
        <div id="cancellationModalReason" class="reason-box-modal"></div>
        
        <hr style="border-color: #e5e7eb; margin: 20px 0;">
        
        <form method="POST" id="cancellationActionForm">
          <input type="hidden" name="order_id" id="cancellationModalOrderIdInput">
          <input type="hidden" name="cancellation_action" id="cancellationModalActionInput">

          <div class="mb-3">
            <label for="adminNote" class="form-label-modal">Admin Note (Optional)</label>
            <textarea name="admin_note" id="cancellationAdminNote" class="form-control-modal" rows="3" placeholder="e.g., 'Refund processed', 'Customer requested order change'"></textarea>
          </div>
          
          <div class="modal-footer-cancellation">
            <button type="button" class="btn-approve-modal" id="btnApprove">
                <i class="fas fa-check-circle"></i> Approve Cancellation
            </button>
            <button type="button" class="btn-reject-modal" id="btnReject">
                <i class="fas fa-times-circle"></i> Reject Request
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    
  if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
    console.error("Bootstrap 5 is not loaded or the Modal component is missing.");
    return; 
  }

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

  document.querySelectorAll('.btn-review-cancellation-action').forEach(button => {
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
  
  cBtnApprove.addEventListener('click', () => {
    Swal.fire({
      title: 'Confirm Approval?',
      text: "This will set the order status to 'Cancelled' and notify the customer.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, Approve Cancellation'
    }).then(result => {
      if (result.isConfirmed) {
        cModalActionInput.value = 'approve';
        cForm.submit();
      }
    });
  });

  cBtnReject.addEventListener('click', () => {
    Swal.fire({
      title: 'Confirm Rejection?',
      text: "This will set the order status back to 'Pending' and notify the customer.",
      icon: 'info',
      showCancelButton: true,
      confirmButtonColor: '#6b7280',
      cancelButtonColor: '#6c757d',
      confirmButtonText: 'Yes, Reject Request'
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
