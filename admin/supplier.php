<?php
// admin/supplier.php — isang supplier: details, mga product, purchase orders at performance (Phase 7b).
// Walang ?id= → bagong supplier. Lahat ng aksyon ay POST + CSRF (PRG).
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require_once __DIR__ . '/../includes/admin_products.php';   // sx_flash, sx_csrf_*
require_once __DIR__ . '/../includes/supplier_ops.php';
require_once __DIR__ . '/../includes/purchasing_ops.php';   // sx_po_status_pill

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$supplier = null;
if ($id) {
    $supplier = sx_supplier_rows($pdo, 's.id = ?', [$id])[0] ?? null;
    if (!$supplier) {
        sx_flash(['error', 'Supplier not found.']);
        header("Location: suppliers.php");
        exit;
    }
}
$values = $supplier ?? ['name' => '', 'contact_person' => '', 'email' => '', 'phone' => '', 'address' => '',
                        'lead_time_days' => 7, 'payment_terms' => '', 'notes' => ''];
$errors = [];

// =================================================================================
// ACTIONS
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!sx_csrf_ok()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'save') {
        [$values, $errors] = sx_supplier_input();
        if (!$errors) {
            try {
                if ($id) {
                    $pdo->prepare("
                        UPDATE suppliers SET name = ?, contact_person = ?, email = ?, phone = ?, address = ?,
                               lead_time_days = ?, payment_terms = ?, notes = ?
                        WHERE id = ?
                    ")->execute([...sx_supplier_params($values), $id]);
                    sx_flash(['success', "{$values['name']} was updated."]);
                } else {
                    $pdo->prepare("
                        INSERT INTO suppliers (name, contact_person, email, phone, address, lead_time_days, payment_terms, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute(sx_supplier_params($values));
                    $id = (int)$pdo->lastInsertId();
                    sx_flash(['success', "{$values['name']} was added. Link it to products on each product's edit page."]);
                }
                header("Location: supplier.php?id={$id}");
                exit;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = "A supplier named “{$values['name']}” already exists.";
                } else {
                    error_log("Supplier save failed: " . $e->getMessage());
                    $errors[] = 'Could not save the supplier. Please try again.';
                }
            }
        }
        // may error: ipakita ulit ang form (walang redirect)
    } elseif ($id && in_array($action, ['deactivate', 'reactivate', 'delete'], true)) {
        try {
            if ($action === 'deactivate') {
                $pdo->prepare("UPDATE suppliers SET is_active = 0 WHERE id = ?")->execute([$id]);
                sx_flash(['success', "{$supplier['name']} is now inactive. Existing orders are kept; new orders can't use it."]);
            } elseif ($action === 'reactivate') {
                $pdo->prepare("UPDATE suppliers SET is_active = 1 WHERE id = ?")->execute([$id]);
                sx_flash(['success', "{$supplier['name']} is active again."]);
            } else {
                $pos = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
                $pos->execute([$id]);
                if ((int)$pos->fetchColumn() || (int)$supplier['products']) {
                    throw new RuntimeException("{$supplier['name']} has products or purchase orders, so it can't be deleted. Deactivate it instead.");
                }
                if (trim($_POST['confirm_name'] ?? '') !== $supplier['name']) {
                    throw new RuntimeException('Type the supplier name exactly to confirm deletion.');
                }
                $pdo->prepare("DELETE FROM suppliers WHERE id = ?")->execute([$id]);
                sx_flash(['success', "{$supplier['name']} was deleted."]);
                header("Location: suppliers.php");
                exit;
            }
        } catch (RuntimeException $e) {
            sx_flash(['error', $e->getMessage()]);
        } catch (PDOException $e) {
            error_log("Supplier {$action} failed for #{$id}: " . $e->getMessage());
            sx_flash(['error', 'Could not save the change. Please try again.']);
        }
        header("Location: supplier.php?id={$id}");
        exit;
    }
}

// =================================================================================
// DISPLAY
// =================================================================================
$products = $orders = [];
if ($supplier) {
    $p = $pdo->prepare("
        SELECT p.id, p.name, p.sku, p.image_path, p.cost_price, p.price, p.reorder_point,
               COALESCE(SUM(i.quantity), 0) AS stock
        FROM products p
        LEFT JOIN inventory i ON i.product_id = p.id
        WHERE p.supplier_id = ?
        GROUP BY p.id
        ORDER BY stock <= p.reorder_point DESC, p.name
    ");
    $p->execute([$id]);
    $products = $p->fetchAll(PDO::FETCH_ASSOC);

    $o = $pdo->prepare("SELECT id, po_number, status, total, expected_date, created_at, received_at FROM purchase_orders WHERE supplier_id = ? ORDER BY created_at DESC LIMIT 10");
    $o->execute([$id]);
    $orders = $o->fetchAll(PDO::FETCH_ASSOC);
}
$flash  = sx_flash();
$csrf   = sx_csrf_token();
$onTime = $supplier ? sx_supplier_on_time($supplier) : null;
$editing = !$supplier || $errors || isset($_GET['edit']);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ============================================================
   SUPPLIER (Phase 7b) — bento; tokens + sx-card / sx-table / co-dialog mula sa assets/style.css
   ============================================================ */
.su { max-width: 1240px; padding-block: var(--sx-space-5) var(--sx-space-8); }
.su-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-4); margin-bottom: var(--sx-space-5); }
.su-head h1 { display: flex; flex-wrap: wrap; align-items: center; gap: var(--sx-space-2); margin: 0; }
.su-head p { margin: 4px 0 0; color: var(--sx-muted); }
.su-head .su-actions { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); margin-left: auto; }
.su-av { display: grid; place-items: center; width: 64px; height: 64px; border-radius: var(--sx-radius-lg); font-size: 1.3rem; font-weight: var(--sx-weight-extrabold); }
.su-av.t0 { background: var(--sx-primary-soft); color: var(--sx-primary); }
.su-av.t1 { background: var(--sx-accent-soft); color: var(--sx-accent-dark); }
.su-av.t2 { background: var(--sx-status-pending-bg); color: var(--sx-status-pending); }
.su-av.t3 { background: var(--sx-status-shipped-bg); color: var(--sx-status-shipped); }
.su-av.t4 { background: var(--sx-status-cancelled-bg); color: var(--sx-status-cancelled); }

