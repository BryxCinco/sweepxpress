<?php
// Lumang URL — pinagsama na sa allorders.php (Phase 9f). Naiwan para sa mga lumang link at bookmark.
$q = array_filter(['year' => (int)($_GET['year'] ?? 0), 'month' => (int)($_GET['month'] ?? 0)]);
header('Location: allorders.php' . ($q ? '?' . http_build_query($q) : ''));
exit;
