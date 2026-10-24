<?php
// edit_product.php - Product Editing Interface
// 1. SESSION AND AUTH MUST BE AT THE VERY TOP
session_start();
require_once __DIR__ . '/../includes/auth.php'; 
require_once __DIR__ . '/../config.php'; // IMPORTANT: Added config.php for $pdo connection

// Added a local helper function for basic HTML escaping (Good practice)
if (!function_exists('h')) {
    function h($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

// Check Admin Access
if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit();
}

$id = 0;
$product = null;
$images = [];

// Define the list of available categories
$categories = [
    "Cleaning Agents",
    "Cleaning Tools",
    "Adhesives & Tapes",
    "Floor & Surface Care",
    "Equipment"
];

// SKU generator function (can also move to config.php if preferred)
if (!function_exists('generateSKU')) {
    function generateSKU($productName) {
        $prefix = strtoupper(substr(preg_replace('/\s+/', '', $productName), 0, 3));
        $randomNumber = rand(1000, 9999);
        return $prefix . $randomNumber;
    }
}

// Determine product id (GET first, POST may override)
if (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $id = (int)$_GET['id'];
}

// ==========================================================
// 2. PROCESS POST REQUESTS (MUST BE AT THE TOP)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (isset($_POST['id']) && filter_var($_POST['id'], FILTER_VALIDATE_INT)) {
        $id = (int)$_POST['id'];
    }

    $errors = []; // Collect error messages
    $redirect_message = null; // Set this on success to trigger redirect
    $product_name_for_url = null; // Store name for success message
    
    // Attempt to fetch current product name for feedback messages
    if ($id > 0) {
        $stmt_name = $pdo->prepare("SELECT name FROM products WHERE id = ?");
        $stmt_name->execute([$id]);
        $product_name_for_url = $stmt_name->fetchColumn();
    }


    if ($id <= 0) {
        $errors[] = "Invalid product ID.";
    } else {
        
        // --- DELETE IMAGE (Secondary) ---
        if ($action === 'delete_image' && isset($_POST['image_id']) && filter_var($_POST['image_id'], FILTER_VALIDATE_INT)) {
            $imageId = (int)$_POST['image_id'];
            try {
                $stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE id = ? AND product_id = ?");
                $stmt->execute([$imageId, $id]);
                $image = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($image) {
                    $relative = ltrim(str_replace('/sweepxpress/', '', $image['image_path']), '/');
                    $filePath = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . $relative;

                    if ($filePath && file_exists($filePath) && is_file($filePath)) {
                        @unlink($filePath);
                    }
                    $pdo->prepare("DELETE FROM product_images WHERE id = ?")->execute([$imageId]);
                    // SUCCESS: Set redirect message
                    $redirect_message = 'Image Deleted'; 
                } else {
                    $errors[] = "Image not found.";
                }
            } catch (PDOException $e) {
                error_log("Image deletion error: " . $e->getMessage());
                $errors[] = "Database error while deleting image.";
            }
        }

        // --- ADD IMAGE(S) (Secondary) ---
        if ($action === 'add_image' && !empty($_FILES['new_image']['name'][0])) {
            $allowedTypes = ['image/jpeg','image/png','image/gif'];
            $uploadDir = realpath(__DIR__ . '/../') . '/uploads/';

            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                $errors[] = "Failed to create upload directory.";
            } elseif (!is_writable($uploadDir)) {
                $errors[] = "Upload directory is not writable.";
            } else {
                $upload_errors = [];
                $success_count = 0;
                foreach ($_FILES['new_image']['tmp_name'] as $key => $tmpName) {
                    if (!is_uploaded_file($tmpName) || $_FILES['new_image']['error'][$key] !== UPLOAD_ERR_OK) continue; 
                    
                    $filename = $_FILES['new_image']['name'][$key];
                    // Using finfo for better security, falling back to $_FILES type
                    $fileType = $_FILES['new_image']['type'][$key] ?? (function() use ($tmpName) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $type = finfo_file($finfo, $tmpName);
                        finfo_close($finfo);
                        return $type;
                    })();
                    $fileSize = $_FILES['new_image']['size'][$key];

                    if (in_array($fileType, $allowedTypes) && $fileSize < 2000000) {
                        $extension = pathinfo($filename, PATHINFO_EXTENSION);
                        $safeName = time() . '_' . uniqid() . '.' . $extension; // Generate unique name
                        $destination = $uploadDir . $safeName;
                        if (move_uploaded_file($tmpName, $destination)) {
                            $imgPath = '/sweepxpress/uploads/' . $safeName;
                            try {
                                $stmt = $pdo->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
                                $stmt->execute([$id, $imgPath]);
                                $success_count++;
                            } catch (PDOException $e) {
                                error_log("Image insert error: " . $e->getMessage());
                                $upload_errors[] = "DB error saving " . h($filename);
                                if (file_exists($destination)) @unlink($destination); 
                            }
                        } else {
                            $upload_errors[] = "Failed to move " . h($filename);
                        }
                    } else {
                        $upload_errors[] = h($filename) . ": invalid type or file too large (Max 2MB)";
                    }
                }

                if ($success_count > 0) {
                    // SUCCESS: Set redirect message
                    $redirect_message = 'Images Uploaded';
                }
                if (!empty($upload_errors)) {
                     // Collect errors from the upload loop
                    $errors[] = "Some images were not uploaded: " . implode(', ', $upload_errors);
                }
            }
        }

        // --- UPDATE DETAILS (Including Category) ---
        if ($action === 'update_details') {
            $name = trim($_POST['name'] ?? '');
            $skuInput = trim($_POST['sku'] ?? '');
            $price = filter_var($_POST['price'] ?? 0, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
            $desc = trim($_POST['description'] ?? '');
            $stock = filter_var($_POST['stock'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $category = trim($_POST['category'] ?? ''); // NEW: Category field
            
            if ($name === '' || $price === false || $desc === '' || $stock === false || !in_array($category, $categories)) {
                $errors[] = "Invalid form data submitted for product details. Check all fields and valid category selection.";
            } else {
                $sku = $skuInput === '' ? generateSKU($name) : $skuInput;
                try {
                    $stmt = $pdo->prepare("UPDATE products SET name = ?, sku = ?, description = ?, price = ?, stock = ?, category = ? WHERE id = ?"); // NEW: Added category to query
                    $stmt->execute([$name, $sku, $desc, $price, $stock, $category, $id]); 
                    // SUCCESS: Set redirect message
                    $redirect_message = 'Details Updated';
                    $product_name_for_url = $name; 
                } catch (PDOException $e) {
                    error_log("Product update error: " . $e->getMessage());
                    $errors[] = "Database error while updating product details. " . $e->getMessage();
                }
            }
        }
        
        // --- UPDATE PRIMARY IMAGE ---
        if ($action === 'update_primary_image' && isset($_FILES['primary_image']) && $_FILES['primary_image']['error'] === UPLOAD_ERR_OK) {
             $file = $_FILES['primary_image'];
             $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
             $maxSize = 2000000; 
             $uploadDir = realpath(__DIR__ . '/../') . '/uploads/';

             if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                 $errors[] = "Upload directory error.";
             } else {
                 $finfo = finfo_open(FILEINFO_MIME_TYPE);
                 $detectedType = finfo_file($finfo, $file['tmp_name']);
                 finfo_close($finfo);

                 if (in_array($detectedType, $allowedTypes) && $file['size'] <= $maxSize) {

                     $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                     $safeName = time() . '_' . uniqid() . '.' . $extension; 
                     $destination = $uploadDir . $safeName;
                     $newImagePath = '/sweepxpress/uploads/' . $safeName;

                     if (move_uploaded_file($file['tmp_name'], $destination)) {
                         try {
                             $stmt = $pdo->prepare("SELECT image_path FROM products WHERE id = ?");
                             $stmt->execute([$id]);
                             $oldProduct = $stmt->fetch(PDO::FETCH_ASSOC);

                             $stmt = $pdo->prepare("UPDATE products SET image_path = ? WHERE id = ?");
                             $stmt->execute([$newImagePath, $id]);

                             if ($oldProduct && !empty($oldProduct['image_path'])) {
                                 $relative = ltrim(str_replace('/sweepxpress/', '', $oldProduct['image_path']), '/');
                                 $filePath = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . $relative;
                                 if ($filePath && file_exists($filePath) && is_file($filePath)) {
                                     @unlink($filePath);
                                 }
                             }

                             // SUCCESS: Set redirect message
                             $redirect_message = 'Primary Image Updated'; 

                         } catch (PDOException $e) {
                             error_log("Primary image update error: " . $e->getMessage());
                             if (file_exists($destination)) @unlink($destination); 
                             $errors[] = "Database error while updating primary image.";
                         }
                     } else {
                         $errors[] = "Failed to move uploaded file.";
                     }
                 } else {
                     $errors[] = "Invalid file type or file too large for primary image.";
                 }
             }
        }
    }
    
    // --- Post-Action Redirect (PRG Pattern) ---
    if ($redirect_message) {
        $redirect_params = "id={$id}&success=" . urlencode($redirect_message);
        if ($product_name_for_url) {
            $redirect_params .= "&name=" . urlencode($product_name_for_url);
        }
        header("Location: edit_product.php?{$redirect_params}");
        exit;
    }
    
    // If there were any errors, store them in the session to display later on this page.
    if (!empty($errors)) {
        $_SESSION['alert_message'] = '<div class="alert alert-danger mt-3">❌ ' . implode('<br>❌ ', $errors) . '</div>';
    }
}

