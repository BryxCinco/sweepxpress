<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ✅ Mark as read (AJAX)
if (isset($_POST['mark_read'])) {
    $id = intval($_POST['mark_read']);
    $pdo->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?")->execute([$id]);
    exit;
}

// ✅ Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ids'])) {
    $ids = $_POST['delete_ids'];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
          Swal.fire({
            icon: 'success',
            title: 'Deleted!',
            text: 'Selected messages have been deleted.',
            confirmButtonColor: '#ef4444'
          }).then(() => {
            window.location.href = 'admin_contact.php';
          });
        });
        </script>";
    }
}

// ✅ Handle admin reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_message'])) {
    $id = intval($_POST['message_id']);
    $reply = trim($_POST['reply_message']);

    $stmt = $pdo->prepare("UPDATE contact_messages SET admin_reply = ?, replied_at = NOW() WHERE id = ?");
    $stmt->execute([$reply, $id]);

    $stmt2 = $pdo->prepare("SELECT name, email, message FROM contact_messages WHERE id = ?");
    $stmt2->execute([$id]);
    $customer = $stmt2->fetch(PDO::FETCH_ASSOC);

    $sent = false;
    if ($customer) {
        $safeName    = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($customer['message'], ENT_QUOTES, 'UTF-8'));
        $safeReply   = nl2br(htmlspecialchars($reply, ENT_QUOTES, 'UTF-8'));

        $mail = null;
        try {
            // SMTP settings galing sa includes/email_config.php (.env)
            require_once __DIR__ . '/../includes/email_config.php';
            $mail = make_mailer();
            $mail->setFrom(MAIL_USERNAME, 'SweepXpress Admin');
            $mail->addAddress($customer['email'], $customer['name']);

            $mail->isHTML(true);
            $mail->Subject = "Reply from SweepXpress";
            $mail->Body = "<div style='font-family:Arial,sans-serif; line-height:1.6;'>
                              <p>Hello <strong>{$safeName}</strong>,</p>
                              <p><strong>Your message:</strong></p>
                              <p style='border-left:3px solid #007bff; padding-left:10px;'>{$safeMessage}</p>
                              <p><strong>Admin reply:</strong></p>
                              <p style='border-left:3px solid #28a745; padding-left:10px; color:#28a745;'>{$safeReply}</p>
                              <p>Thank you,<br>SweepXpress Team</p>
                          </div>";
            $mail->send();
            $sent = true;
        } catch (Throwable $e) {
            $error = $mail ? $mail->ErrorInfo : $e->getMessage();
            error_log("Admin reply email failed: {$error}");
        }
    }

    $alertIcon  = $sent ? 'success' : 'warning';
    $alertTitle = $sent ? 'Reply Sent!' : 'Reply Saved, Email Not Sent';
    $alertText  = $sent
        ? 'Your reply has been successfully sent to the customer.'
        : 'Your reply was saved, but the email could not be sent. Please check the mail settings and try again.';

    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
      Swal.fire({
        icon: '{$alertIcon}',
        title: '{$alertTitle}',
        text: '{$alertText}',
        confirmButtonColor: '#2563eb'
      }).then(() => {
        window.location.href = 'admin_contact.php';
      });
    });
    </script>";
}

$stmt = $pdo->query("SELECT * FROM contact_messages ORDER BY created_at DESC");
$messages = $stmt->fetchAll();

$unreadCount = count(array_filter($messages, fn($m) => !$m['is_read']));
$repliedCount = count(array_filter($messages, fn($m) => $m['admin_reply']));
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

