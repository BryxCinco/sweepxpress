<?php
session_start();
require_once __DIR__ . '/../config.php';

// Force JSON response
header('Content-Type: application/json');

// Check POST input
if (!isset($_POST['product_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Product ID is required']);
    exit;
}

$product_id = (int)$_POST['product_id'];

// Fetch product details
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['status' => 'error', 'message' => 'Product not found']);
    exit;
}

// Store Buy Now data in session
$_SESSION['buy_now_item'] = [
    'id'       => $product['id'],
    'name'     => $product['name'],
    'price'    => (float)$product['price'],
    'quantity' => 1, // ← missing comma fixed!
    'image'    => $product['image_path'] // exact DB image path
];

echo json_encode([
    'status' => 'success',
    'redirect' => '/sweepxpress/buy_now_checkout.php'
]);
exit;
?>
