<?php
// Pansamantalang naka-disable ang Facebook login (sirang query sa users.facebook_id,
// na walang ganoong column). Ibalik kapag may migration at na-test na ang FB app.
header("Location: /sweepxpress/login.php");
exit;
