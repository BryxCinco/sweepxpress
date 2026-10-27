<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/product_card.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch main product info and inventory quantity
$stmt = $pdo->prepare("SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON p.id = i.product_id WHERE p.id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    ?>
    <div class="sx-container sx-section">
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-box-open"></i>
            <h3>Product not found</h3>
            <p style="margin: 0 0 var(--sx-space-4)">It may have been removed or the link is incorrect.</p>
            <a href="/sweepxpress/index.php" class="sx-btn sx-btn-outline sx-btn-sm">Back to shop</a>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Determine stock availability for the main product
$stockQty      = (int)($p['stock_quantity'] ?? 0);
$is_in_stock   = $stockQty > 0;
$lowStockLimit = 10; // pareho sa admin/inventory.php
$isUserLoggedIn = is_logged_in();

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

// Gallery: pangunahing larawan + product_images, walang doble
$gallery = [];
foreach (array_merge([$p['image_path'] ?? ''], array_column($images, 'image_path')) as $file) {
    if (empty($file)) continue;
    $src = getImagePath($file);
    if (!in_array($src, $gallery, true)) $gallery[] = $src;
}
if (!$gallery) $gallery[] = getImagePath('');
$mainImage = $gallery[0];

// Related products: parehong category muna, saka may stock
$stmt = $pdo->prepare("SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity
                       FROM products p
                       LEFT JOIN inventory i ON p.id = i.product_id
                       WHERE p.id <> ?
                       ORDER BY (p.category <=> ?) DESC, (COALESCE(i.quantity, 0) > 0) DESC, p.id DESC
                       LIMIT 4");
$stmt->execute([$id, $p['category']]);
$related = $stmt->fetchAll();

$categoryUrl = !empty($p['category'])
    ? '/sweepxpress/index.php?category=' . urlencode($p['category']) . '#products'
    : '/sweepxpress/index.php#products';
?>

<style>
/* ============================================================
   PRODUCT DETAILS (Phase 9b) — tokens mula sa assets/style.css
   ============================================================ */
.pd { padding-block: var(--sx-space-5) var(--sx-space-8); }

/* Breadcrumb */
.pd-crumbs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--sx-space-2);
    margin-bottom: var(--sx-space-5);
    font-size: var(--sx-text-sm);
    color: var(--sx-muted);
}
.pd-crumbs a { color: var(--sx-muted); text-decoration: none; }
.pd-crumbs a:hover { color: var(--sx-primary); }
.pd-crumbs .fa-chevron-right { font-size: 0.65rem; opacity: 0.6; }
.pd-crumbs [aria-current] { color: var(--sx-dark); font-weight: var(--sx-weight-semibold); }
.pd-back {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    margin-right: var(--sx-space-1);
    border: 1px solid var(--sx-border);
    border-radius: 50%;
    background: var(--sx-surface);
}

/* Layout */
.pd-layout {
    display: grid;
    gap: var(--sx-space-6);
    align-items: start;
}

/* Gallery */
.pd-main {
    aspect-ratio: 1 / 1;
    border: 1px solid var(--sx-border-light);
    background: var(--sx-surface-alt);
}
.pd-main img { transition: opacity 0.2s ease; }
.pd-main .store-stock { top: var(--sx-space-4); right: var(--sx-space-4); font-size: var(--sx-text-xs); padding: 6px 12px; }
.pd-thumbs {
    display: flex;
    gap: var(--sx-space-3);
    margin-top: var(--sx-space-3);
    overflow-x: auto;
}
.pd-thumb {
    flex-shrink: 0;
    width: 76px;
    height: 76px;
    padding: 0;
    border: 2px solid transparent;
    border-radius: var(--sx-radius-md);
    background: var(--sx-surface-alt);
    overflow: hidden;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity var(--sx-transition), border-color var(--sx-transition);
}
.pd-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pd-thumb:hover { opacity: 1; }
.pd-thumb.active { opacity: 1; border-color: var(--sx-primary); }

