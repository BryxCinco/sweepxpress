<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$show_modal = false; 
$show_reply_modal = false;
$admin_replies = [];
$has_unread_replies = false;
$unread_count = 0;

if (!is_logged_in()) {
    $user_email = null;
    $user_name = null;
} else {
    $user_email = $_SESSION['user']['email'];
    $user_name = $_SESSION['user']['name'] ?? '';
}

// user_seen column: tingnan ang database/migrations/2026-10-27_4c_contact_user_seen.sql

// Handle AJAX delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    $id = (int)$_POST['id'];
    
    if (is_logged_in()) {
        $stmt = $pdo->prepare("DELETE FROM contact_messages WHERE id = ? AND email = ?");
        $success = $stmt->execute([$id, $user_email]);
        echo json_encode(['success' => $success]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Not authorized']);
    }
    exit;
}

// Handle new message send
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && !isset($_POST['ajax_delete'])) {
    if (!is_logged_in()) {
        echo "<div class='alert alert-danger mt-4'>⚠️ You must be logged in to send a message.</div>";
    } else {
        $message = trim($_POST['message']);
        if (spam_guard_failed('contact')) {
            echo "<div class='alert alert-danger mt-4'>⚠️ Your message could not be sent. Please try again.</div>";
        } elseif (rate_limited($pdo, 'contact', 5, 3600, 'user:' . $_SESSION['user']['id'])) {
            // Max 5 message kada user kada oras (bawat isa ay nag-e-email sa admin)
            echo "<div class='alert alert-danger mt-4'>⚠️ You've sent several messages recently. Please wait a while before sending another.</div>";
        } elseif (mb_strlen($message) > 2000) {
            echo "<div class='alert alert-danger mt-4'>⚠️ Your message is too long (max 2000 characters).</div>";
        } elseif (!empty($message)) {
            rate_limit_record($pdo, 'contact', 'user:' . $_SESSION['user']['id']);
            $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, message, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$user_name, $user_email, $message]);

            $mail = null;
            try {
                // SMTP settings + sender galing sa includes/email_config.php (.env)
                require_once __DIR__ . '/includes/email_config.php';
                $mail = make_mailer();
                $mail->addAddress(MAIL_USERNAME, 'Admin'); // admin inbox = mail account

                $mail->isHTML(true);
                $mail->Subject = 'New Contact Message from ' . $user_name;
                $mail->Body    = "<p><strong>Name:</strong> " . h($user_name) . "</p>
                                  <p><strong>Email:</strong> " . h($user_email) . "</p>
                                  <p><strong>Message:</strong><br>" . nl2br(htmlspecialchars($message)) . "</p>";

                $mail->send();
            } catch (Throwable $e) {
                $error = $mail ? $mail->ErrorInfo : $e->getMessage();
                error_log("Contact form email failed: {$error}");
            }

            $show_modal = true;
        }
    }
}

// Handle view replies
if (isset($_GET['view_replies']) && is_logged_in()) {
    $stmt = $pdo->prepare("SELECT id, message, admin_reply, replied_at, created_at, user_seen
                           FROM contact_messages 
                           WHERE email = ? AND admin_reply IS NOT NULL 
                           ORDER BY replied_at DESC");
    $stmt->execute([$user_email]);
    $admin_replies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->prepare("UPDATE contact_messages SET user_seen = 1 WHERE email = ? AND admin_reply IS NOT NULL")->execute([$user_email]);

    $show_reply_modal = true;
}

// Check unread replies
if (is_logged_in()) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contact_messages WHERE email = ? AND admin_reply IS NOT NULL AND user_seen = 0");
    $stmt->execute([$user_email]);
    $unread_count = (int) $stmt->fetchColumn();
    $has_unread_replies = $unread_count > 0;
}
?>

