<?php
// Lumang login/register page: password-only login (walang OTP, rate limit o
// suspension check) at sirang register (mga column na wala sa users table).
// Lahat ay dumadaan na sa login.php at register.php.
$target = (($_GET['tab'] ?? '') === 'register') ? 'register.php' : 'login.php';
header("Location: /sweepxpress/" . $target);
exit;