// ==========================================================
// 3. FETCH DATA FOR DISPLAY
// ==========================================================
// Fetch the product for display after any POST handling and possible redirect
if ($id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
            $stmt->execute([$id]);
            $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $product = null;
            $images = [];
            $_SESSION['alert_message'] = '<div class="alert alert-danger mt-3">Product not found.</div>';
        }
    } catch (PDOException $e) {
        error_log("Product fetch error: " . $e->getMessage());
        $product = null;
        $images = [];
        $_SESSION['alert_message'] = '<div class="alert alert-danger mt-3">Database error while fetching product.</div>';
    }
} else {
    $product = null;
    $images = [];
    $_SESSION['alert_message'] = '<div class="alert alert-danger mt-3">Invalid product ID on page load.</div>';
}

// ==========================================================
// 4. INCLUDE HEADER AND START HTML OUTPUT
// ==========================================================
// HTML output begins here!
require_once __DIR__ . '/../includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php
// Display session alerts for errors that occurred before redirect
if (isset($_SESSION['alert_message'])) {
    echo $_SESSION['alert_message'];
    unset($_SESSION['alert_message']); 
}

// ====================================================================
// 5. SWEETALERT MESSAGE DISPLAY (Reads URL parameter)
// ====================================================================
if (isset($_GET['success'])) {
    $action = h($_GET['success']);
    $product_name = h(urldecode($_GET['name'] ?? 'Product'));
    $title = '';
    $text = '';

    switch($action) {
        case 'Details Updated':
            $title = 'Details Updated!';
            $text = "The details for **{$product_name}** have been successfully saved. ✅";
            break;
        case 'Image Deleted':
            $title = 'Image Removed!';
            $text = "The gallery image has been deleted for **{$product_name}**.";
            break;
        case 'Images Uploaded':
            $title = 'Images Uploaded!';
            $text = "The new images have been added to the gallery for **{$product_name}**.";
            break;
        case 'Primary Image Updated':
            $title = 'Primary Image Changed!';
            $text = "The main product image for **{$product_name}** has been updated.";
            break;
        default:
            $title = 'Update Successful!';
            $text = 'The product was successfully modified.';
    }

    // This script runs only if ?success=... is in the URL
    echo "<script>
    document.addEventListener('DOMContentLoaded', () => {
      Swal.fire({
        icon: 'success',
        title: '{$title}',
        html: '{$text}',
        confirmButtonColor: '#3085d6'
      }).then(() => {
        // Clear URL parameters without reloading again (maintains scroll position)
        if (history.replaceState) {
            history.replaceState(null, null, window.location.pathname + window.location.search.replace(/[\?&]success=[^&]*(&name=[^&]*)?/, ''));
        }
      });
    });
    </script>";
}
?>

