<?php
// Dating hiwalay na admin login: password lang, walang OTP at walang rate limit.
// Lahat (pati admin) ay dumadaan na sa login.php; pagkatapos ng OTP,
// dinadala ang admin sa dashboard.
header("Location: /sweepxpress/login.php");
exit;
