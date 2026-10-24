<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';

if (!function_exists('h')) {
    /** Escape a string for safe HTML output. */
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

/* -----------------------------------------------------------
   Request input
----------------------------------------------------------- */
$selectedCategory = trim($_GET['category'] ?? '');
$searchTerm        = trim($_GET['search'] ?? '');

/* -----------------------------------------------------------
   Categories (for filter pills)
----------------------------------------------------------- */
try {
    $categories = $pdo
        ->query("SELECT DISTINCT category FROM products
                 WHERE category IS NOT NULL AND category <> ''
                 ORDER BY category ASC")
        ->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $categories = [];
}

/* -----------------------------------------------------------
   Products (search + category filters, in-stock first)
----------------------------------------------------------- */
$sql = "SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id
        WHERE 1=1";
$params = [];

if ($searchTerm !== '') {
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if ($selectedCategory !== '') {
    $sql .= " AND p.category = ?";
    $params[] = $selectedCategory;
}

// In-stock items surface before out-of-stock, newest first within each group.
$sql .= " ORDER BY CASE WHEN COALESCE(i.quantity, 0) > 0 THEN 0 ELSE 1 END ASC,
                    p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$isUserLoggedIn = is_logged_in();

/* -----------------------------------------------------------
   Hero carousel images
----------------------------------------------------------- */
$heroImages = [
    '/sweepxpress/assets/r7.jpg',
    '/sweepxpress/assets/r6.jpg',
    '/sweepxpress/assets/r10.jpg',
];

$placeholderImage = '/sweepxpress/assets/placeholder.jpg';
?>

<style>
/* ============================================================
   SHOP PAGE
   Floating filter bar on scroll, skeleton loading states,
   responsive 320px -> 2560px+. Colors intentionally unchanged.
   ============================================================ */

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

:root {
    /* Brand */
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

    /* Spacing scale */
    --s-1: 8px;
    --s-2: 12px;
    --s-3: 16px;
    --s-4: 20px;
    --s-5: 24px;
    --s-6: 32px;

    /* Type scale */
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

    /* Elevation */
    --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
    --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
    --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);

    /* Shared motion timing */
    --ease: cubic-bezier(0.4, 0, 0.2, 1);
}

body {
    margin: 0;
    padding: 0;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    -webkit-font-smoothing: antialiased;
    background: var(--gray-50);
    color: var(--gray-900);
    overscroll-behavior-y: contain;
}

.shop-container {
    max-width: 1140px;
    margin: 0 auto;
    padding: var(--s-6) var(--s-3) 0;
}

/* ---------------------------------------------------------
   Pull to refresh (mobile only)
--------------------------------------------------------- */
.ptr-indicator {
    position: fixed;
    top: 70px;
    left: 50%;
    z-index: 1000;
    width: 36px;
    height: 36px;
    pointer-events: none;
    transform: translateX(-50%) translateY(-50px);
    transition: transform 0.2s var(--ease);
}

.ptr-indicator.ptr-visible {
    transform: translateX(-50%) translateY(0);
}

.ptr-spinner {
    position: relative;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: white;
    box-shadow: var(--shadow-md);
}

.ptr-spinner::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 24px;
    height: 24px;
    margin: -12px 0 0 -12px;
    border: 3px solid var(--primary-light);
    border-top-color: var(--primary);
    border-radius: 50%;
}

.ptr-indicator.ptr-loading .ptr-spinner::after {
    animation: ptr-spin 0.6s linear infinite;
}

@keyframes ptr-spin {
    to { transform: rotate(360deg); }
}

@media (min-width: 1024px) {
    .ptr-indicator { display: none; }
}

/* ---------------------------------------------------------
   Skeleton loading
--------------------------------------------------------- */
.skeleton {
    background: linear-gradient(90deg, var(--gray-100) 25%, var(--gray-200) 50%, var(--gray-100) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
    border-radius: var(--radius);
}

@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.skeleton-hero      { width: 100%; height: 280px; border-radius: var(--radius-xl); margin-bottom: var(--s-5); }
.skeleton-title      { width: 60%; height: 32px; margin: 0 auto var(--s-2); }
.skeleton-subtitle   { width: 40%; height: 16px; margin: 0 auto var(--s-4); }
.skeleton-search     { max-width: 480px; height: 44px; margin: 0 auto var(--s-4); border-radius: 999px; }
.skeleton-pill       { width: 90px; height: 34px; border-radius: 999px; }
.skeleton-card-img   { width: 100%; height: 180px; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
.skeleton-line       { height: 14px; margin-bottom: var(--s-2); border-radius: 4px; }
.skeleton-line-lg    { height: 18px; margin-bottom: var(--s-2); }
.skeleton-btn        { height: 38px; margin-bottom: var(--s-1); border-radius: var(--radius-lg); }

/* Swap skeleton <-> real content once body.loaded is set by JS */
.hero-wrapper#hero-real,
.shop-header#header-real,
.category-wrapper#categories-real,
#product-real {
    display: none !important;
}

body.loaded .hero-wrapper#hero-skeleton,
body.loaded .shop-header#header-skeleton,
body.loaded .category-wrapper#categories-skeleton,
body.loaded #product-skeletons {
    display: none !important;
}

body.loaded .hero-wrapper#hero-real,
body.loaded .shop-header#header-real,
body.loaded .category-wrapper#categories-real {
    display: block !important;
}

