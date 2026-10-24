<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php'; // ensures user is logged in

$user_id = $_SESSION['user']['id'];

// Fetch reorder history
$query = "
    SELECT 
        oi.product_id,
        p.name,
        p.image,
        COUNT(*) AS total_orders,
        MAX(o.order_date) AS last_order_date,
        MIN(o.order_date) AS first_order_date
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ?
    GROUP BY oi.product_id
    HAVING COUNT(*) >= 2
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="container mt-4">
    <h4 class="fw-bold mb-3">🛒 Recommended Reorders</h4>

    <div class="row">

<?php while ($row = $result->fetch_assoc()): ?>

<?php
    // Compute average interval
    $first = new DateTime($row['first_order_date']);
    $last = new DateTime($row['last_order_date']);

    $days_between = $first->diff($last)->days;
    $average_interval = $days_between / ($row['total_orders'] - 1);

    // Compute days since last purchase
    $today = new DateTime();
    $days_since_last = $today->diff($last)->days;

    // Show suggestion only if interval exceeded
    if ($days_since_last < $average_interval) continue;
?>

        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <img src="<?= htmlspecialchars($row['image']) ?>" class="card-img-top" style="height: 200px; object-fit: cover;">

                <div class="card-body">
                    <h5 class="card-title"><?= htmlspecialchars($row['name']) ?></h5>
                    <p class="card-text small">
                        • You typically buy this every <b><?= ceil($average_interval) ?> days</b><br>
                        • It has been <b><?= $days_since_last ?> days</b> since your last purchase
                    </p>

                    <a href="product.php?id=<?= $row['product_id'] ?>" class="btn btn-primary w-100">
                        Reorder Now
                    </a>
                </div>
            </div>
        </div>

<?php endwhile; ?>

    </div>
</div>
