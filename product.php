<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch main product info and inventory quantity
$stmt = $pdo->prepare("SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON p.id = i.product_id WHERE p.id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    echo '<p class="alert alert-danger text-center mt-5">Product not found.</p>';
    require 'includes/footer.php';
    exit;
}

// Determine stock availability for the main product
$is_in_stock = ($p['stock_quantity'] ?? 0) > 0;

// Fetch product gallery images
$stmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$images = $stmt->fetchAll();

// Helper function for image path (SAME AS INDEX.PHP)
function getImagePath($filename) {
    $placeholder = '/sweepxpress/assets/placeholder.jpg';
    if (empty($filename)) return $placeholder;
    $filename = basename($filename);
    $paths = [
        __DIR__ . '/uploads/' . $filename,
        __DIR__ . '/assets/' . $filename
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            return str_replace(__DIR__, '/sweepxpress', $path);
        }
    }
    return $placeholder;
}

$mainImage = getImagePath($p['image_path'] ?? ($images[0]['image_path'] ?? ''));
$oldPrice = isset($p['old_price']) ? (float)$p['old_price'] : 0;
$discount = ($oldPrice > $p['price'] && $oldPrice > 0) ? round(100 - ($p['price'] / $oldPrice * 100)) : 0;
?>

<style>
/* ============================================
   PRODUCT PAGE - PERFECT MATCH WITH INDEX.PHP
   Same Design System, Consistent UX
   ============================================ */

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
    /* Colors - SAME AS INDEX.PHP */
    --primary: #2563eb;
    --primary-dark: #1e40af;
    --primary-light: #dbeafe;
    
    /* Neutrals */
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;
    
    /* Spacing */
    --s-1: 8px;
    --s-2: 12px;
    --s-3: 16px;
    --s-4: 20px;
    --s-5: 24px;
    --s-6: 32px;
    
    /* Typography */
    --text-xs: 12px;
    --text-sm: 14px;
    --text-base: 16px;
    --text-lg: 18px;
    --text-xl: 20px;
    --text-2xl: 24px;
    
    /* Radius */
    --radius: 6px;
    --radius-lg: 8px;
    --radius-xl: 12px;
    
    /* Shadows */
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
}

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    background: var(--gray-50);
    color: var(--gray-900);
}

/* Container - SAME AS INDEX.PHP */
.product-container {
    max-width: 1140px;
    margin: 0 auto;
    padding: 0 var(--s-3);
}

/* Navigation */
.nav-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: var(--s-3);
    gap: var(--s-2);
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: var(--s-1);
    padding: var(--s-1) var(--s-2);
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius);
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--gray-700);
    text-decoration: none;
    transition: all 0.15s;
}

.btn-back:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.breadcrumb {
    display: flex;
    align-items: center;
    gap: var(--s-1);
    font-size: var(--text-xs);
    color: var(--gray-500);
}

.breadcrumb a {
    color: var(--primary);
    text-decoration: none;
}

.breadcrumb a:hover {
    text-decoration: underline;
}

/* Skeleton - SAME SYSTEM AS INDEX.PHP */
.skeleton {
    background: linear-gradient(90deg, #f3f4f6 25%, #e5e7eb 50%, #f3f4f6 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
    border-radius: var(--radius);
}

@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.skeleton-img { height: 320px; margin-bottom: var(--s-2); }
.skeleton-thumbs { display: flex; gap: var(--s-1); margin-bottom: var(--s-4); }
.skeleton-thumb { width: 60px; height: 60px; border-radius: var(--radius); }
.skeleton-title { height: 28px; width: 80%; margin-bottom: var(--s-3); }
.skeleton-price { height: 60px; margin-bottom: var(--s-3); border-radius: var(--radius-lg); }
.skeleton-line { height: 14px; margin-bottom: var(--s-1); }
.skeleton-feature { height: 36px; margin-bottom: var(--s-1); border-radius: var(--radius); }
.skeleton-btn { height: 44px; margin-bottom: var(--s-1); border-radius: var(--radius-lg); }
.skeleton-guarantee { height: 50px; margin-bottom: var(--s-1); border-radius: var(--radius); }
.skeleton-section-title { height: 28px; width: 300px; margin: 0 auto var(--s-1); }
.skeleton-section-subtitle { height: 16px; width: 200px; margin: 0 auto var(--s-4); }

/* Skeleton visibility */
#product-skeleton, #related-header-skeleton, #related-skeleton { display: block; }
#product-real, #related-header-real, #related-real { display: none !important; }
body.loaded #product-skeleton, body.loaded #related-header-skeleton, body.loaded #related-skeleton { display: none !important; }
body.loaded #product-real { display: grid !important; }
body.loaded #related-header-real { display: block !important; }
body.loaded #related-real { display: grid !important; }

/* Product Layout */
.product-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--s-5);
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-xl);
    padding: var(--s-5);
    margin-bottom: var(--s-5);
}