body.loaded #product-real {
    display: contents !important;
}

/* ---------------------------------------------------------
   Hero carousel
--------------------------------------------------------- */
.hero-wrapper {
    margin: 0 0 var(--s-5);
}

.hero-carousel {
    position: relative;
    background: white;
    border-radius: var(--radius-xl);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.hero-track {
    display: flex;
    height: 280px;
}

.hero-slide {
    flex: 0 0 100%;
}

.hero-slide img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.hero-dots {
    position: absolute;
    bottom: var(--s-3);
    left: 50%;
    display: flex;
    gap: var(--s-1);
    padding: var(--s-1) var(--s-2);
    background: rgba(0, 0, 0, 0.3);
    border-radius: 999px;
    backdrop-filter: blur(8px);
    transform: translateX(-50%);
}

.hero-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.5);
    transition: all 0.2s var(--ease);
}

.hero-dot.active {
    width: 16px;
    background: white;
}

/* ---------------------------------------------------------
   Page header
--------------------------------------------------------- */
.shop-header {
    margin-bottom: var(--s-5);
    text-align: center;
}

.shop-title {
    margin: 0 0 var(--s-1);
    font-size: var(--text-2xl);
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--gray-900);
}

.shop-subtitle {
    margin: 0 0 var(--s-4);
    font-size: var(--text-base);
    color: var(--gray-500);
}

/* ---------------------------------------------------------
   Search — shared styling for the page-header search and the
   compact search that appears in the sticky bar on scroll.
--------------------------------------------------------- */
.search-bar,
.sticky-search {
    display: flex;
    align-items: center;
    overflow: hidden;
    background: white;
    border: 1px solid var(--gray-300);
    border-radius: var(--radius-lg);
    transition: border-color 0.15s var(--ease), box-shadow 0.15s var(--ease);
}

.search-bar {
    max-width: 520px;
    margin: 0 auto;
    box-shadow: var(--shadow-sm);
}

.search-bar input,
.sticky-search input {
    flex: 1;
    padding: 11px var(--s-3);
    border: none;
    background: transparent;
    font-size: var(--text-base);
    outline: none;
}

.sticky-search input {
    padding: 8px var(--s-2);
    font-size: var(--text-sm);
}

.search-bar input::placeholder,
.sticky-search input::placeholder {
    color: var(--gray-400);
}

.search-bar button,
.sticky-search button {
    padding: 11px var(--s-5);
    white-space: nowrap;
    background: var(--primary);
    color: white;
    border: none;
    font-size: var(--text-base);
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s var(--ease);
}

.sticky-search button {
    padding: 8px var(--s-3);
    font-size: var(--text-sm);
}

.search-bar button:hover,
.sticky-search button:hover {
    background: var(--primary-dark);
}

.search-bar:focus-within,
.sticky-search:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px var(--primary-light);
}

/* ---------------------------------------------------------
   Category pills — shared base for the page and sticky bar.
--------------------------------------------------------- */
.category-filters,
.sticky-categories {
    display: flex;
    gap: var(--s-1);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: var(--s-1);
}

.category-filters::-webkit-scrollbar,
.sticky-categories::-webkit-scrollbar {
    display: none;
}

.category-btn,
.sticky-cat-btn {
    flex: 0 0 auto;
    white-space: nowrap;
    text-decoration: none;
    border-radius: 999px;
    font-weight: 500;
    transition: all 0.15s var(--ease);
}

.category-btn {
    padding: var(--s-1) var(--s-3);
    background: white;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    font-size: var(--text-sm);
}

.category-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
}