/* Info */
.pd-title { margin-bottom: var(--sx-space-4); }
.pd-price-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-5);
}
.pd-price {
    font-size: var(--sx-text-3xl);
    font-weight: var(--sx-weight-extrabold);
    letter-spacing: var(--sx-tracking-tight);
    color: var(--sx-dark);
}
.pd-avail { position: static; backdrop-filter: none; -webkit-backdrop-filter: none; font-size: var(--sx-text-xs); padding: 6px 12px; }
.pd-desc {
    margin-bottom: var(--sx-space-5);
    line-height: 1.7;
    color: var(--sx-text);
}
.pd-specs {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--sx-space-2) var(--sx-space-5);
    margin: 0 0 var(--sx-space-5);
    padding: var(--sx-space-4) var(--sx-space-5);
    border: 1px solid var(--sx-border-light);
    border-radius: var(--sx-radius-lg);
    background: var(--sx-surface-alt);
    font-size: var(--sx-text-sm);
}
.pd-specs dt { color: var(--sx-muted); font-weight: var(--sx-weight-medium); }
.pd-specs dd { margin: 0; color: var(--sx-dark); font-weight: var(--sx-weight-semibold); }
.pd-specs .mono { font-family: var(--sx-font-mono); }
.pd-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-6);
}
.pd-actions .sx-btn { width: 100%; }
.pd-perks {
    display: grid;
    gap: var(--sx-space-4);
    padding-top: var(--sx-space-5);
    border-top: 1px solid var(--sx-border-light);
}

/* Related */
.pd-related { padding-top: var(--sx-space-8); }
.pd-related-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-5);
}

@media (min-width: 992px) {
    .pd-layout { grid-template-columns: 1.05fr 1fr; gap: var(--sx-space-8); }
    .pd-gallery { position: sticky; top: 96px; }
}
@media (max-width: 575.98px) {
    .pd-price { font-size: var(--sx-text-2xl); }
    .pd-actions { grid-template-columns: 1fr; }
    .pd-thumb { width: 64px; height: 64px; }
}
</style>