/* Gallery */
.gallery {
    position: sticky;
    top: 80px;
    height: fit-content;
}

.main-img-wrapper {
    position: relative;
    background: var(--gray-100);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: var(--s-2);
    aspect-ratio: 1;
}

.main-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.discount-badge {
    position: absolute;
    top: var(--s-2);
    right: var(--s-2);
    background: var(--danger);
    color: white;
    padding: 4px var(--s-2);
    border-radius: 999px;
    font-size: var(--text-xs);
    font-weight: 600;
}

.thumbs {
    display: flex;
    gap: var(--s-1);
    overflow-x: auto;
}

.thumbs::-webkit-scrollbar { display: none; }

.thumb {
    width: 60px;
    height: 60px;
    border-radius: var(--radius);
    border: 2px solid transparent;
    cursor: pointer;
    object-fit: cover;
    transition: all 0.15s;
    flex-shrink: 0;
}

.thumb:hover { border-color: var(--primary); }
.thumb.active { border-color: var(--primary); }

/* Product Info */
.product-info {
    display: flex;
    flex-direction: column;
}

.product-title {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--s-3);
    line-height: 1.3;
    letter-spacing: -0.01em;
}

.price-box {
    display: flex;
    align-items: center;
    gap: var(--s-2);
    padding: var(--s-3);
    background: var(--primary-light);
    border-radius: var(--radius-lg);
    border-left: 3px solid var(--primary);
    margin-bottom: var(--s-3);
    flex-wrap: wrap;
}

.current-price {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--primary);
}

.old-price {
    font-size: var(--text-base);
    color: var(--gray-500);
    text-decoration: line-through;
}

.save-badge {
    padding: 4px var(--s-1);
    background: rgba(220, 53, 69, 0.1);
    color: var(--danger);
    border-radius: 999px;
    font-size: var(--text-xs);
    font-weight: 600;
}

.product-desc {
    font-size: var(--text-sm);
    color: var(--gray-600);
    line-height: 1.6;
    margin-bottom: var(--s-3);
    padding-bottom: var(--s-3);
    border-bottom: 1px solid var(--gray-200);
}

.features {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
    padding: var(--s-2);
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    margin-bottom: var(--s-3);
}

.feature {
    display: flex;
    align-items: center;
    gap: var(--s-1);
    font-size: var(--text-sm);
    color: var(--gray-700);
}

.feature-icon {
    width: 20px;
    height: 20px;
    background: var(--primary-light);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary);
    font-size: 11px;
    flex-shrink: 0;
    font-weight: 600;
}

.actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--s-2);
    margin-bottom: var(--s-3);
}

.btn-cart, .btn-buy {
    padding: 11px var(--s-3);
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: var(--s-1);
}

.btn-cart {
    background: white;
    color: var(--primary);
    border: 2px solid var(--primary);
}

.btn-cart:hover { background: var(--primary-light); }

.btn-buy {
    background: var(--primary);
    color: white;
}

.btn-buy:hover { background: var(--primary-dark); }

.btn-buy:disabled, .btn-cart:disabled {
    background: var(--gray-400);
    border-color: var(--gray-400);
    cursor: not-allowed;
    opacity: 0.7;
}

