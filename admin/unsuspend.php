<?php
// Lumang GET action — nasa user_actions.php na (POST + CSRF + kumpirmasyon) mula Phase 9g.
// Walang ginagawang pagbabago rito; ibinabalik lang sa account page.
header('Location: user_actions.php?id=' . (int)($_GET['user_id'] ?? 0));
exit;
