<?php
require_once __DIR__ . '/../includes/admin_auth.php';
include __DIR__ . '/../includes/header.php';

if (!isset($_GET['id'])) {
    echo "<p>Order not found.</p>";
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$orderId = intval($_GET['id']);
$stmt = $pdo->prepare("SELECT o.*, u.email FROM orders o 
                       JOIN users u ON o.user_id = u.id
                       WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    echo "<p>Order not found.</p>";
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$stmtItems = $pdo->prepare("SELECT oi.*, p.name FROM order_items oi
                            JOIN products p ON oi.product_id = p.id
                            WHERE oi.order_id = ?");
$stmtItems->execute([$orderId]);
$items = $stmtItems->fetchAll();
?>

<h2>Order #<?= $order['id'] ?></h2>
<p><strong>Customer:</strong> <?= htmlspecialchars($order['email']) ?></p>
<p><strong>Total:</strong> ₱<?= number_format($order['total_amount'], 2) ?></p>
<p><strong>Date:</strong> <?= $order['created_at'] ?></p>

<h3>Items</h3>
<table border="1" cellpadding="5">
    <tr>
        <th>Product</th>
        <th>Price</th>
        <th>Qty</th>
        <th>Subtotal</th>
    </tr>
    <?php foreach ($items as $item): ?>
    <tr>
        <td><?= htmlspecialchars($item['name']) ?></td>
        <td>₱<?= number_format($item['price'], 2) ?></td>
        <td><?= $item['quantity'] ?></td>
        <td>₱<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php include __DIR__ . '/../includes/footer.php'; ?>