.guarantees {
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
    padding: var(--s-2);
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
}

.guarantee {
    display: flex;
    align-items: center;
    gap: var(--s-2);
}

.guarantee-icon {
    width: 32px;
    height: 32px;
    background: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: var(--text-base);
    flex-shrink: 0;
}

.guarantee-text strong {
    display: block;
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 2px;
}

.guarantee-text span {
    font-size: var(--text-xs);
    color: var(--gray-500);
}

/* Related Products - EXACT SAME AS INDEX.PHP */
.related { margin-bottom: var(--s-6); }

.section-header {
    text-align: center;
    margin-bottom: var(--s-4);
}

.section-title {
    font-size: var(--text-2xl);
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: var(--s-1);
}

.section-subtitle {
    font-size: var(--text-sm);
    color: var(--gray-500);
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--s-3);
}

/* SAME PRODUCT CARD AS INDEX.PHP */
.product-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: all 0.15s;
    position: relative;
}

.product-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

/* OUT OF STOCK BADGE - SAME AS INDEX.PHP */
.product-card.out-of-stock::before {
    content: 'Out of Stock';
    position: absolute;
    top: var(--s-2);
    left: var(--s-2);
    background: rgba(0,0,0,0.75);
    color: white;
    padding: 4px var(--s-2);
    border-radius: 999px;
    font-size: var(--text-xs);
    font-weight: 600;
    z-index: 2;
}

.card-img-wrapper {
    position: relative;
    width: 100%;
    height: 180px;
    background: var(--gray-100);
    overflow: hidden;
}

.card-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity 0.3s;
}

.card-img.loaded {
    opacity: 1;
}

.card-img.grayscale {
    filter: grayscale(100%);
}

.card-body {
    padding: var(--s-3);
    flex: 1;
    display: flex;
    flex-direction: column;
}