/* Bento: contact card (2 rows) + 4 KPI tiles */
.su-bento { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--sx-space-3); margin-bottom: var(--sx-space-5); }
.su-tile { display: grid; align-content: space-between; gap: var(--sx-space-2); min-height: 108px; padding: var(--sx-space-4); }
.su-tile span { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.su-tile strong { font-size: 1.6rem; font-weight: var(--sx-weight-extrabold); line-height: 1.1; color: var(--sx-dark); }
.su-tile small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.su-tile.good strong { color: var(--sx-accent-dark); }
.su-tile.warn strong { color: var(--sx-status-pending); }
.su-contact { grid-column: span 2; display: grid; gap: var(--sx-space-3); padding: var(--sx-space-5); }
.su-contact dl { display: grid; gap: var(--sx-space-3); margin: 0; }
.su-contact div { display: flex; gap: var(--sx-space-3); align-items: flex-start; }
.su-contact dt { display: grid; place-items: center; flex-shrink: 0; width: 32px; height: 32px; border-radius: var(--sx-radius-sm); background: var(--sx-surface-alt); color: var(--sx-primary); }
.su-contact dd { margin: 0; min-width: 0; color: var(--sx-dark); overflow-wrap: anywhere; }
.su-contact dd small { display: block; color: var(--sx-muted); }
.su-notes { margin: 0; padding: var(--sx-space-3); border-radius: var(--sx-radius-md); background: var(--sx-surface-alt); font-size: var(--sx-text-sm); color: var(--sx-text); white-space: pre-wrap; }

.su-layout { display: grid; gap: var(--sx-space-5); align-items: start; }
.su-main, .su-side { display: grid; gap: var(--sx-space-5); min-width: 0; }
.su-products { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--sx-space-3); }
.su-product { display: flex; gap: var(--sx-space-3); align-items: center; padding: var(--sx-space-3); border: 1px solid var(--sx-border-light); border-radius: var(--sx-radius-md); color: inherit; text-decoration: none; transition: border-color var(--sx-transition), background var(--sx-transition); }
.su-product:hover { border-color: var(--sx-primary); background: var(--sx-surface-alt); }
.su-product img { width: 48px; height: 48px; flex-shrink: 0; border-radius: var(--sx-radius-sm); background: var(--sx-surface-alt); object-fit: cover; }
.su-product div { min-width: 0; flex: 1; }
.su-product strong { display: block; overflow: hidden; font-size: var(--sx-text-sm); color: var(--sx-dark); text-overflow: ellipsis; white-space: nowrap; }
.su-bar { height: 6px; margin: 6px 0 4px; overflow: hidden; border-radius: var(--sx-radius-pill); background: var(--sx-surface-alt); }
.su-bar i { display: block; height: 100%; border-radius: inherit; background: var(--sx-accent); }
.su-bar.low i { background: var(--sx-status-pending); }
.su-bar.out i { background: var(--sx-status-cancelled); }
.su-product small { font-size: var(--sx-text-xs); color: var(--sx-muted); }
.su-table .num { text-align: right; white-space: nowrap; }
.su-table .muted { color: var(--sx-muted); font-size: var(--sx-text-sm); white-space: nowrap; }
.su-note { margin: 0 0 var(--sx-space-3); font-size: var(--sx-text-sm); color: var(--sx-muted); }
.su-danger { border: 1px solid var(--sx-status-cancelled-bg); }
.su-form .pf-grid { grid-template-columns: 1fr; }
@media (min-width: 640px) { .su-form .pf-grid { grid-template-columns: 1fr 1fr; } .su-form .pf-full { grid-column: 1 / -1; } }
@media (min-width: 768px) {
    .su-bento { grid-template-columns: 1.4fr repeat(2, 1fr); }
    .su-contact { grid-column: 1; grid-row: span 2; }
}
@media (min-width: 992px) { .su-layout { grid-template-columns: 1fr 340px; } }
</style>

