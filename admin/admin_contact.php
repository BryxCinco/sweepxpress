<?php
// admin/admin_contact.php — inbox ng contact messages: basahin, sagutin (may email), burahin.
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/admin_products.php'; // sx_flash, sx_csrf_*

$filters = ['all' => 'All', 'unread' => 'Unread', 'open' => 'Needs reply', 'replied' => 'Replied'];
$filter = isset($filters[$_GET['f'] ?? '']) ? $_GET['f'] : 'all';
$search = trim($_GET['q'] ?? '');
$openId = (int)($_GET['id'] ?? 0);
$back = function (array $extra = []) use ($filter, $search) {
    $q = array_filter(array_merge(['f' => $filter === 'all' ? null : $filter, 'q' => $search], $extra), fn($v) => $v !== null && $v !== '');
    return 'admin_contact.php' . ($q ? '?' . http_build_query($q) : '');
};

// =================================================================================
// ACTIONS (bago ang header; PRG — dati SweetAlert sa gitna ng page at walang CSRF)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sx_csrf_ok()) {
        sx_flash(['error', 'Your session expired. Please try again.']);
        header('Location: ' . $back());
        exit;
    }

    if ($action === 'delete') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        if ($ids) {
            $pdo->prepare("DELETE FROM contact_messages WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")->execute($ids);
            sx_flash(['success', count($ids) . ' message' . (count($ids) === 1 ? '' : 's') . ' deleted.']);
        }
        header('Location: ' . $back());
        exit;
    }

    if ($action === 'unread') {
        $pdo->prepare("UPDATE contact_messages SET is_read = 0 WHERE id = ?")->execute([(int)$_POST['id']]);
        header('Location: ' . $back());
        exit;
    }

    if ($action === 'reply') {
        $id = (int)($_POST['id'] ?? 0);
        $reply = trim($_POST['reply'] ?? '');
        if ($reply === '' || mb_strlen($reply) > 3000) {
            sx_flash(['error', 'Write a reply (up to 3,000 characters).']);
            header('Location: ' . $back(['id' => $id]));
            exit;
        }
        $stmt = $pdo->prepare("SELECT name, email, message FROM contact_messages WHERE id = ?");
        $stmt->execute([$id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            sx_flash(['error', 'Message not found.']);
            header('Location: ' . $back());
            exit;
        }

        // user_seen = 0 para makita ng customer na may bagong sagot sa Contact page
        $pdo->prepare("UPDATE contact_messages SET admin_reply = ?, replied_at = NOW(), is_read = 1, user_seen = 0 WHERE id = ?")->execute([$reply, $id]);

        $sent = false;
        $safeName    = htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($customer['message'], ENT_QUOTES, 'UTF-8'));
        $safeReply   = nl2br(htmlspecialchars($reply, ENT_QUOTES, 'UTF-8'));
        $mail = null;
        try {
            require_once __DIR__ . '/../includes/email_config.php';
            $mail = make_mailer();
            $mail->setFrom(MAIL_USERNAME, 'SweepXpress Admin');
            $mail->addAddress($customer['email'], $customer['name']);
            $mail->isHTML(true);
            $mail->Subject = "Reply from SweepXpress";
            $mail->Body = "<div style='font-family:Arial,sans-serif; line-height:1.6;'>
                              <p>Hello <strong>{$safeName}</strong>,</p>
                              <p><strong>Your message:</strong></p>
                              <p style='border-left:3px solid #1e3a8a; padding-left:10px;'>{$safeMessage}</p>
                              <p><strong>Our reply:</strong></p>
                              <p style='border-left:3px solid #10b981; padding-left:10px;'>{$safeReply}</p>
                              <p>Thank you,<br>SweepXpress Team</p>
                          </div>";
            $mail->send();
            $sent = true;
        } catch (Throwable $e) {
            error_log("Admin reply email failed: " . ($mail ? $mail->ErrorInfo : $e->getMessage()));
        }
        sx_flash($sent
            ? ['success', "Reply sent to {$customer['email']}."]
            : ['warning', 'Reply saved and shown on the customer’s Contact page, but the email could not be sent. Check the mail settings.']);
        header('Location: ' . $back(['id' => $id]));
        exit;
    }
}

// =================================================================================
// LIST + OPEN MESSAGE
// =================================================================================
if ($openId) {
    // Pagbukas = nabasa na (dati: hiwalay na AJAX na tumatanggap ng buong page bilang sagot)
    $pdo->prepare("UPDATE contact_messages SET is_read = 1 WHERE id = ?")->execute([$openId]);
}