.card-title {
    font-size: var(--text-base);
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: var(--s-1);
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.card-title a {
    color: inherit;
    text-decoration: none;
}

.card-title a:hover {
    color: var(--primary);
}

.card-price {
    font-size: var(--text-lg);
    font-weight: 700;
    color: var(--primary);
    margin-bottom: var(--s-2);
}

.card-desc {
    font-size: var(--text-sm);
    color: var(--gray-600);
    line-height: 1.5;
    margin-bottom: var(--s-3);
    flex: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.card-actions {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
}

.btn-card {
    padding: 10px var(--s-3);
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    transition: all 0.15s;
    cursor: pointer;
}

.btn-card-primary {
    background: var(--primary);
    color: white;
    border: none;
}

.btn-card-primary:hover {
    background: var(--primary-dark);
    color: white;
}

.btn-card-primary:disabled {
    background: var(--gray-400);
    cursor: not-allowed;
}

.btn-card-secondary {
    background: white;
    color: var(--primary);
    border: 1px solid var(--primary);
    display: block;
}

.btn-card-secondary:hover {
    background: var(--primary-light);
    color: var(--primary-dark);
}

/* Responsive - SAME AS INDEX.PHP */
@media (max-width: 768px) {
    .product-container { padding: 0 var(--s-2); }
    
    .nav-header {
        flex-direction: column;
        align-items: flex-start;
        gap: var(--s-1);
    }
    
    .breadcrumb { font-size: 11px; }
    
    .product-layout {
        grid-template-columns: 1fr;
        gap: var(--s-3);
        padding: var(--s-3);
    }
    
    .gallery { position: static; }
    
    .main-img-wrapper {
        height: 280px;
        aspect-ratio: auto;
    }
    
    .product-title { font-size: var(--text-xl); margin-bottom: var(--s-2); }
    
    .price-box { padding: var(--s-2); margin-bottom: var(--s-2); }
    
    .current-price { font-size: var(--text-xl); }
    
    .product-desc {
        font-size: var(--text-sm);
        margin-bottom: var(--s-2);
        padding-bottom: var(--s-2);
    }
    
    .features { padding: var(--s-2); margin-bottom: var(--s-2); }
    
    .actions {
        grid-template-columns: 1fr;
        gap: var(--s-1);
        margin-bottom: var(--s-2);
    }
    
    .btn-cart, .btn-buy {
        padding: 10px var(--s-2);
        font-size: var(--text-sm);
    }
    
    .guarantees { padding: var(--s-2); gap: var(--s-2); }
    
    .guarantee-icon { width: 28px; height: 28px; font-size: var(--text-sm); }
    
    .guarantee-text strong { font-size: var(--text-xs); }
    
    .guarantee-text span { font-size: 11px; }
    
    .section-title { font-size: var(--text-xl); }
    
    .products-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: var(--s-2);
    }
    
    .card-img-wrapper { height: 140px; }
    
    .card-body { padding: var(--s-2); }
    
    .card-title { font-size: var(--text-sm); }
    
    .card-price { font-size: var(--text-base); }
    
    .btn-card { padding: 8px var(--s-2); font-size: var(--text-sm); }
}

@media (max-width: 480px) {
    .main-img-wrapper { height: 240px; }
    
    .product-title { font-size: var(--text-lg); }
    
    .current-price { font-size: var(--text-lg); }
    
    .btn-cart, .btn-buy {
        font-size: var(--text-xs);
        padding: 9px var(--s-2);
    }
    
    .actions form { display: block; width: 100%; }
    .actions form .btn-buy { width: 100%; }
}
</style>

<div class="product-container">
    <div class="nav-header">
        <a href="/sweepxpress/index.php" class="btn-back">
            <i class="bi bi-arrow-left"></i>
            Back
        </a>
        
        <nav class="breadcrumb">
            <a href="/sweepxpress/index.php">Home</a>
            <span>/</span>
            <a href="/sweepxpress/index.php">Products</a>
            <span>/</span>
            <span><?php echo htmlspecialchars($p['name']); ?></span>
        </nav>
    </div>

    <!-- Skeleton -->
    <div class="product-layout" id="product-skeleton">
        <div class="gallery">
            <div class="skeleton skeleton-img"></div>
            <div class="skeleton-thumbs">
                <div class="skeleton skeleton-thumb"></div>
                <div class="skeleton skeleton-thumb"></div>
                <div class="skeleton skeleton-thumb"></div>
            </div>
        </div>
        <div class="product-info">
            <div class="skeleton skeleton-title"></div>
            <div class="skeleton skeleton-price"></div>
            <div class="skeleton skeleton-line" style="width:100%; margin-bottom:8px"></div>
            <div class="skeleton skeleton-line" style="width:95%; margin-bottom:8px"></div>
            <div class="skeleton skeleton-line" style="width:80%; margin-bottom:16px"></div>
            <div class="skeleton skeleton-feature"></div>
            <div class="skeleton skeleton-feature"></div>
            <div class="skeleton skeleton-feature" style="margin-bottom:16px"></div>
            <div class="actions">
                <div class="skeleton skeleton-btn"></div>
                <div class="skeleton skeleton-btn"></div>
            </div>
            <div class="skeleton skeleton-guarantee"></div>
            <div class="skeleton skeleton-guarantee"></div>
            <div class="skeleton skeleton-guarantee"></div>
        </div>
    </div>

    <!-- Real Product -->
    <div class="product-layout" id="product-real">
        <div class="gallery">
            <div class="main-img-wrapper">
                <img id="mainImg" src="<?php echo htmlspecialchars($mainImage); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" class="main-img">
                <?php if ($discount > 0): ?>
                    <div class="discount-badge"><?php echo $discount; ?>% OFF</div>
                <?php endif; ?>
            </div>
            <?php if (!empty($images)): ?>
                <div class="thumbs">
                    <?php foreach ($images as $index => $img): ?>
                        <img src="<?php echo htmlspecialchars(getImagePath($img['image_path'])); ?>" 
                             class="thumb <?php echo $index === 0 ? 'active' : ''; ?>" 
                             onclick="changeMainImage(this)">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="product-info">
            <h1 class="product-title"><?php echo htmlspecialchars($p['name']); ?></h1>
            
            <div class="price-box">
                <span class="current-price">₱<?php echo number_format($p['price'], 2); ?></span>
                <?php if ($discount > 0): ?>
                    <span class="old-price">₱<?php echo number_format($oldPrice, 2); ?></span>
                    <span class="save-badge">SAVE <?php echo $discount; ?>%</span>
                <?php endif; ?>
            </div>

            <div class="product-desc">
                <?php echo nl2br(htmlspecialchars($p['description'])); ?>
            </div>

            <div class="features">
                <div class="feature">
                    <div class="feature-icon">✓</div>
                    <span>High-quality materials</span>
                </div>
                <div class="feature">
                    <div class="feature-icon">⚡</div>
                    <span>Durable and long-lasting</span>
                </div>
                <div class="feature">
                    <div class="feature-icon">🔧</div>
                    <span>Easy to use and maintain</span>
                </div>
            </div>

            <div class="actions">
                <?php if ($is_in_stock): ?>
                    <button class="btn-cart" onclick="addToCart(<?php echo (int)$p['id']; ?>)">
                        <i class="bi bi-cart-plus"></i>
                        Add to Cart
                    </button>
                    
                    <form method="POST" action="/sweepxpress/buy_now_checkout.php" style="display:contents;">
                        <input type="hidden" name="productid" value="<?php echo (int)$p['id']; ?>">
                        <button type="submit" class="btn-buy" onclick="buyNow(<?= (int)$p['id'] ?>)">
                            <i class="bi bi-lightning-charge"></i>
                            Buy Now
                        </button>
                    </form>
                <?php else: ?>
                    <button class="btn-buy" disabled style="grid-column: 1 / -1;">
                        <i class="bi bi-x-circle"></i>
                        Out of Stock
                    </button>
                <?php endif; ?>
            </div>

            <div class="guarantees">
                <div class="guarantee">
                    <div class="guarantee-icon">🚚</div>
                    <div class="guarantee-text">
                        <strong>Fast Delivery</strong>
                        <span>2-3 business days</span>
                    </div>
                </div>
                <div class="guarantee">
                    <div class="guarantee-icon">↩️</div>
                    <div class="guarantee-text">
                        <strong>Easy Returns</strong>
                        <span>7-day return policy</span>
                    </div>
                </div>
                <div class="guarantee">
                    <div class="guarantee-icon">🔒</div>
                    <div class="guarantee-text">
                        <strong>Secure Payment</strong>
                        <span>Protected checkout</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Related products - SAME QUERY AS BEFORE
$stmt = $pdo->query("SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON p.id = i.product_id WHERE p.id != $id ORDER BY p.id DESC LIMIT 8");
$products = $stmt->fetchAll();
?>

<div class="product-container related">
    <!-- Related Skeleton -->
    <div class="section-header" id="related-header-skeleton">
        <div class="skeleton skeleton-section-title"></div>
        <div class="skeleton skeleton-section-subtitle"></div>
    </div>

    <div class="section-header" id="related-header-real">
        <h2 class="section-title">You May Also Like</h2>
        <p class="section-subtitle">Discover more products from our collection</p>
    </div>

    <div class="products-grid" id="related-skeleton">
        <?php for ($i = 0; $i < 8; $i++): ?>
            <div class="product-card">
                <div class="skeleton skeleton-card-img"></div>
                <div class="card-body">
                    <div class="skeleton skeleton-line-lg" style="width: 85%;"></div>
                    <div class="skeleton skeleton-line" style="width: 40%;"></div>
                    <div class="skeleton skeleton-line" style="width: 100%;"></div>
                    <div class="skeleton skeleton-line" style="width: 80%; margin-bottom: var(--s-3);"></div>
                    <div class="skeleton skeleton-btn"></div>
                    <div class="skeleton skeleton-btn"></div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

    <!-- Related Real - SAME STRUCTURE AS INDEX.PHP -->
    <div class="products-grid" id="related-real">
        <?php foreach ($products as $prod): 
            $imgPath = getImagePath($prod['image_path']);
            $prodOldPrice = isset($prod['old_price']) ? (float)$prod['old_price'] : 0;
            $is_related_in_stock = ($prod['stock_quantity'] ?? 0) > 0;
        ?>
            <div class="product-card <?php echo !$is_related_in_stock ? 'out-of-stock' : ''; ?>">
                <div class="card-img-wrapper">
                    <img data-src="<?php echo htmlspecialchars($imgPath); ?>"
                         src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Crect fill='%23f3f4f6' width='400' height='400'/%3E%3C/svg%3E"
                         class="card-img <?php echo !$is_related_in_stock ? 'grayscale' : ''; ?>"
                         alt="<?php echo htmlspecialchars($prod['name']); ?>"
                         loading="lazy"
                         onerror="this.src='/sweepxpress/assets/placeholder.jpg'; this.classList.add('loaded');">
                </div>
                <div class="card-body">
                    <h3 class="card-title">
                        <a href="product.php?id=<?php echo (int)$prod['id']; ?>">
                            <?php echo htmlspecialchars($prod['name']); ?>
                        </a>
                    </h3>
                    <div class="card-price">₱<?php echo number_format($prod['price'], 2); ?></div>
                    <?php if (!empty($prod['description'])): ?>
                    <p class="card-desc"><?php echo htmlspecialchars(substr($prod['description'], 0, 80)); ?>...</p>
                    <?php endif; ?>
                    <div class="card-actions">
                        <?php if ($is_related_in_stock): ?>
                            <button class="btn-card btn-card-primary" onclick="addToCart(<?php echo (int)$prod['id']; ?>)">
                                Add to Cart
                            </button>
                        <?php else: ?>
                            <button class="btn-card btn-card-primary" disabled>
                                Out of Stock
                            </button>
                        <?php endif; ?>
                        <a href="product.php?id=<?php echo (int)$prod['id']; ?>" class="btn-card btn-card-secondary">
                            View Details
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// SAME LOADING SYSTEM AS INDEX.PHP
document.addEventListener('DOMContentLoaded', function() {
    const minDisplay = 600;
    const startTime = Date.now();
    
    function showContent() {
        const elapsed = Date.now() - startTime;
        const remaining = Math.max(0, minDisplay - elapsed);
        
        setTimeout(function() {
            document.body.classList.add('loaded');
            
            // Lazy load images
            const lazyImages = document.querySelectorAll('img[data-src]');
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            const src = img.dataset.src;
                            
                            if (src && src !== 'null') {
                                const tempImg = new Image();
                                tempImg.onload = function() {
                                    img.src = src;
                                    img.classList.add('loaded');
                                };
                                tempImg.onerror = function() {
                                    img.src = '/sweepxpress/assets/placeholder.jpg';
                                    img.classList.add('loaded');
                                };
                                tempImg.src = src;
                            }
                            img.removeAttribute('data-src');
                            observer.unobserve(img);
                        }
                    });
                }, { rootMargin: '50px' });
                
                lazyImages.forEach(img => observer.observe(img));
            }
        }, remaining);
    }
    
    if (document.readyState === 'complete') {
        showContent();
    } else {
        window.addEventListener('load', showContent);
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// SAME FUNCTIONS AS BEFORE
function changeMainImage(thumb) {
    document.getElementById('mainImg').src = thumb.src;
    document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
}

function addToCart(productId) {
    fetch(window.location.origin + '/sweepxpress/api/add_to_cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'product_id=' + encodeURIComponent(productId)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (typeof updateCartBadge === 'function') updateCartBadge(data.count);
            Swal.fire({
                icon: 'success',
                title: 'Added to Cart!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: data.message
            });
        }
    })
    .catch(err => console.error('Error:', err));
}

function buyNow(productId) {
    fetch(window.location.origin + '/sweepxpress/api/buy_now.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'product_id=' + encodeURIComponent(productId)
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            window.location.href = data.redirect;
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message
            });
        }
    })
    .catch(err => console.error('Error:', err));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
