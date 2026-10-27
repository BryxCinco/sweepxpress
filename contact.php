<?php
require_once __DIR__ . '/config.php'; // naglo-load na rin ng vendor/autoload.php (PHPMailer)

// Lahat ng pagproseso ay BAGO ang header.php: para malinis na JSON ang AJAX delete
// (dati may HTML ng header sa unahan) at para makapag-redirect pagkatapos magpadala.

$contact_error = '';
$my_messages = [];
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
        $contact_error = 'You must be logged in to send a message.';
    } else {
        $message = trim($_POST['message']);
        if (spam_guard_failed('contact')) {
            $contact_error = 'Your message could not be sent. Please try again.';
        } elseif (rate_limited($pdo, 'contact', 5, 3600, 'user:' . $_SESSION['user']['id'])) {
            // Max 5 message kada user kada oras (bawat isa ay nag-e-email sa admin)
            $contact_error = "You've sent several messages recently. Please wait a while before sending another.";
        } elseif (mb_strlen($message) > 2000) {
            $contact_error = 'Your message is too long (max 2000 characters).';
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

            // Post/Redirect/Get: hindi na mauulit ang pagpapadala kapag ni-refresh
            header('Location: /sweepxpress/contact.php?sent=1#messages');
            exit;
        } else {
            $contact_error = 'Please write a message before sending.';
        }
    }
}

// Mga sariling mensahe (may reply man o wala) — makikita na sa page mismo
if (is_logged_in()) {
    $stmt = $pdo->prepare("SELECT id, message, admin_reply, replied_at, created_at, user_seen
                           FROM contact_messages
                           WHERE email = ?
                           ORDER BY created_at DESC
                           LIMIT 20");
    $stmt->execute([$user_email]);
    $my_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($my_messages as $m) {
        if ($m['admin_reply'] !== null && !(int)$m['user_seen']) $unread_count++;
    }

    // Nakita na ang mga reply (dati: kapag binuksan ang "View Messages")
    if ($unread_count > 0) {
        $pdo->prepare("UPDATE contact_messages SET user_seen = 1 WHERE email = ? AND admin_reply IS NOT NULL")->execute([$user_email]);
    }
}

$sent = isset($_GET['sent']);

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ============================================================
   CONTACT (Phase 9b) — tokens mula sa assets/style.css
   ============================================================ */
.ct { padding-block: var(--sx-space-5) var(--sx-space-8); }
.ct-head { max-width: 640px; margin-bottom: var(--sx-space-6); }
.ct-head p { margin: var(--sx-space-3) 0 0; color: var(--sx-muted); }
.ct-layout { display: grid; gap: var(--sx-space-5); align-items: start; }

/* Info panel */
.ct-info {
    position: relative;
    display: grid;
    gap: var(--sx-space-5);
    padding: var(--sx-space-6);
    border-radius: var(--sx-radius-xl);
    background: var(--sx-gradient-brand);
    color: #fff;
    overflow: hidden;
}
.ct-info::after {
    content: "";
    position: absolute;
    right: -80px;
    bottom: -80px;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(110, 231, 183, 0.35) 0%, transparent 70%);
}
.ct-info h2 { margin: 0; font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); }
.ct-info > p { margin: 0; color: rgba(255, 255, 255, 0.8); font-size: var(--sx-text-sm); line-height: 1.6; }
.ct-info ul { position: relative; z-index: 1; display: grid; gap: var(--sx-space-3); margin: 0; padding: 0; list-style: none; }
.ct-info li { display: flex; align-items: center; gap: var(--sx-space-3); font-size: var(--sx-text-sm); }
.ct-info li i {
    display: inline-grid;
    place-items: center;
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border-radius: var(--sx-radius-md);
    background: rgba(255, 255, 255, 0.14);
}
.ct-info a { color: #fff; text-decoration: underline; text-underline-offset: 3px; }
.ct-info small { display: block; color: rgba(255, 255, 255, 0.7); font-size: var(--sx-text-xs); }

/* Alerts */
.ct-alert {
    display: flex;
    align-items: flex-start;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-4);
    padding: var(--sx-space-3) var(--sx-space-4);
    border-radius: var(--sx-radius-md);
    font-size: var(--sx-text-sm);
}
.ct-alert i { margin-top: 2px; }
.ct-alert.success { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.ct-alert.error { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }
.ct-alert.info { background: var(--sx-primary-soft); color: var(--sx-primary); }

/* Form */
.ct-form-title { margin: 0 0 var(--sx-space-4); font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ct-row { display: grid; gap: 0 var(--sx-space-4); }
.ct-readonly { background: var(--sx-surface-alt); color: var(--sx-muted); }
.ct-count { align-self: flex-end; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ct-guest { text-align: center; padding: var(--sx-space-7) var(--sx-space-4); }
.ct-guest i { font-size: 2.25rem; color: var(--sx-primary); margin-bottom: var(--sx-space-3); }
.ct-guest h2 { margin: 0 0 var(--sx-space-2); font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ct-guest p { margin: 0 auto var(--sx-space-5); max-width: 360px; color: var(--sx-muted); }
.ct-guest .ab-ctas { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--sx-space-3); }

/* Messages */
.ct-messages { padding-top: var(--sx-space-8); scroll-margin-top: 90px; }
.ct-messages-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.ct-thread { display: grid; gap: var(--sx-space-4); }
.ct-msg { display: grid; gap: var(--sx-space-3); }
.ct-msg.is-new { border-color: var(--sx-accent); box-shadow: 0 0 0 3px var(--sx-accent-soft); }
.ct-msg-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--sx-space-2); }
.ct-msg-meta { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); font-size: var(--sx-text-xs); color: var(--sx-muted); }
.ct-new { padding: 2px 8px; border-radius: var(--sx-radius-pill); background: var(--sx-accent); color: var(--sx-on-accent); font-size: 0.65rem; font-weight: var(--sx-weight-extrabold); text-transform: uppercase; letter-spacing: 0.05em; }
.ct-bubble { padding: var(--sx-space-3) var(--sx-space-4); border-radius: var(--sx-radius-md); font-size: var(--sx-text-sm); line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
.ct-bubble.mine { background: var(--sx-surface-alt); color: var(--sx-text); }
.ct-bubble.reply { background: var(--sx-primary-soft); color: var(--sx-text); border-left: 3px solid var(--sx-primary); }
.ct-bubble-label { display: block; margin-bottom: 4px; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); color: var(--sx-muted); white-space: normal; }
.ct-delete { color: var(--sx-danger); }
.ct-delete:hover { background: var(--sx-status-cancelled-bg); color: var(--sx-danger); }