$where = [];
$params = [];
$where[] = match ($filter) {
    'unread'  => 'is_read = 0',
    'open'    => 'admin_reply IS NULL',
    'replied' => 'admin_reply IS NOT NULL',
    default   => '1=1',
};
if ($search !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR message LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
$stmt = $pdo->prepare("SELECT id, name, email, message, admin_reply, replied_at, created_at, is_read FROM contact_messages WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 200");
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counts = $pdo->query("SELECT COUNT(*) AS all_, SUM(is_read = 0) AS unread, SUM(admin_reply IS NULL) AS open_, SUM(admin_reply IS NOT NULL) AS replied FROM contact_messages")->fetch(PDO::FETCH_ASSOC);
$countFor = ['all' => $counts['all_'], 'unread' => $counts['unread'], 'open' => $counts['open_'], 'replied' => $counts['replied']];

$open = null;
if ($openId) {
    $o = $pdo->prepare("SELECT m.*, u.id AS user_id FROM contact_messages m LEFT JOIN users u ON u.email = m.email WHERE m.id = ?");
    $o->execute([$openId]);
    $open = $o->fetch(PDO::FETCH_ASSOC) ?: null;
}

$flash = sx_flash();
$csrf = sx_csrf_token();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   MESSAGES (Phase 9g) — tokens + sx-card / sx-chip mula sa assets/style.css
   ============================================================ */
.mx { max-width: 1300px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.mx-layout { display: grid; gap: var(--sx-space-4); align-items: start; }
.mx-list { padding: 0; overflow: hidden; }
.mx-tools { display: grid; gap: var(--sx-space-2); padding: var(--sx-space-3); border-bottom: 1px solid var(--sx-border-light); }
.mx-tools form { display: flex; gap: var(--sx-space-2); }
.mx-tools .sx-input { flex: 1; min-width: 0; }
.mx-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.mx-chips .sx-chip { padding: 4px 10px; font-size: var(--sx-text-xs); }
.mx-chips .sx-chip span { margin-left: 4px; opacity: 0.7; }
.mx-items { max-height: 70vh; overflow-y: auto; }
.mx-item { display: grid; grid-template-columns: auto 1fr; gap: var(--sx-space-3); padding: var(--sx-space-3); border-bottom: 1px solid var(--sx-border-light); }
.mx-item:hover { background: var(--sx-surface-alt); }
.mx-item.active { background: var(--sx-primary-soft); }
.mx-item a { display: block; min-width: 0; color: inherit; text-decoration: none; }
.mx-item .top { display: flex; justify-content: space-between; gap: var(--sx-space-2); }
.mx-item strong { overflow: hidden; color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.mx-item.unread strong::before { content: ""; display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; background: var(--sx-primary); vertical-align: middle; }
.mx-item time { flex-shrink: 0; font-size: var(--sx-text-xs); color: var(--sx-muted); }
.mx-item p { margin: 2px 0 0; overflow: hidden; font-size: var(--sx-text-sm); color: var(--sx-muted); text-overflow: ellipsis; white-space: nowrap; }
.mx-item .tag { font-size: 10px; font-weight: var(--sx-weight-bold); color: var(--sx-accent-dark); }
.mx-bulk { display: flex; justify-content: space-between; align-items: center; padding: var(--sx-space-2) var(--sx-space-3); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.mx-view h2 { margin: 0; font-size: var(--sx-text-lg); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.mx-view .meta { margin: 4px 0 var(--sx-space-4); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.mx-bubble { margin: 0 0 var(--sx-space-4); padding: var(--sx-space-4); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); white-space: pre-wrap; overflow-wrap: anywhere; }
.mx-bubble.reply { border-left: 3px solid var(--sx-accent); background: var(--sx-accent-soft); }
.mx-bubble small { display: block; margin-bottom: 6px; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); color: var(--sx-muted); white-space: normal; }
.mx-actions { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--sx-space-2); margin-top: var(--sx-space-3); }
@media (min-width: 992px) { .mx-layout { grid-template-columns: 380px 1fr; } }
</style>

<div class="mx sx-container">
    <div class="pf-head">
        <div>
            <span class="sx-eyebrow">Support</span>
            <h1 class="sx-heading-lg">Messages</h1>
            <p><?= (int)$counts['unread'] ?> unread · <?= (int)$counts['open_'] ?> waiting for a reply</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>

    <div class="mx-layout">
        <section class="sx-card mx-list" aria-label="Inbox">
            <div class="mx-tools">
                <form method="get" role="search">
                    <?php if ($filter !== 'all'): ?><input type="hidden" name="f" value="<?= h($filter) ?>"><?php endif; ?>
                    <input type="search" name="q" class="sx-input" placeholder="Search name, email or text…" value="<?= h($search) ?>" aria-label="Search messages">
                    <button type="submit" class="sx-btn sx-btn-dark sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>
                <div class="mx-chips">
                    <?php foreach ($filters as $k => $label): ?>
                        <a href="<?= h($k === 'all' ? 'admin_contact.php' . ($search ? '?q=' . urlencode($search) : '') : 'admin_contact.php?' . http_build_query(array_filter(['f' => $k, 'q' => $search]))) ?>"
                           class="sx-chip<?= $filter === $k ? ' active' : '' ?>"><?= $label ?> <span><?= (int)$countFor[$k] ?></span></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="post" id="bulkForm" onsubmit="return confirm('Delete the selected messages?')">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <div class="mx-items">
                    <?php foreach ($messages as $m): ?>
                        <div class="mx-item<?= !$m['is_read'] ? ' unread' : '' ?><?= $open && (int)$open['id'] === (int)$m['id'] ? ' active' : '' ?>">
                            <input type="checkbox" name="ids[]" value="<?= (int)$m['id'] ?>" aria-label="Select message from <?= h($m['name']) ?>" class="mx-check">
                            <a href="<?= h($back(['id' => $m['id']])) ?>">
                                <div class="top"><strong><?= h($m['name']) ?></strong><time><?= date('M j', strtotime($m['created_at'])) ?></time></div>
                                <p><?= h($m['message']) ?></p>
                                <?php if ($m['admin_reply'] !== null): ?><span class="tag"><i class="fa-solid fa-reply"></i> Replied</span><?php endif; ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$messages): ?>
                        <div class="sx-empty"><i class="fa-solid fa-inbox"></i><h3>No messages</h3></div>
                    <?php endif; ?>
                </div>
                <?php if ($messages): ?>
                    <div class="mx-bulk">
                        <label><input type="checkbox" id="checkAll"> Select all</label>
                        <button type="submit" class="sx-btn sx-btn-ghost sx-btn-sm" id="bulkDelete" disabled><i class="fa-solid fa-trash"></i> Delete</button>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        <section class="sx-card mx-view" aria-live="polite">
            <?php if ($open): ?>
                <h2><?= h($open['name']) ?></h2>
                <p class="meta">
                    <a href="mailto:<?= h($open['email']) ?>"><?= h($open['email']) ?></a>
                    <?php if ($open['user_id']): ?> · <a href="user_actions.php?id=<?= (int)$open['user_id'] ?>">View account</a><?php endif; ?>
                    · <?= date('M j, Y g:i A', strtotime($open['created_at'])) ?>
                </p>
                <div class="mx-bubble"><small>Message</small><?= h($open['message']) ?></div>
                <?php if ($open['admin_reply'] !== null): ?>
                    <div class="mx-bubble reply"><small>Your reply · <?= date('M j, Y g:i A', strtotime($open['replied_at'])) ?></small><?= h($open['admin_reply']) ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                    <div class="sx-field" style="margin-bottom: 0">
                        <label class="sx-label" for="mxReply"><?= $open['admin_reply'] !== null ? 'Send another reply' : 'Reply' ?></label>
                        <textarea id="mxReply" name="reply" class="sx-textarea" rows="5" maxlength="3000" required placeholder="Write your reply… The customer gets it by email and on their Contact page."></textarea>
                    </div>
                    <div class="mx-actions">
                        <span></span>
                        <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid fa-paper-plane"></i> Send reply</button>
                    </div>
                </form>
                <form method="post" class="mx-actions" style="margin-top: var(--sx-space-4); padding-top: var(--sx-space-3); border-top: 1px solid var(--sx-border-light)">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
                    <input type="hidden" name="ids[]" value="<?= (int)$open['id'] ?>">
                    <button type="submit" name="action" value="unread" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-regular fa-envelope"></i> Mark unread</button>
                    <button type="submit" name="action" value="delete" class="sx-btn sx-btn-ghost sx-btn-sm" onclick="return confirm('Delete this message?')"><i class="fa-solid fa-trash"></i> Delete</button>
                </form>
            <?php else: ?>
                <div class="sx-empty">
                    <i class="fa-regular fa-envelope-open"></i>
                    <h3>Select a message</h3>
                    <p>Pick a message on the left to read and reply.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checks = document.querySelectorAll('.mx-check');
    const all = document.getElementById('checkAll');
    const del = document.getElementById('bulkDelete');
    const sync = () => { if (del) del.disabled = ![...checks].some((c) => c.checked); };
    checks.forEach((c) => c.addEventListener('change', sync));
    if (all) all.addEventListener('change', () => { checks.forEach((c) => { c.checked = all.checked; }); sync(); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
