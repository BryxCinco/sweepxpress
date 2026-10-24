<?php
require_once __DIR__ . '/config.php'; // koneksyon sa database

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    // Check kung registered ang email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        // Gumawa ng unique token
        $token = bin2hex(random_bytes(16));
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // valid for 1 hour

        // Burahin muna lahat ng lumang token ng user na 'yan
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);

        // I-save ang bagong token
        $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$email, $token, $expires_at]);

        // Link para sa email (puwede mo palitan ng iyong domain)
        $reset_link = "http://localhost/sweepxpress/reset_password.php?email=" . urlencode($email) . "&token=" . urlencode($token);

        // Halimbawa lang: ipapakita lang sa screen (sa live site dapat i-email mo)
        echo "<p>👉 I-click ang link na ito para i-reset ang password: <a href='$reset_link'>$reset_link</a></p>";
    } else {
        echo "❌ Walang account na gumagamit ng email na ito.";
    }
}
?>

<!-- Simpleng form -->
<form method="POST">
    <label>Ilagay ang iyong email:</label><br>
    <input type="email" name="email" required>
    <button type="submit">Ipadala ang reset link</button>
</form>