<div class="pd sx-container">

    <!-- Breadcrumb -->
    <nav class="pd-crumbs" aria-label="Breadcrumb">
        <a href="/sweepxpress/index.php" class="pd-back" aria-label="Back to shop"><i class="fa-solid fa-arrow-left"></i></a>
        <a href="/sweepxpress/index.php">Shop</a>
        <?php if (!empty($p['category'])): ?>
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <a href="<?php echo h($categoryUrl); ?>"><?php echo h($p['category']); ?></a>
        <?php endif; ?>
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        <span aria-current="page"><?php echo h($p['name']); ?></span>
    </nav>

    <div class="pd-layout">
        <!-- Gallery -->
        <div class="pd-gallery">
            <div class="sx-media pd-main">
                <img id="mainImg" src="<?php echo h($mainImage); ?>" alt="<?php echo h($p['name']); ?>" fetchpriority="high">
                <?php if (!$is_in_stock): ?>
                    <span class="store-stock out">Out of stock</span>
                <?php elseif ($stockQty <= $lowStockLimit): ?>
                    <span class="store-stock low">Only <?php echo $stockQty; ?> left</span>
                <?php endif; ?>
            </div>
            <?php if (count($gallery) > 1): ?>
                <div class="pd-thumbs">
                    <?php foreach ($gallery as $index => $src): ?>
                        <button type="button" class="pd-thumb <?php echo $index === 0 ? 'active' : ''; ?>"
                                onclick="changeMainImage(this)" aria-label="View image <?php echo $index + 1; ?>">
                            <img src="<?php echo h($src); ?>" alt="">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="pd-info">
            <?php if (!empty($p['category'])): ?>
                <span class="sx-eyebrow"><?php echo h($p['category']); ?></span>
            <?php endif; ?>
            <h1 class="sx-heading-lg pd-title"><?php echo h($p['name']); ?></h1>

            <div class="pd-price-row">
                <span class="pd-price">₱<?php echo number_format((float)$p['price'], 2); ?></span>
                <?php if (!$is_in_stock): ?>
                    <span class="store-stock out pd-avail">Out of stock</span>
                <?php elseif ($stockQty <= $lowStockLimit): ?>
                    <span class="store-stock low pd-avail">Low stock</span>
                <?php else: ?>
                    <span class="store-stock in pd-avail"><i class="fa-solid fa-check"></i> In stock</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($p['description'])): ?>
                <div class="pd-desc"><?php echo nl2br(h($p['description'])); ?></div>
            <?php endif; ?>

            <dl class="pd-specs">
                <?php if (!empty($p['sku'])): ?>
                    <dt>SKU</dt><dd class="mono"><?php echo h($p['sku']); ?></dd>
                <?php endif; ?>
                <dt>Category</dt><dd><?php echo h($p['category'] ?: 'Uncategorized'); ?></dd>
                <dt>Availability</dt>
                <dd>
                    <?php if (!$is_in_stock): ?>
                        Out of stock
                    <?php elseif ($stockQty <= $lowStockLimit): ?>
                        Only <?php echo $stockQty; ?> left
                    <?php else: ?>
                        <?php echo $stockQty; ?> available
                    <?php endif; ?>
                </dd>
            </dl>

            <div class="pd-actions">
                <?php if ($is_in_stock && $isUserLoggedIn): ?>
                    <button type="button" class="sx-btn sx-btn-primary sx-btn-lg" onclick="sxAddToCart(<?php echo (int)$p['id']; ?>, this)">
                        <i class="fa-solid fa-cart-plus"></i> Add to Cart
                    </button>
                    <button type="button" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-arrow" onclick="sxBuyNow(<?php echo (int)$p['id']; ?>, this)">
                        Buy Now <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                    </button>
                <?php elseif ($is_in_stock): ?>
                    <a href="/sweepxpress/login.php" class="sx-btn sx-btn-primary sx-btn-lg"><i class="fa-solid fa-right-to-bracket"></i> Log in to order</a>
                    <a href="/sweepxpress/register.php" class="sx-btn sx-btn-outline sx-btn-lg">Create an account</a>
                <?php else: ?>
                    <button type="button" class="sx-btn sx-btn-outline sx-btn-lg" disabled><i class="fa-solid fa-ban"></i> Out of stock</button>
                    <a href="<?php echo h($categoryUrl); ?>" class="sx-btn sx-btn-ghost sx-btn-lg">Browse similar</a>
                <?php endif; ?>
            </div>

            <div class="pd-perks">
                <div class="sx-feature">
                    <div class="sx-feature-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                    <div><p class="sx-feature-title">Cash on delivery</p><p class="sx-feature-text">Pay when your order arrives.</p></div>
                </div>
                <div class="sx-feature">
                    <div class="sx-feature-icon"><i class="fa-solid fa-truck-fast"></i></div>
                    <div><p class="sx-feature-title">Order tracking</p><p class="sx-feature-text">Follow your order from preparing to delivered.</p></div>
                </div>
                <div class="sx-feature">
                    <div class="sx-feature-icon"><i class="fa-solid fa-file-invoice"></i></div>
                    <div><p class="sx-feature-title">Business orders</p><p class="sx-feature-text">Bulk purchase orders with payment terms.</p></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($related): ?>
    <section class="pd-related">
        <div class="pd-related-head">
            <div>
                <span class="sx-eyebrow">You may also like</span>
                <h2 class="sx-heading-md"><?php echo !empty($p['category']) ? 'More in ' . h($p['category']) : 'More products'; ?></h2>
            </div>
            <a href="<?php echo h($categoryUrl); ?>" class="sx-btn sx-btn-outline sx-btn-sm">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="store-grid">
            <?php foreach ($related as $prod) sx_product_card($prod, $isUserLoggedIn); ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
function changeMainImage(thumb) {
    document.getElementById('mainImg').src = thumb.querySelector('img').src;
    document.querySelectorAll('.pd-thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
}

document.addEventListener('DOMContentLoaded', () => sxLazyImages());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