.sticky-cat-btn {
    padding: 6px var(--s-2);
    background: var(--gray-100);
    border: 1px solid transparent;
    color: var(--gray-700);
    font-size: var(--text-xs);
}

.sticky-cat-btn:hover {
    background: var(--primary-light);
    color: var(--primary);
}

.category-btn.active,
.sticky-cat-btn.active {
    background: var(--primary);
    border-color: var(--primary);
    color: white;
}

@media (min-width: 768px) {
    .category-filters {
        flex-wrap: wrap;
        justify-content: center;
    }
}

.category-wrapper {
    margin-bottom: var(--s-5);
}

/* ---------------------------------------------------------
   Sticky filter bar (appears once the user scrolls past
   the page header)
--------------------------------------------------------- */
.sticky-filter-bar {
    position: sticky;
    top: 70px;
    left: 0;
    right: 0;
    z-index: 100;
    visibility: hidden;
    background: white;
    border-bottom: 1px solid var(--gray-200);
    opacity: 0;
    transform: translateY(-100%);
    transition: transform 0.2s var(--ease), opacity 0.2s var(--ease), box-shadow 0.2s var(--ease);
}

.sticky-filter-bar.visible {
    visibility: visible;
    opacity: 1;
    box-shadow: var(--shadow-md);
    transform: translateY(0);
}

.sticky-filter-content {
    display: flex;
    flex-direction: column;
    gap: var(--s-2);
    max-width: 1140px;
    margin: 0 auto;
    padding: var(--s-2) var(--s-3);
}

/* ---------------------------------------------------------
   Product grid
--------------------------------------------------------- */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: var(--s-4);
    margin-bottom: var(--s-6);
}

#product-real {
    transition: opacity 0.15s var(--ease);
}

#product-real.is-loading {
    opacity: 0.45;
    pointer-events: none;
}

.product-card {
    position: relative;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    transition: box-shadow 0.15s var(--ease), transform 0.15s var(--ease);
}

.product-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.product-card.out-of-stock::before {
    content: 'Out of Stock';
    position: absolute;
    top: var(--s-2);
    left: var(--s-2);
    z-index: 2;
    padding: 4px var(--s-2);
    background: rgba(0, 0, 0, 0.85);
    color: white;
    border-radius: 999px;
    font-size: var(--text-xs);
    font-weight: 600;
}

.product-img-wrapper {
    position: relative;
    width: 100%;
    height: 180px;
    background: var(--gray-100);
    overflow: hidden;
}

.product-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity 0.3s var(--ease);
}

.product-img.loaded    { opacity: 1; }
.product-img.grayscale { filter: grayscale(100%); }

.product-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    padding: var(--s-3);
}

