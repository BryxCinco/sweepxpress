<?php
// Lumang URL — pinagsama na sa order_details.php (Phase 9f). Naiwan para sa mga lumang link at bookmark.
header('Location: order_details.php?id=' . (int)($_GET['order_id'] ?? 0) . '&from=deliveries');
exit;