<?php if ($product): ?>
<div class="container my-5">
    
    <div class="mb-3">
        <a href="products.php" class="btn btn-secondary">← Back to Products</a>
    </div>

    <h1>✏️ Edit Product: **<?php echo h($product['name']); ?>**</h1>
    <hr>

    <div class="card shadow-lg border-0 mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="my-0">Product Information</h5>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="update_details">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Name</label>
                    <input type="text" name="name" class="form-control" value="<?php echo h($product['name']); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">SKU</label>
                    <input type="text" name="sku" class="form-control" maxlength="20" value="<?php echo h($product['sku'] ?? ''); ?>" placeholder="e.g. CLE1234">
                    <small class="text-muted">Leave blank to auto-generate upon saving.</small>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Description</label>
                    <textarea name="description" class="form-control" rows="3" required><?php echo h($product['description']); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Price (PHP)</label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?php echo h((string)$product['price']); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Stock Quantity</label>
                        <input type="number" name="stock" class="form-control" min="0" value="<?php echo h((string)$product['stock']); ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Category</label>
                    <select name="category" class="form-select" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo h($cat); ?>"
                                    <?php 
                                    if (isset($product['category']) && $cat === $product['category']) {
                                        echo 'selected';
                                    } 
                                    ?>>
                                <?php echo h($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success mt-2">💾 **Save Details**</button>
            </form>
        </div>
    </div>
    
    <div class="card shadow-lg border-0 mb-4">
        <div class="card-header bg-danger text-white">
            <h5 class="my-0">Primary Product Image</h5>
        </div>
        <div class="card-body">
            
            <div class="row mb-4">
                <div class="col-12">
                    <label class="form-label fw-bold">Current Primary Image</label>
                    <?php if ($product['image_path']): ?>
                        <div class="p-2 border rounded d-inline-block">
                            <img src="<?php echo h($product['image_path']); ?>" 
                                 class="img-fluid" 
                                 alt="Primary Image" 
                                 style="max-width: 150px; height: auto;">
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No primary image set.</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <hr>

            <h6 class="fw-bold">Replace Primary Image</h6>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_primary_image">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                
                <div class="mb-3">
                    <input type="file" name="primary_image" class="form-control" accept="image/*" required>
                    <small class="text-muted">Max 2MB. JPG, PNG, GIF only. **This will overwrite the current image.**</small>
                </div>
                
                <button type="submit" class="btn btn-danger">🔄 **Replace Primary Image**</button>
            </form>
            
        </div>
    </div>
    
    <div class="card shadow-lg border-0 mb-4">
        <div class="card-header bg-secondary text-white">
            <h5 class="my-0">Gallery Images (<?php echo count($images); ?>)</h5>
        </div>
        <div class="card-body">
            
            <h6 class="fw-bold mb-3">Existing Gallery Images:</h6>
            <div class="d-flex flex-wrap gap-3 mb-4 p-3 border rounded bg-light">
                <?php if (!empty($images)): ?>
                    <?php foreach ($images as $image): ?>
                    <div class="image-wrapper text-center border p-2 bg-white rounded">
                        <img src="<?php echo h($image['image_path']); ?>" 
                             class="img-thumbnail mb-2" 
                             alt="Gallery Image"
                             style="max-width: 100px; height: auto;">
                        <form method="post" style="margin-top: 5px;">
                            <input type="hidden" name="action" value="delete_image">
                            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                            <input type="hidden" name="image_id" value="<?php echo (int)$image['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100" 
                                    onclick="return confirm('Are you sure you want to delete this image?')">🗑 Delete</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted m-0">No additional gallery images available.</p>
                <?php endif; ?>
            </div>
            
            <hr>

            <h6 class="fw-bold">Add New Image(s) to Gallery</h6>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_image">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                
                <div class="mb-3">
                    <input type="file" name="new_image[]" class="form-control" accept="image/*" multiple required>
                    <small class="text-muted">Max 2MB per file. JPG, PNG, GIF only. Supports multiple file selection.</small>
                </div>
                
                <button type="submit" class="btn btn-info text-white">➕ **Upload Image(s)**</button>
            </form>
            
        </div>
    </div>
</div>

<?php else: ?>
    <?php 
        // If product is not found, show the stored error message.
        if (isset($_SESSION['alert_message'])) {
             echo '<div class="container my-5">' . $_SESSION['alert_message'] . '</div>';
             unset($_SESSION['alert_message']);
        } else {
    ?>
        <div class="container my-5">
            <div class="alert alert-danger text-center">Product ID not specified, not valid, or product not found in the database.</div>
        </div>
    <?php } ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