.product-name {
    display: -webkit-box;
    margin-bottom: var(--s-1);
    overflow: hidden;
    color: var(--gray-900);
    font-size: var(--text-base);
    font-weight: 600;
    line-height: 1.3;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.product-name a {
    color: inherit;
    text-decoration: none;
}

.product-name a:hover {
    color: var(--primary);
}

.product-price {
    margin-bottom: var(--s-2);
    color: var(--primary);
    font-size: var(--text-lg);
    font-weight: 700;
}

.product-desc {
    display: -webkit-box;
    flex: 1;
    margin-bottom: var(--s-3);
    overflow: hidden;
    color: var(--gray-600);
    font-size: var(--text-sm);
    line-height: 1.5;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.product-actions {
    display: flex;
    flex-direction: column;
    gap: var(--s-1);
}

.btn-cart,
.btn-details {
    padding: 10px var(--s-3);
    border-radius: var(--radius-lg);
    font-size: var(--text-base);
    text-align: center;
    text-decoration: none;
    transition: all 0.15s var(--ease);
}

.btn-cart {
    background: var(--primary);
    color: white;
    border: none;
    font-weight: 600;
    cursor: pointer;
}

.btn-cart:hover {
    background: var(--primary-dark);
    color: white;
}

.btn-cart:disabled {
    background: var(--gray-400);
    cursor: not-allowed;
}

.btn-details {
    padding: 9px var(--s-3);
    background: white;
    color: var(--primary);
    border: 1px solid var(--primary);
    font-weight: 500;
}

.btn-details:hover {
    background: var(--primary-light);
    color: var(--primary-dark);
}

.empty-state {
    grid-column: 1 / -1;
    padding: var(--s-6) var(--s-3);
    color: var(--gray-500);
    text-align: center;
}

/* ---------------------------------------------------------
   Responsive
--------------------------------------------------------- */
@media (max-width: 768px) {
    .shop-container      { padding: var(--s-4) var(--s-2) 0; }
    .sticky-filter-bar    { top: 56px; }
    .sticky-filter-content{ padding: var(--s-2); gap: var(--s-1); }
    .hero-track,
    .skeleton-hero        { height: 200px; }
    .shop-title           { font-size: var(--text-xl); }
    .shop-subtitle        { font-size: var(--text-sm); }
    .product-grid         { grid-template-columns: repeat(2, 1fr); gap: var(--s-2); }
    .product-img-wrapper  { height: 140px; }
    .product-body         { padding: var(--s-2); }
    .product-name         { font-size: var(--text-sm); }
    .product-price        { font-size: var(--text-base); }
    .product-desc         { font-size: var(--text-xs); }
    .btn-cart, .btn-details { padding: 8px var(--s-2); font-size: var(--text-sm); }
}

@media (max-width: 480px) {
    .hero-track, .skeleton-hero { height: 160px; }
    .search-bar          { max-width: 100%; }
    .search-bar input    { font-size: var(--text-sm); }
    .search-bar button   { padding: 11px var(--s-3); font-size: var(--text-sm); }
}
</style>

<!-- Pull to refresh indicator -->
<div class="ptr-indicator" id="ptrIndicator">
    <div class="ptr-spinner"></div>
</div>

<!-- Sticky filter bar (revealed on scroll) -->
<div class="sticky-filter-bar" id="stickyBar">
    <div class="sticky-filter-content">
        <form action="" method="get" class="sticky-search">
            <input type="text" name="search" placeholder="Search products..." value="<?php echo h($searchTerm); ?>">
            <button type="submit">Search</button>
        </form>

        <?php if (!empty($categories)): ?>
        <div class="sticky-categories">
            <a href="index.php" class="sticky-cat-btn <?php echo $selectedCategory === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($categories as $cat): ?>
            <a href="?category=<?php echo urlencode($cat); ?>"
               class="sticky-cat-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>">
                <?php echo h($cat); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="shop-container">

    <!-- Hero: skeleton + real -->
    <div class="hero-wrapper" id="hero-skeleton">
        <div class="skeleton skeleton-hero"></div>
    </div>

    <div class="hero-wrapper" id="hero-real">
        <div class="hero-carousel">
            <div class="hero-track" id="heroTrack">
                <?php foreach ($heroImages as $i => $img): ?>
                <div class="hero-slide">
                    <img src="<?php echo h($img); ?>" alt="Featured <?php echo $i + 1; ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <div class="hero-dots" id="heroDots">
                <?php foreach ($heroImages as $i => $img): ?>
                <span class="hero-dot <?php echo $i === 0 ? 'active' : ''; ?>"></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Header: skeleton + real -->
    <div class="shop-header" id="header-skeleton">
        <div class="skeleton skeleton-title"></div>
        <div class="skeleton skeleton-subtitle"></div>
        <div class="skeleton skeleton-search"></div>
    </div>

    <div class="shop-header" id="header-real">
        <h1 class="shop-title">Shop Cleaning Supplies</h1>
        <p class="shop-subtitle">Find the best cleaning products for your home or business</p>
        <form action="" method="get" class="search-bar">
            <input type="text" name="search" placeholder="Search products..." value="<?php echo h($searchTerm); ?>">
            <button type="submit">Search</button>
        </form>
    </div>

    <!-- Categories: skeleton + real -->
    <div class="category-wrapper" id="categories-skeleton">
        <div class="category-filters">
            <div class="skeleton skeleton-pill"></div>
            <div class="skeleton skeleton-pill"></div>
            <div class="skeleton skeleton-pill"></div>
            <div class="skeleton skeleton-pill"></div>
        </div>
    </div>

    <?php if (!empty($categories)): ?>
    <div class="category-wrapper" id="categories-real">
        <div class="category-filters">
            <a href="index.php" class="category-btn <?php echo $selectedCategory === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($categories as $cat): ?>
            <a href="?category=<?php echo urlencode($cat); ?>"
               class="category-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>">
                <?php echo h($cat); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Product grid -->
    <div class="product-grid">

        <!-- Skeleton cards -->
        <div id="product-skeletons" style="display: contents;">
            <?php for ($i = 0; $i < 8; $i++): ?>
            <div class="product-card">
                <div class="skeleton skeleton-card-img"></div>
                <div class="product-body">
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

        <!-- Real cards -->
        <div id="product-real" style="display: none;">
            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <p>No products found matching your search.</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $p):
                    $isInStock = ($p['stock_quantity'] ?? 0) > 0;
                    $imageUrl  = !empty($p['image_path']) ? trim($p['image_path']) : $placeholderImage;
                ?>
                <div class="product-card <?php echo $isInStock ? '' : 'out-of-stock'; ?>">
                    <div class="product-img-wrapper">
                        <img
                            data-src="<?php echo h($imageUrl); ?>"
                            src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Crect fill='%23f3f4f6' width='400' height='400'/%3E%3C/svg%3E"
                            class="product-img <?php echo $isInStock ? '' : 'grayscale'; ?>"
                            alt="<?php echo h($p['name']); ?>"
                            loading="lazy"
                            onerror="this.src='<?php echo h($placeholderImage); ?>'; this.classList.add('loaded');">
                    </div>
                    <div class="product-body">
                        <h3 class="product-name">
                            <a href="/sweepxpress/product.php?id=<?php echo (int) $p['id']; ?>"><?php echo h($p['name']); ?></a>
                        </h3>
                        <div class="product-price">₱<?php echo number_format((float) $p['price'], 2); ?></div>
                        <p class="product-desc"><?php echo h($p['description']); ?></p>
                        <div class="product-actions">
                            <?php if (!$isInStock): ?>
                                <button class="btn-cart" disabled>Out of Stock</button>
                                <a href="/sweepxpress/product.php?id=<?php echo (int) $p['id']; ?>" class="btn-details">View Details</a>
                            <?php elseif ($isUserLoggedIn): ?>
                                <button class="btn-cart" onclick="addToCart(<?php echo (int) $p['id']; ?>)">Add to Cart</button>
                                <a href="/sweepxpress/product.php?id=<?php echo (int) $p['id']; ?>" class="btn-details">View Details</a>
                            <?php else: ?>
                                <a href="/sweepxpress/login.php" class="btn-cart">Add to Cart</a>
                                <a href="/sweepxpress/login.php" class="btn-details">View Details</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(() => {
    'use strict';

    const PLACEHOLDER_IMG = '<?php echo h($placeholderImage); ?>';
    const MIN_SKELETON_DISPLAY_MS = 400;

    /* -----------------------------------------------------
       Sticky filter bar: reveal after scrolling past the header
    ----------------------------------------------------- */
    function initStickyBar() {
        const stickyBar = document.getElementById('stickyBar');
        let ticking = false;

        const updateStickyBar = () => {
            const scrolled = window.pageYOffset || document.documentElement.scrollTop;
            stickyBar.classList.toggle('visible', scrolled > 400);
            ticking = false;
        };

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(updateStickyBar);
                ticking = true;
            }
        }, { passive: true });
    }

    /* -----------------------------------------------------
       Pull to refresh (mobile only)
    ----------------------------------------------------- */
    function initPullToRefresh() {
        if (window.innerWidth >= 1024) return;

        const ptr = document.getElementById('ptrIndicator');
        const threshold = 70;
        let startY = 0;
        let currentY = 0;
        let isPulling = false;
        let isRefreshing = false;

        document.addEventListener('touchstart', (e) => {
            if (window.pageYOffset === 0 && !isRefreshing) {
                startY = e.touches[0].clientY;
                isPulling = true;
            }
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!isPulling || isRefreshing) return;
            currentY = e.touches[0].clientY;
            if (currentY - startY > 10) {
                ptr.classList.add('ptr-visible');
            }
        }, { passive: true });

        document.addEventListener('touchend', () => {
            if (!isPulling || isRefreshing) return;

            if (currentY - startY >= threshold) {
                isRefreshing = true;
                ptr.classList.add('ptr-loading');
                setTimeout(() => window.location.reload(), 500);
            } else {
                ptr.classList.remove('ptr-visible');
                isPulling = false;
            }
        });
    }

    /* -----------------------------------------------------
       Hero carousel: auto-advancing slides with dot navigation
    ----------------------------------------------------- */
    function initHeroCarousel() {
        const track = document.getElementById('heroTrack');
        const dotsBox = document.getElementById('heroDots');
        if (!track || !dotsBox) return;

        const slides = track.querySelectorAll('.hero-slide');
        const dots = dotsBox.querySelectorAll('.hero-dot');
        let current = 0;

        const goToSlide = (index) => {
            current = (index + slides.length) % slides.length;
            track.style.transition = 'transform 0.5s ease';
            track.style.transform = `translateX(-${current * track.clientWidth}px)`;
            dots.forEach((dot, i) => dot.classList.toggle('active', i === current));
        };

        window.addEventListener('resize', () => {
            track.style.transition = 'none';
            track.style.transform = `translateX(-${current * track.clientWidth}px)`;
        });

        setInterval(() => goToSlide(current + 1), 4000);
        dots.forEach((dot, i) => dot.addEventListener('click', () => goToSlide(i)));
    }

    /* -----------------------------------------------------
       Lazy-load product images once real content is shown
    ----------------------------------------------------- */
    function lazyLoadImages(scope = document) {
        const images = scope.querySelectorAll('img[data-src]');

        const loadImage = (img) => {
            const src = img.dataset.src;
            img.removeAttribute('data-src');

            if (!src || src === 'null' || src === 'undefined') {
                img.src = PLACEHOLDER_IMG;
                img.classList.add('loaded');
                return;
            }

            const preload = new Image();
            preload.onload = () => { img.src = src; img.classList.add('loaded'); };
            preload.onerror = () => { img.src = PLACEHOLDER_IMG; img.classList.add('loaded'); };
            preload.src = src;
        };

        if (!('IntersectionObserver' in window)) {
            images.forEach(loadImage);
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    loadImage(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '50px' });

        images.forEach((img) => observer.observe(img));
    }

    /* -----------------------------------------------------
       Category filter: fetch the filtered product list in the
       background and swap it in place, instead of reloading the
       whole page (which also resets scroll position).
    ----------------------------------------------------- */
    function initCategoryFilterAjax() {
        const productReal = document.getElementById('product-real');
        if (!productReal) return;

        const syncActiveState = (sourceDoc) => {
            const activeHrefs = new Set(
                Array.from(sourceDoc.querySelectorAll('.category-btn.active, .sticky-cat-btn.active'))
                    .map((a) => a.getAttribute('href'))
            );
            document.querySelectorAll('.category-btn, .sticky-cat-btn').forEach((btn) => {
                btn.classList.toggle('active', activeHrefs.has(btn.getAttribute('href')));
            });
        };

        const loadCategory = async (url, { pushState = true } = {}) => {
            productReal.classList.add('is-loading');

            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) throw new Error(`Request failed with ${res.status}`);

                const html = await res.text();
                const freshDoc = new DOMParser().parseFromString(html, 'text/html');
                const freshProductReal = freshDoc.getElementById('product-real');
                if (!freshProductReal) throw new Error('Product list not found in response');

                productReal.innerHTML = freshProductReal.innerHTML;
                lazyLoadImages(productReal);
                syncActiveState(freshDoc);

                if (pushState) {
                    history.pushState({ ajaxFilter: true }, '', url);
                }
            } catch (err) {
                console.error('Category filter failed, falling back to normal navigation:', err);
                window.location.href = url;
            } finally {
                productReal.classList.remove('is-loading');
            }
        };

        document.addEventListener('click', (e) => {
            const link = e.target.closest('.category-btn, .sticky-cat-btn');
            if (!link) return;

            e.preventDefault();
            loadCategory(link.href);
        });

        window.addEventListener('popstate', () => {
            loadCategory(window.location.href, { pushState: false });
        });
    }

    /* -----------------------------------------------------
       Swap skeletons for real content once the page has loaded
    ----------------------------------------------------- */
    function initContentReveal() {
        const startTime = Date.now();

        const reveal = () => {
            const remaining = Math.max(0, MIN_SKELETON_DISPLAY_MS - (Date.now() - startTime));
            setTimeout(() => {
                document.body.classList.add('loaded');
                lazyLoadImages();
                initHeroCarousel();
            }, remaining);
        };

        if (document.readyState === 'complete') {
            reveal();
        } else {
            window.addEventListener('load', reveal);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initStickyBar();
        initPullToRefresh();
        initContentReveal();
        initCategoryFilterAjax();
    });

    /* -----------------------------------------------------
       Add to cart
    ----------------------------------------------------- */
    window.addToCart = function addToCart(productId) {
        fetch(`${window.location.origin}/sweepxpress/api/add_to_cart.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `product_id=${encodeURIComponent(productId)}`,
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    if (typeof updateCartBadge === 'function') {
                        updateCartBadge(data.count);
                    }
                    alert('Product added to cart!');
                } else {
                    alert(data.message || 'Failed to add to cart');
                }
            })
            .catch((err) => {
                console.error('Error:', err);
                alert('Failed to add to cart');
            });
    };
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>