<style>
    /* Professional Contact Page Design */
    .contact-page {
        background: #f8f9fa;
        min-height: calc(100vh - 80px);
        padding: 3rem 0;
    }

    .contact-container {
        max-width: 900px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* Page Header */
    .page-header {
        text-align: center;
        margin-bottom: 2.5rem;
    }

    .page-header h1 {
        font-size: 2.25rem;
        font-weight: 700;
        color: #212529;
        margin-bottom: 0.75rem;
    }

    .page-header p {
        font-size: 1.0625rem;
        color: #6c757d;
        max-width: 600px;
        margin: 0 auto;
    }

    /* Notification Banner */
    .notification-banner {
        background: linear-gradient(135deg, #e7f3ff 0%, #f0f7ff 100%);
        border: 1px solid #b6d7f5;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .notification-icon {
        width: 40px;
        height: 40px;
        background: #3366ff;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .notification-text {
        flex: 1;
        color: #004085;
        font-weight: 500;
    }

    /* Main Card */
    .contact-card {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }

    .card-body {
        padding: 2.5rem;
    }

    /* Form Styling */
    .form-field {
        margin-bottom: 1.75rem;
    }

    .form-field:last-of-type {
        margin-bottom: 2rem;
    }

    .field-label {
        display: block;
        font-size: 0.9375rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 0.625rem;
    }

    .field-input {
        width: 100%;
        padding: 0.875rem 1rem;
        font-size: 1rem;
        border: 1.5px solid #dee2e6;
        border-radius: 10px;
        background: #f8f9fa;
        transition: all 0.2s ease;
    }

    .field-input:focus {
        outline: none;
        border-color: #3366ff;
        background: #ffffff;
        box-shadow: 0 0 0 4px rgba(51, 102, 255, 0.08);
    }

    .field-input:disabled {
        background: #e9ecef;
        color: #6c757d;
        cursor: not-allowed;
    }

    .field-textarea {
        width: 100%;
        padding: 0.875rem 1rem;
        font-size: 1rem;
        border: 1.5px solid #dee2e6;
        border-radius: 10px;
        background: #ffffff;
        min-height: 140px;
        resize: vertical;
        font-family: inherit;
        transition: all 0.2s ease;
    }

    .field-textarea:focus {
        outline: none;
        border-color: #3366ff;
        box-shadow: 0 0 0 4px rgba(51, 102, 255, 0.08);
    }

    /* Action Area */
    .form-actions {
        display: flex;
        gap: 1rem;
        align-items: center;
    }

    .btn-submit {
        flex: 1;
        padding: 1rem 2rem;
        background: #3366ff;
        color: #ffffff;
        border: none;
        border-radius: 10px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-submit:hover {
        background: #2952cc;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(51, 102, 255, 0.3);
    }

    .btn-submit:active {
        transform: translateY(0);
    }

    .btn-view-messages {
        padding: 1rem 1.5rem;
        background: #ffffff;
        color: #495057;
        border: 1.5px solid #dee2e6;
        border-radius: 10px;
        font-size: 1rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
        white-space: nowrap;
    }

    .btn-view-messages:hover {
        border-color: #3366ff;
        color: #3366ff;
        background: #f8f9ff;
    }

    .unread-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #dc3545;
        color: #ffffff;
        border-radius: 12px;
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(220, 53, 69, 0.4);
    }

    /* Modal Improvements */
    .modal-content {
        border: none;
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
    }

    .modal-header {
        padding: 1.75rem 2rem;
        border-bottom: 1px solid #e9ecef;
    }

    .modal-title {
        font-size: 1.375rem;
        font-weight: 700;
        color: #212529;
    }

    .modal-body {
        padding: 2rem;
    }

    .modal-footer {
        padding: 1.5rem 2rem;
        border-top: 1px solid #e9ecef;
    }

    /* Success Modal */
    .success-modal .modal-header {
        background: #d1e7dd;
        border-bottom: 1px solid #badbcc;
    }

    .success-modal .modal-title {
        color: #0f5132;
    }

    .success-check {
        width: 72px;
        height: 72px;
        background: #198754;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        font-size: 2.5rem;
        color: #ffffff;
        animation: scaleIn 0.3s ease;
    }

    @keyframes scaleIn {
        from {
            transform: scale(0);
        }
        to {
            transform: scale(1);
        }
    }

    .success-content {
        text-align: center;
    }

    .success-content h3 {
        font-size: 1.5rem;
        font-weight: 700;
        color: #212529;
        margin-bottom: 1rem;
    }

    .success-content p {
        font-size: 1rem;
        color: #6c757d;
        line-height: 1.6;
    }

    .btn-success-close {
        padding: 0.75rem 2rem;
        background: #198754;
        color: #ffffff;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-success-close:hover {
        background: #157347;
    }

    /* Reply Cards */
    .reply-card {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 12px;
        padding: 1.5rem;
        margin-bottom: 1.25rem;
        transition: all 0.2s ease;
    }

    .reply-card:hover {
        background: #ffffff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .message-block {
        margin-bottom: 1rem;
    }

    .message-label {
        font-size: 0.8125rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c757d;
        margin-bottom: 0.5rem;
    }

    .message-content {
        font-size: 0.9375rem;
        color: #212529;
        line-height: 1.6;
        padding: 0.75rem;
        background: #ffffff;
        border-left: 3px solid #dee2e6;
        border-radius: 6px;
    }

    .admin-reply .message-content {
        border-left-color: #198754;
        background: #f0f9f4;
    }

    .reply-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid #dee2e6;
    }

    .reply-timestamp {
        font-size: 0.875rem;
        color: #6c757d;
    }

    .btn-delete {
        padding: 0.5rem 1rem;
        background: #fff5f5;
        color: #dc3545;
        border: 1px solid #f1aeb5;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-delete:hover {
        background: #f8d7da;
        border-color: #ea868f;
    }

    .empty-messages {
        text-align: center;
        padding: 3rem 2rem;
        color: #adb5bd;
    }

    .empty-icon {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    .empty-text {
        font-size: 1.125rem;
        color: #6c757d;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .contact-page {
            padding: 2rem 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.75rem;
        }

        .form-actions {
            flex-direction: column;
        }

        .btn-view-messages {
            width: 100%;
            text-align: center;
        }
    }
</style>

<div class="contact-page">
    <div class="contact-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>Contact Support</h1>
            <p>Need help? Have questions? We're here to assist you. Send us a message below.</p>
        </div>

        <!-- Notification Banner -->
        <?php if (is_logged_in() && $has_unread_replies): ?>
        <div class="notification-banner">
            <div class="notification-icon">🔔</div>
            <div class="notification-text">
                You have <?= $unread_count ?> new <?= $unread_count === 1 ? 'message' : 'messages' ?> from our support team!
            </div>
        </div>
        <?php endif; ?>

        <!-- Contact Form Card -->
        <div class="contact-card">
            <div class="card-body">
                <form method="post" action="" id="contactForm">
                    <?= spam_guard_field('contact') ?>
                    <div class="form-field">
                        <label class="field-label">Name</label>
                        <input type="text" class="field-input" value="<?= htmlspecialchars($user_name ?? 'Guest') ?>" disabled />
                    </div>

                    <div class="form-field">
                        <label class="field-label">Email Address</label>
                        <input type="email" class="field-input" value="<?= htmlspecialchars($user_email ?? '') ?>" disabled />
                    </div>

                    <div class="form-field">
                        <label class="field-label">Your Message</label>
                        <textarea class="field-textarea" name="message" placeholder="Describe your question or concern..." maxlength="2000" required></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">Send Message</button>
                        <?php if (is_logged_in()): ?>
                        <a href="?view_replies=1" class="btn-view-messages">
                            View Messages
                            <?php if ($has_unread_replies): ?>
                            <span class="unread-badge"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade success-modal" id="successModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Message Sent Successfully</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="success-content">
                    <div class="success-check">✓</div>
                    <h3>Thank You!</h3>
                    <p>Your message has been sent. We'll respond to <strong><?= htmlspecialchars($user_email ?? '') ?></strong> as soon as possible.</p>
                </div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn-success-close" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Replies Modal -->
<div class="modal fade" id="repliesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Your Messages</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="repliesContainer">
                <?php if (!empty($admin_replies)): ?>
                    <?php foreach ($admin_replies as $reply): ?>
                    <div class="reply-card" data-reply-id="<?= (int)$reply['id'] ?>">
                        <div class="message-block">
                            <div class="message-label">Your Message</div>
                            <div class="message-content"><?= htmlspecialchars($reply['message']) ?></div>
                        </div>
                        <div class="message-block admin-reply">
                            <div class="message-label">Support Reply</div>
                            <div class="message-content"><?= htmlspecialchars($reply['admin_reply']) ?></div>
                        </div>
                        <div class="reply-footer">
                            <span class="reply-timestamp"><?= date('M j, Y • g:i A', strtotime($reply['replied_at'])) ?></span>
                            <button class="btn-delete" onclick="confirmDelete(<?= (int)$reply['id'] ?>)">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-messages">
                        <div class="empty-icon">💬</div>
                        <p class="empty-text">No messages yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function confirmDelete(id) {
    Swal.fire({
        title: 'Delete Message?',
        text: "This action cannot be undone",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('ajax_delete', '1');
            formData.append('id', id);

            fetch(location.pathname, { method: 'POST', credentials: 'same-origin', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const el = document.querySelector('.reply-card[data-reply-id="'+id+'"]');
                    if (el) el.remove();
                    
                    if (!document.querySelector('.reply-card')) {
                        document.getElementById('repliesContainer').innerHTML = '<div class="empty-messages"><div class="empty-icon">💬</div><p class="empty-text">No messages yet</p></div>';
                    }
                    
                    Swal.fire('Deleted!', 'Message deleted successfully.', 'success');
                } else {
                    Swal.fire('Error', 'Failed to delete message.', 'error');
                }
            });
        }
    });
}

<?php if ($show_modal && !$show_reply_modal): ?>
document.addEventListener('DOMContentLoaded', () => {
    const modal = new bootstrap.Modal(document.getElementById('successModal'));
    modal.show();
    document.getElementById('contactForm').reset();
});
<?php elseif ($show_reply_modal): ?>
document.addEventListener('DOMContentLoaded', () => {
    const modal = new bootstrap.Modal(document.getElementById('repliesModal'));
    modal.show();
});
<?php endif; ?>
</script>