@media (min-width: 768px) {
    .ct-row { grid-template-columns: 1fr 1fr; }
}
@media (min-width: 992px) {
    .ct-layout { grid-template-columns: 0.85fr 1.15fr; gap: var(--sx-space-6); }
    .ct-info { position: sticky; top: 96px; }
}
</style>

<div class="ct sx-container">
    <div class="ct-head">
        <span class="sx-eyebrow">Contact</span>
        <h1 class="sx-heading-xl">How can we help?</h1>
        <p>Questions about an order, a product, or bulk purchasing? Send us a message and our team will get back to you.</p>
    </div>

    <div class="ct-layout">
        <!-- Info -->
        <aside class="ct-info">
            <h2>Get in touch</h2>
            <p>Replies are sent to your email and also appear under “Your messages” on this page.</p>
            <ul>
                <li><i class="fa-solid fa-envelope"></i><div><small>Email</small><a href="mailto:sweepxpress@gmail.com">sweepxpress@gmail.com</a></div></li>
                <li><i class="fa-solid fa-phone"></i><div><small>Phone</small>(63) 761-77232</div></li>
                <li><i class="fa-solid fa-building"></i><div><small>Business orders</small>Use a business account to send purchase orders.</div></li>
            </ul>
        </aside>

        <!-- Form -->
        <div class="sx-card">
            <?php if ($sent && is_logged_in()): ?>
                <div class="ct-alert success" role="status"><i class="fa-solid fa-circle-check"></i><div><strong>Message sent!</strong> We'll reply to <?= h($user_email ?? '') ?> as soon as possible.</div></div>
            <?php endif; ?>
            <?php if ($contact_error !== ''): ?>
                <div class="ct-alert error" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div><?= h($contact_error) ?></div></div>
            <?php endif; ?>

            <?php if (is_logged_in()): ?>
                <h2 class="ct-form-title">Send a message</h2>
                <form method="post" action="/sweepxpress/contact.php" id="contactForm">
                    <?= spam_guard_field('contact') ?>
                    <div class="ct-row">
                        <div class="sx-field">
                            <label class="sx-label" for="ct-name">Name</label>
                            <input type="text" id="ct-name" class="sx-input ct-readonly" value="<?= h($user_name) ?>" disabled>
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="ct-email">Email address</label>
                            <input type="email" id="ct-email" class="sx-input ct-readonly" value="<?= h($user_email) ?>" disabled>
                        </div>
                    </div>
                    <div class="sx-field">
                        <label class="sx-label" for="ct-message">Your message</label>
                        <textarea id="ct-message" class="sx-textarea" name="message" rows="6" placeholder="Describe your question or concern..." maxlength="2000" required></textarea>
                        <span class="ct-count" id="ctCount" aria-live="polite">0 / 2000</span>
                    </div>
                    <button type="submit" class="sx-btn sx-btn-primary sx-btn-lg"><i class="fa-solid fa-paper-plane"></i> Send message</button>
                </form>
            <?php else: ?>
                <div class="ct-guest">
                    <i class="fa-solid fa-user-lock"></i>
                    <h2>Log in to send a message</h2>
                    <p>We link each message to your account so you can see our replies here.</p>
                    <div class="ab-ctas">
                        <a href="/sweepxpress/login.php" class="sx-btn sx-btn-primary"><i class="fa-solid fa-right-to-bracket"></i> Log in</a>
                        <a href="/sweepxpress/register.php" class="sx-btn sx-btn-outline">Create an account</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_logged_in()): ?>
    <section class="ct-messages" id="messages">
        <div class="ct-messages-head">
            <h2 class="sx-heading-md">Your messages</h2>
            <?php if ($unread_count > 0): ?>
                <span class="ct-new"><?= $unread_count ?> new <?= $unread_count === 1 ? 'reply' : 'replies' ?></span>
            <?php endif; ?>
        </div>

        <div class="ct-thread" id="messageList">
            <?php if (!$my_messages): ?>
                <div class="sx-card sx-empty">
                    <i class="fa-regular fa-comments"></i>
                    <h3>No messages yet</h3>
                    <p style="margin: 0">Messages you send will appear here, along with our replies.</p>
                </div>
            <?php endif; ?>
            <?php foreach ($my_messages as $m):
                $hasReply = $m['admin_reply'] !== null;
                $isNew = $hasReply && !(int)$m['user_seen'];
            ?>
                <article class="sx-card ct-msg <?= $isNew ? 'is-new' : '' ?>" data-msg-id="<?= (int)$m['id'] ?>">
                    <div class="ct-msg-top">
                        <div class="ct-msg-meta">
                            <?php if ($hasReply): ?>
                                <span class="sx-pill sx-pill-completed">Replied</span>
                            <?php else: ?>
                                <span class="sx-pill sx-pill-pending">Awaiting reply</span>
                            <?php endif; ?>
                            <?php if ($isNew): ?><span class="ct-new">New</span><?php endif; ?>
                            <span>Sent <?= date('M j, Y • g:i A', strtotime($m['created_at'])) ?></span>
                        </div>
                        <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm ct-delete" onclick="ctDelete(<?= (int)$m['id'] ?>, this)">
                            <i class="fa-solid fa-trash-can"></i> Delete
                        </button>
                    </div>
                    <div class="ct-bubble mine"><span class="ct-bubble-label">You</span><?= h($m['message']) ?></div>
                    <?php if ($hasReply): ?>
                        <div class="ct-bubble reply"><span class="ct-bubble-label">SweepXpress Support · <?= date('M j, Y • g:i A', strtotime($m['replied_at'])) ?></span><?= h($m['admin_reply']) ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