body {
    background: radial-gradient(circle at top,#e0f2fe 0,#f4f5fb 50%,#f9fafb 100%);
    font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.msg-shell {
    max-width: 1400px;
    margin: 0 auto;
    padding: 32px 24px 48px;
}

/* HEADER */
.msg-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.msg-title-block h1 {
    font-size: 1.9rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin-bottom: 4px;
}

.msg-title-block p {
    font-size: 0.9rem;
    color: #64748b;
}

.msg-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.msg-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    border: none;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}

.msg-btn-delete {
    background: #ffffff;
    color: #ef4444;
    border: 1px solid #fee2e2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.msg-btn-delete:hover {
    background: #ef4444;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239,68,68,0.3);
}

.msg-btn-confirm {
    background: linear-gradient(135deg,#ef4444,#dc2626);
    color: white;
    box-shadow: 0 4px 12px rgba(239,68,68,0.3);
}

.msg-btn-confirm:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(239,68,68,0.4);
}

.msg-btn-cancel {
    background: #ffffff;
    color: #64748b;
    border: 1px solid #e2e8f0;
}

.msg-btn-cancel:hover {
    background: #f8fafc;
}

/* STATS */
.msg-stats {
    display: flex;
    gap: 12px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.msg-stat {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    font-size: 0.85rem;
    font-weight: 600;
}

.stat-unread {
    color: #ef4444;
}

.stat-replied {
    color: #10b981;
}

.stat-total {
    color: #64748b;
}

/* TABLE CARD */
.msg-card {
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 10px 30px rgba(15,23,42,0.08);
    overflow: hidden;
}

.msg-card-header {
    padding: 18px 22px;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(135deg,#f9fafb,#eef2ff);
}

.msg-card-title {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 0;
}

.msg-card-sub {
    font-size: 0.85rem;
    color: #6b7280;
}

/* TABLE */
.msg-table-wrap {
    overflow-x: auto;
}

.msg-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.msg-table thead th {
    padding: 12px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: #9ca3af;
    text-align: left;
}

.msg-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.msg-table tbody tr {
    cursor: pointer;
    transition: all 0.16s ease;
}

.msg-table tbody tr:hover {
    background: linear-gradient(90deg,rgba(37,99,235,0.03) 0%,transparent 100%);
}

.msg-table tbody tr.unread-row {
    background: rgba(254,226,226,0.3);
}

.msg-table tbody tr.unread-row:hover {
    background: rgba(254,226,226,0.5);
}

.cell-num {
    font-weight: 600;
    color: #9ca3af;
}

.cell-name {
    font-weight: 600;
    color: #111827;
}

.cell-email {
    color: #64748b;
}

.cell-message {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #475569;
}

.cell-date {
    color: #9ca3af;
    font-size: 0.85rem;
}

/* STATUS BADGES */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
}

.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
}

.st-unread {
    background: rgba(239,68,68,0.08);
    color: #b91c1c;
}
.st-unread .status-dot { background: #ef4444; }

.st-read {
    background: rgba(245,158,11,0.08);
    color: #b45309;
}
.st-read .status-dot { background: #f59e0b; }

.st-replied {
    background: rgba(16,185,129,0.08);
    color: #047857;
}
.st-replied .status-dot { background: #10b981; }

/* MODAL */
.modal-content {
    border-radius: 16px;
    border: none;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}

.modal-header {
    background: linear-gradient(135deg,#2563eb,#1d4ed8);
    border-radius: 16px 16px 0 0;
    padding: 20px 24px;
}

.modal-title {
    font-weight: 700;
    font-size: 1.1rem;
}

.modal-body {
    padding: 24px;
}

.modal-body p {
    margin-bottom: 12px;
}

.modal-body strong {
    font-weight: 700;
    color: #111827;
}

.reply-textarea {
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #d4dbe8;
    font-size: 0.9rem;
    font-family: inherit;
    resize: vertical;
    transition: all 0.2s;
}

.reply-textarea:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.reply-btn {
    padding: 10px 24px;
    background: linear-gradient(135deg,#10b981,#059669);
    color: white;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(16,185,129,0.3);
}

.reply-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(16,185,129,0.4);
}

.select-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.empty-state {
    padding: 60px 24px;
    text-align: center;
}

.empty-icon {
    font-size: 3rem;
    color: #e2e8f0;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 1.2rem;
    font-weight: 700;
    color: #64748b;
    margin-bottom: 8px;
}

.empty-state p {
    color: #9ca3af;
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .msg-shell {
        padding: 24px 16px;
    }
    
    .msg-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .msg-actions {
        width: 100%;
    }
}
</style>

<div class="msg-shell">
    <!-- HEADER -->
    <div class="msg-header">
        <div class="msg-title-block">
            <h1>Contact Messages</h1>
            <p>Review, reply, and manage customer inquiries.</p>
        </div>
        <div class="msg-actions">
            <div id="deleteControls" style="display:none;">
                <button type="button" id="cancelDelete" class="msg-btn msg-btn-cancel">
                    <i class="fas fa-times"></i>
                    Cancel
                </button>
                <button type="button" id="confirmDelete" class="msg-btn msg-btn-confirm">
                    <i class="fas fa-trash-alt"></i>
                    Confirm Delete
                </button>
            </div>
            <button id="toggleDelete" class="msg-btn msg-btn-delete">
                <i class="fas fa-trash"></i>
                Delete Messages
            </button>
        </div>
    </div>

    <!-- STATS -->
    <div class="msg-stats">
        <div class="msg-stat stat-total">
            <i class="fas fa-envelope"></i>
            <?php echo count($messages); ?> total
        </div>
        <div class="msg-stat stat-unread">
            <i class="fas fa-envelope-open"></i>
            <?php echo $unreadCount; ?> unread
        </div>
        <div class="msg-stat stat-replied">
            <i class="fas fa-reply"></i>
            <?php echo $repliedCount; ?> replied
        </div>
    </div>

    <!-- TABLE -->
    <div class="msg-card">
        <div class="msg-card-header">
            <div class="msg-card-title">All Messages</div>
            <div class="msg-card-sub">Click any row to view details and send replies.</div>
        </div>
        <div class="msg-table-wrap">
            <form id="deleteForm" method="POST">
                <table class="msg-table">
                    <thead>
                        <tr>
                            <th style="width:40px;display:none;" class="select-col">
                                <input type="checkbox" id="selectAll" class="select-checkbox">
                            </th>
                            <th style="width:5%;">#</th>
                            <th style="width:15%;">Name</th>
                            <th style="width:20%;">Email</th>
                            <th style="width:35%;">Message</th>
                            <th style="width:15%;">Date</th>
                            <th style="width:10%;text-align:center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($messages): ?>
                            <?php foreach ($messages as $i => $msg): ?>
                                <tr class="message-row <?php echo !$msg['is_read'] ? 'unread-row' : ''; ?>"
                                    data-id="<?php echo $msg['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($msg['name']); ?>"
                                    data-email="<?php echo htmlspecialchars($msg['email']); ?>"
                                    data-message="<?php echo htmlspecialchars($msg['message']); ?>"
                                    data-reply="<?php echo htmlspecialchars($msg['admin_reply']); ?>"
                                    data-read="<?php echo $msg['is_read']; ?>">
                                    <td style="display:none;" class="select-col">
                                        <input type="checkbox" name="delete_ids[]" value="<?php echo $msg['id']; ?>" class="select-checkbox">
                                    </td>
                                    <td class="cell-num"><?php echo $i + 1; ?></td>
                                    <td class="cell-name"><?php echo htmlspecialchars($msg['name']); ?></td>
                                    <td class="cell-email"><?php echo htmlspecialchars($msg['email']); ?></td>
                                    <td class="cell-message"><?php echo htmlspecialchars($msg['message']); ?></td>
                                    <td class="cell-date"><?php echo date("M d, Y", strtotime($msg['created_at'])); ?></td>
                                    <td style="text-align:center;">
                                        <?php if ($msg['admin_reply']): ?>
                                            <span class="status-badge st-replied">
                                                <span class="status-dot"></span>
                                                Replied
                                            </span>
                                        <?php elseif (!$msg['is_read']): ?>
                                            <span class="status-badge st-unread">
                                                <span class="status-dot"></span>
                                                Unread
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge st-read">
                                                <span class="status-dot"></span>
                                                Read
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <div class="empty-icon">
                                            <i class="fas fa-inbox"></i>
                                        </div>
                                        <h3>No messages yet</h3>
                                        <p>Customer inquiries will appear here once submitted.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
</div>

<!-- MODAL -->
<div class="modal fade" id="messageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Message from <span id="modalName"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Email:</strong> <span id="modalEmail"></span></p>
                <p><strong>Message:</strong></p>
                <p id="modalMessage" style="white-space:pre-wrap;color:#475569;"></p>
                <hr>
                <form method="POST">
                    <input type="hidden" name="message_id" id="modalMessageId">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Admin Reply:</label>
                        <textarea name="reply_message" id="modalReply" class="reply-textarea w-100" rows="4" placeholder="Type your reply here..."></textarea>
                    </div>
                    <button type="submit" class="reply-btn">
                        <i class="fas fa-paper-plane"></i>
                        Send Reply
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = new bootstrap.Modal(document.getElementById('messageModal'));
    const modalName = document.getElementById('modalName');
    const modalEmail = document.getElementById('modalEmail');
    const modalMessage = document.getElementById('modalMessage');
    const modalReply = document.getElementById('modalReply');
    const modalId = document.getElementById('modalMessageId');

    const toggleDelete = document.getElementById('toggleDelete');
    const deleteControls = document.getElementById('deleteControls');
    const cancelDelete = document.getElementById('cancelDelete');
    const confirmDelete = document.getElementById('confirmDelete');
    const selectCols = document.querySelectorAll('.select-col');
    const checkboxes = document.querySelectorAll('input[name="delete_ids[]"]');
    const selectAll = document.getElementById('selectAll');
    const form = document.getElementById('deleteForm');

    let deleteMode = false;

    toggleDelete.addEventListener('click', () => {
        deleteMode = true;
        selectCols.forEach(c => c.style.display = 'table-cell');
        deleteControls.style.display = 'flex';
        toggleDelete.style.display = 'none';
    });

    cancelDelete.addEventListener('click', () => {
        deleteMode = false;
        selectCols.forEach(c => c.style.display = 'none');
        deleteControls.style.display = 'none';
        toggleDelete.style.display = 'block';
        checkboxes.forEach(ch => ch.checked = false);
        selectAll.checked = false;
    });

    confirmDelete.addEventListener('click', () => {
        const selected = Array.from(checkboxes).filter(ch => ch.checked);
        if (selected.length === 0) {
            Swal.fire({ icon: 'warning', title: 'No Messages Selected', text: 'Please select at least one message to delete.' });
            return;
        }
        Swal.fire({
            title: 'Delete Selected Messages?',
            text: "This action cannot be undone!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete them!'
        }).then(result => {
            if (result.isConfirmed) form.submit();
        });
    });

    checkboxes.forEach(ch => ch.addEventListener('click', e => e.stopPropagation()));
    if (selectAll) {
        selectAll.addEventListener('click', e => {
            e.stopPropagation();
            checkboxes.forEach(ch => ch.checked = selectAll.checked);
        });
    }

    document.querySelectorAll('.message-row').forEach(row => {
        row.addEventListener('click', e => {
            if (deleteMode) return;
            modalName.textContent = row.dataset.name;
            modalEmail.textContent = row.dataset.email;
            modalMessage.textContent = row.dataset.message;
            modalReply.value = row.dataset.reply || '';
            modalId.value = row.dataset.id;
            modal.show();

            if (row.dataset.read === "0") {
                fetch("", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "mark_read=" + row.dataset.id
                }).then(() => {
                    row.dataset.read = "1";
                    row.classList.remove('unread-row');
                    const badge = row.querySelector('.status-badge');
                    badge.className = 'status-badge st-read';
                    badge.innerHTML = '<span class="status-dot"></span>Read';
                });
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