<div class="su sx-container">
    <nav class="pf-crumb" aria-label="Breadcrumb">
        <a href="suppliers.php">Suppliers</a> <span aria-hidden="true">/</span>
        <span><?= $supplier ? h($supplier['name']) : 'New supplier' ?></span>
    </nav>

    <?php if ($flash): ?>
        <div class="sx-alert sx-alert-<?= h($flash[0]) ?>" role="status" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid <?= $flash[0] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><div><?= h($flash[1]) ?></div>
        </div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="sx-alert sx-alert-error" role="alert" style="margin-bottom: var(--sx-space-4)">
            <i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('h', $errors)) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($supplier): ?>
        <div class="su-head">
            <span class="su-av t<?= $id % 5 ?>" aria-hidden="true"><?= h(sx_supplier_initials($supplier['name'])) ?></span>
            <div>
                <h1 class="sx-heading-lg">
                    <?= h($supplier['name']) ?>
                    <?php if (!$supplier['is_active']): ?><span class="sx-pill sx-pill-cancelled">Inactive</span><?php endif; ?>
                </h1>
                <p>Supplier since <?= date('M j, Y', strtotime($supplier['created_at'])) ?><?= $supplier['payment_terms'] ? ' · ' . h($supplier['payment_terms']) : '' ?></p>
            </div>
            <div class="su-actions">
                <?php if (!$editing): ?>
                    <a href="supplier.php?id=<?= $id ?>&amp;edit=1#details" class="sx-btn sx-btn-outline sx-btn-sm"><i class="fa-solid fa-pen"></i> Edit</a>
                <?php endif; ?>
                <?php if ($supplier['is_active']): ?>
                    <a href="purchase_order.php?supplier=<?= $id ?>" class="sx-btn sx-btn-primary sx-btn-sm"><i class="fa-solid fa-plus"></i> New purchase order</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="su-bento">
            <section class="sx-card su-contact" aria-label="Contact">
                <dl>
                    <div><dt><i class="fa-solid fa-user"></i></dt><dd><?= h($supplier['contact_person'] ?: '—') ?><small>Contact person</small></dd></div>
                    <div><dt><i class="fa-solid fa-envelope"></i></dt><dd><?= $supplier['email'] ? '<a href="mailto:' . h($supplier['email']) . '">' . h($supplier['email']) . '</a>' : '—' ?><small>Email</small></dd></div>
                    <div><dt><i class="fa-solid fa-phone"></i></dt><dd><?= $supplier['phone'] ? '<a href="tel:' . h(preg_replace('/[^0-9+]/', '', $supplier['phone'])) . '">' . h($supplier['phone']) . '</a>' : '—' ?><small>Phone</small></dd></div>
                    <div><dt><i class="fa-solid fa-location-dot"></i></dt><dd><?= h($supplier['address'] ?: '—') ?><small>Address</small></dd></div>
                </dl>
                <?php if ($supplier['notes']): ?><p class="su-notes"><?= h($supplier['notes']) ?></p><?php endif; ?>
            </section>
            <div class="sx-card su-tile">
                <span>Products supplied</span>
                <strong><?= (int)$supplier['products'] ?></strong>
                <small><?= count(array_filter($products, fn($r) => (int)$r['stock'] <= (int)$r['reorder_point'])) ?> at or below reorder point</small>
            </div>
            <div class="sx-card su-tile">
                <span>Open orders</span>
                <strong><?= (int)$supplier['open_pos'] ?></strong>
                <small>₱<?= number_format((float)$supplier['open_value'], 2) ?> still to arrive</small>
            </div>
            <div class="sx-card su-tile<?= $onTime === null ? '' : ($onTime >= 90 ? ' good' : ' warn') ?>">
                <span>On-time delivery</span>
                <strong><?= $onTime === null ? '—' : $onTime . '%' ?></strong>
                <small><?= (int)$supplier['received_pos'] ?> completed order<?= (int)$supplier['received_pos'] === 1 ? '' : 's' ?></small>
            </div>
            <div class="sx-card su-tile">
                <span>Lead time</span>
                <strong><?= $supplier['avg_lead'] !== null ? number_format((float)$supplier['avg_lead'], 1) . 'd' : (int)$supplier['lead_time_days'] . 'd' ?></strong>
                <small><?= $supplier['avg_lead'] !== null ? 'Actual average · promised ' . (int)$supplier['lead_time_days'] . 'd' : 'Promised (no deliveries yet)' ?></small>
            </div>
        </div>
    <?php else: ?>
        <div class="pf-head">
            <div>
                <h1 class="sx-heading-lg">New supplier</h1>
                <p>Lead time sets the default expected date on purchase orders.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="su-layout">
        <div class="su-main">
            <?php if ($editing): ?>
                <form method="post" class="sx-card su-form" id="details">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
                    <h2 class="pf-title"><?= $supplier ? 'Edit details' : 'Details' ?></h2>
                    <div class="pf-grid">
                        <div class="sx-field pf-full">
                            <label class="sx-label" for="su-name">Company name</label>
                            <input type="text" id="su-name" name="name" class="sx-input" required maxlength="160" value="<?= h($values['name']) ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="su-contact">Contact person <span class="sx-hint">(optional)</span></label>
                            <input type="text" id="su-contact" name="contact_person" class="sx-input" maxlength="120" value="<?= h($values['contact_person']) ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="su-email">Email <span class="sx-hint">(optional)</span></label>
                            <input type="email" id="su-email" name="email" class="sx-input" maxlength="190" value="<?= h($values['email']) ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="su-phone">Phone <span class="sx-hint">(optional)</span></label>
                            <input type="tel" id="su-phone" name="phone" class="sx-input" maxlength="30" pattern="[0-9+()\-\s]{7,30}" value="<?= h($values['phone']) ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="su-lead">Lead time (days)</label>
                            <input type="number" id="su-lead" name="lead_time_days" class="sx-input" required min="0" max="365" step="1" value="<?= h((string)$values['lead_time_days']) ?>">
                        </div>
                        <div class="sx-field">
                            <label class="sx-label" for="su-terms">Payment terms <span class="sx-hint">(optional)</span></label>
                            <input type="text" id="su-terms" name="payment_terms" class="sx-input" maxlength="60" list="su-terms-list" placeholder="e.g. COD, 30 days" value="<?= h($values['payment_terms']) ?>">
                            <datalist id="su-terms-list"><option value="COD"><option value="7 days"><option value="15 days"><option value="30 days"><option value="Prepaid"></datalist>
                        </div>
                        <div class="sx-field pf-full">
                            <label class="sx-label" for="su-address">Address <span class="sx-hint">(optional)</span></label>
                            <input type="text" id="su-address" name="address" class="sx-input" maxlength="255" value="<?= h($values['address']) ?>">
                        </div>
                        <div class="sx-field pf-full">
                            <label class="sx-label" for="su-notes">Notes <span class="sx-hint">(optional)</span></label>
                            <textarea id="su-notes" name="notes" class="sx-textarea" rows="3" maxlength="2000"><?= h($values['notes']) ?></textarea>
                        </div>
                    </div>
                    <div class="pf-actions">
                        <a href="<?= $supplier ? "supplier.php?id={$id}" : 'suppliers.php' ?>" class="sx-btn sx-btn-ghost">Cancel</a>
                        <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid fa-check"></i> <?= $supplier ? 'Save changes' : 'Add supplier' ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($supplier): ?>
                <section class="sx-card">
                    <h2 class="pf-title">Products supplied</h2>
                    <?php if ($products): ?>
                        <div class="su-products">
                            <?php foreach ($products as $pr):
                                $stock = (int)$pr['stock']; $rp = max(1, (int)$pr['reorder_point']);
                                $cls = $stock === 0 ? 'out' : ($stock <= $rp ? 'low' : 'in');
                                $pct = min(100, (int)round($stock / ($rp * 3) * 100)); // puno ang bar sa 3× ng reorder point ?>
                                <a href="edit_product.php?id=<?= (int)$pr['id'] ?>" class="su-product">
                                    <img src="<?= h($pr['image_path'] ?: '/sweepxpress/assets/placeholder.jpg') ?>" alt="" loading="lazy" onerror="this.src='/sweepxpress/assets/placeholder.jpg'">
                                    <div>
                                        <strong><?= h($pr['name']) ?></strong>
                                        <div class="su-bar <?= $cls ?>" role="img" aria-label="<?= $stock ?> in stock, reorder at <?= (int)$pr['reorder_point'] ?>"><i style="width: <?= $pct ?>%"></i></div>
                                        <small><?= number_format($stock) ?> in stock · reorder at <?= (int)$pr['reorder_point'] ?><?= $pr['cost_price'] !== null ? ' · cost ₱' . number_format((float)$pr['cost_price'], 2) : '' ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="su-note" style="margin: 0">No products linked yet. Choose this supplier on a product's edit page.</p>
                    <?php endif; ?>
                </section>

                <section class="sx-card" style="padding: 0; overflow: hidden">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; padding: var(--sx-space-5) var(--sx-space-5) 0">
                        <h2 class="pf-title">Purchase orders</h2>
                        <?php if ($orders): ?><a href="purchase_orders.php?tab=all&amp;supplier=<?= $id ?>" class="sx-hint">All orders →</a><?php endif; ?>
                    </div>
                    <?php if ($orders): ?>
                        <div class="sx-table-wrap" style="border: 0; border-radius: 0">
                            <table class="sx-table su-table">
                                <thead><tr><th>Order</th><th>Created</th><th>Expected</th><th class="num">Total</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                        <tr>
                                            <td><a href="purchase_order.php?id=<?= (int)$o['id'] ?>"><?= h($o['po_number']) ?></a></td>
                                            <td class="muted"><?= date('M j, Y', strtotime($o['created_at'])) ?></td>
                                            <td class="muted"><?= $o['expected_date'] ? date('M j, Y', strtotime($o['expected_date'])) : '—' ?></td>
                                            <td class="num">₱<?= number_format((float)$o['total'], 2) ?></td>
                                            <td><?= sx_po_status_pill($o['status']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="sx-empty"><i class="fa-solid fa-file-invoice"></i><h3>No purchase orders yet</h3></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <?php if ($supplier): ?>
            <aside class="su-side">
                <section class="sx-card">
                    <h2 class="pf-title">Purchased</h2>
                    <p class="su-note">Value of stock received from this supplier.</p>
                    <strong style="font-size: 1.6rem; font-weight: var(--sx-weight-extrabold); color: var(--sx-dark)">₱<?= number_format((float)$supplier['purchased'], 2) ?></strong>
                    <p class="su-note" style="margin: var(--sx-space-2) 0 0">Last delivery: <?= $supplier['last_delivery'] ? date('M j, Y', strtotime($supplier['last_delivery'])) : 'none yet' ?></p>
                </section>

                <form method="post" class="sx-card">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <h2 class="pf-title">Status</h2>
                    <?php if ($supplier['is_active']): ?>
                        <input type="hidden" name="action" value="deactivate">
                        <p class="su-note">Inactive suppliers keep their history but can't be used on new purchase orders.</p>
                        <button type="submit" class="sx-btn sx-btn-outline sx-btn-block"><i class="fa-solid fa-pause"></i> Deactivate</button>
                    <?php else: ?>
                        <input type="hidden" name="action" value="reactivate">
                        <p class="su-note">This supplier is inactive.</p>
                        <button type="submit" class="sx-btn sx-btn-primary sx-btn-block"><i class="fa-solid fa-play"></i> Reactivate</button>
                    <?php endif; ?>
                </form>

                <?php $canDelete = !(int)$supplier['products'] && !$orders; ?>
                <section class="sx-card su-danger">
                    <h2 class="pf-title">Delete supplier</h2>
                    <p class="su-note"><?= $canDelete ? 'Removes the supplier for good.' : 'Only possible when no products or purchase orders use it. Deactivate it instead.' ?></p>
                    <button type="button" class="sx-btn sx-btn-danger sx-btn-block" id="openDelete" <?= $canDelete ? '' : 'disabled' ?>>Delete supplier…</button>
                </section>
            </aside>
        <?php endif; ?>
    </div>
</div>

<?php if ($supplier && $canDelete): ?>
<dialog class="co-dialog" id="deleteDialog" aria-labelledby="delTitle">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="delete">
        <div class="co-dialog-head">
            <h3 id="delTitle">Delete <?= h($supplier['name']) ?>?</h3>
            <button type="button" class="sx-btn sx-btn-ghost sx-btn-sm" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="co-dialog-body">
            <p style="margin-top: 0">This can't be undone. Type <strong><?= h($supplier['name']) ?></strong> to confirm.</p>
            <input type="text" name="confirm_name" class="sx-input" required autocomplete="off" aria-label="Supplier name">
        </div>
        <div class="co-dialog-foot">
            <button type="button" class="sx-btn sx-btn-ghost" data-close>Cancel</button>
            <button type="submit" class="sx-btn sx-btn-danger">Delete supplier</button>
        </div>
    </form>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('deleteDialog');
    document.getElementById('openDelete').addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-close]').forEach((el) => el.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