// Bilang ng characters sa message
(() => {
    const box = document.getElementById('ct-message');
    const count = document.getElementById('ctCount');
    if (!box || !count) return;
    const update = () => { count.textContent = `${box.value.length} / 2000`; };
    box.addEventListener('input', update);
    update();
})();

function ctDelete(id, btn) {
    if (!confirm('Delete this message? This cannot be undone.')) return;
    btn.disabled = true;

    const formData = new FormData();
    formData.append('ajax_delete', '1');
    formData.append('id', id);

    fetch('/sweepxpress/contact.php', { method: 'POST', credentials: 'same-origin', body: formData })
        .then(res => res.json())
        .then(data => {
            if (!data.success) throw new Error(data.message || 'Delete failed');
            const card = document.querySelector(`.ct-msg[data-msg-id="${id}"]`);
            if (card) card.remove();
            if (!document.querySelector('.ct-msg')) {
                document.getElementById('messageList').innerHTML =
                    '<div class="sx-card sx-empty"><i class="fa-regular fa-comments"></i><h3>No messages yet</h3>' +
                    '<p style="margin: 0">Messages you send will appear here, along with our replies.</p></div>';
            }
            sxToast('Message deleted', 'success');
        })
        .catch(err => {
            console.error(err);
            btn.disabled = false;
            sxToast('Failed to delete message', 'error');
        });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
