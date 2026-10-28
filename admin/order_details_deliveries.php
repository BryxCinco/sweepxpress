<?php
// Lumang URL — iisa na ang admin order page: order_details.php (Phase 9f).
// Naiwan lang para hindi masira ang mga lumang link at bookmark.
header('Location: order_details.php?id=' . (int)($_GET['id'] ?? 0) . '&from=deliveries');
exit;
