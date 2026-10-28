<?php
// admin/settings_admin.php — iisang settings page na para sa lahat ng role (Phase 9g):
// customers/settings_customer.php (CSRF, password policy, image check, PRG).
// Dati: hiwalay na kopya na walang CSRF at password policy, at puwedeng palitan ang email nang walang check.
require_once __DIR__ . '/../includes/auth.php';
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}
require __DIR__ . '/../customers/settings_customer.php';
