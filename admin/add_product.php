<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/header.php';

$message = '';
$name = '';
$price = 0;
$desc = '';

// --- FORM HANDLING: CREATE PRODUCT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $price = filter_var($_POST['price'] ?? 0, FILTER_VALIDATE_FLOAT);
    $imagePath = null;
    $errors = [];

    // Basic Validation
    if ($name === '') $errors[] = 'Name is required.';
    if ($desc === '') $errors[] = 'Description is required.';
    if ($price === false || $price < 0) $errors[] = 'Valid price is required.';

    // Image Upload Logic (for the primary image)
    if (empty($errors) && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 2000000; // 2MB
        $uploadDir = realpath(__DIR__ . '/../') . '/uploads/';

        if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
            die('<div class="alert alert-danger">❌ Upload directory error. Please check permissions.</div>');
        }

        if (in_array(mime_content_type($file['tmp_name']), $allowedTypes) && $file['size'] <= $maxSize) {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safeName = time() . '_' . uniqid() . '.' . $extension; // More unique name
            $destination = $uploadDir . $safeName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                // IMPORTANT: Use the correct web path
                $imagePath = '/sweepxpress/uploads/' . $safeName;
            } else {
                $errors[] = 'Failed to move uploaded file.';
            }
        } else {
            $errors[] = 'Invalid file type or file too large (Max 2MB, JPG/PNG/GIF).';
        }
    } else if (empty($errors) && isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors besides no file selected
        $errors[] = 'Image upload error: Code ' . $_FILES['image']['error'];
    }
    
    // Database Insertion
    if (empty($errors)) {
        try {
            // Default stock to 0 for a new product, assuming a stock column exists
            $stmt = $pdo->prepare("INSERT INTO products (name, description, price, image_path, stock) VALUES (?, ?, ?, ?, 0)");
            $stmt->execute([$name, $desc, $price, $imagePath]);
            
            // Redirect after successful creation
            $newId = $pdo->lastInsertId();
            header("Location: edit_product.php?id=$newId&status=success_add");
            exit;

        } catch (PDOException $e) {
            error_log("Product insertion error: " . $e->getMessage());
            $message = '<div class="alert alert-danger">Database error while adding product.</div>';
        }
    } else {
        $message = '<div class="alert alert-warning">⚠ ' . htmlspecialchars(implode('<br>', $errors)) . '</div>';
    }

    // Preserve form data on error
    $price = (float)$_POST['price'] ?? $price; // Use POST value if validation fails
}

// Display messages (success/error)
if (isset($_GET['status']) && $_GET['status'] === 'success_add') {
    $message = '<div class="alert alert-success">✅ Product created successfully! You can now add more images.</div>';
}
if (!empty($message)) echo $message;

// Set default values for the form (useful if validation fails)
// $name, $price, $desc are already set above based on POST or initial values.

?>
<div class="container-fluid min-vh-100 bg-light px-0 py-4">
    
    <div class="d-flex justify-content-between align-items-center mb-4 px-4 px-lg-5">
        <h1 class="display-6 fw-bold text-primary">Add New Product</h1>
        <a href="products.php" class="btn btn-secondary shadow-sm">
            ← Back to Products List
        </a>
    </div>

    <div class="card shadow-lg border-0 w-100">
        <div class="card-header bg-white border-bottom py-3 px-4 px-lg-5">
            <h3 class="mb-0 text-dark">Product Details</h3>
        </div>
        <div class="card-body p-4 p-lg-5">
            <form method="post" enctype="multipart/form-data">
                
                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3" required><?php echo htmlspecialchars($desc); ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="price" class="form-label">Price (PHP)</label>
                    <input type="number" name="price" id="price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$price); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="image" class="form-label">Product Image (Primary - Max 2MB, JPG/PNG/GIF)</label>
                    <input type="file" name="image" id="image" class="form-control" accept="image/*">
                </div>

                <button type="submit" 
                    class="btn btn-lg mt-3" 
                    style="background-color: #198754 !important; border-color: #198754 !important; color: #ffffff !important;">
                    <i class="fas fa-plus me-2" style="color: #ffffff !important;"></i> Save Product
                </button>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>