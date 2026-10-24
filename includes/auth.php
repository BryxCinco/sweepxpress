<?php
// includes/auth.php — guard helpers
require_once __DIR__ . '/../config.php';
if (!is_logged_in()) {
  header(header: 'Location: /sweepxpress/login.php');
  exit;
}
?>
