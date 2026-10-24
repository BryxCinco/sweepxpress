<?php
require_once __DIR__ . '/../config.php';

if (!is_logged_in()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

if (!is_admin()) {
    // not admin → kick back to homepage
    header("Location: /sweepxpress/index.php");
    exit;
}


