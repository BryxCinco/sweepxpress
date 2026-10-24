<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/header.php';

// File location
$aboutFile = __DIR__ . '/../content/about.html';
$msg = '';

// 💡 FIX: Ensure the directory exists before attempting file_put_contents
$contentDir = dirname($aboutFile);
if (!is_dir($contentDir)) {
    // Attempt to create the directory with 0755 permissions (recursive)
    if (!mkdir($contentDir, 0755, true)) {
        // Handle error if directory creation fails (optional, but good practice)
        $msg = "❌ Error: Failed to create content directory.";
    }
}

// Save changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($msg)) { // Only proceed if no directory error
    $newContent = $_POST['content'] ?? '';
    // This line (was line 11) now runs after the directory is confirmed to exist
    file_put_contents($aboutFile, $newContent);
    $msg = "✅ About Us updated successfully!";
}

// Load current content
$currentContent = file_exists($aboutFile) ? file_get_contents($aboutFile) : '';
?>
<h1>Edit About Us</h1>
<?php if ($msg): ?><p style="color:green;"><?php echo $msg; ?></p><?php endif; ?>

<form method="post">
  <textarea name="content" rows="12" style="width:100%;"><?php echo htmlspecialchars($currentContent); ?></textarea>
  <br>
  <button type="submit" class="btn">Save</button>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>