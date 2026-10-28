<?php
// Lumang AJAX endpoint — ang pagpalit ng role ay nasa user_actions.php na (POST + CSRF) mula Phase 9g.
header('Content-Type: application/json');
http_response_code(410);
echo json_encode(['success' => false, 'message' => 'Role changes moved to the account page.']);
exit;
