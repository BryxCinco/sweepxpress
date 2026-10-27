<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/product_card.php';

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
   Hero: totoong produkto para sa collage + totoong bilang
----------------------------------------------------------- */
try {
    $heroProducts = $pdo->query("SELECT p.id, p.name, p.image_path
                                 FROM products p
                                 JOIN inventory i ON i.product_id = p.id
                                 WHERE COALESCE(p.image_path, '') <> '' AND i.quantity > 0
                                 ORDER BY p.created_at DESC
                                 LIMIT 3")->fetchAll();
    $statInStock = (int) $pdo->query("SELECT COUNT(DISTINCT product_id) FROM inventory WHERE quantity > 0")->fetchColumn();
} catch (PDOException $e) {
    $heroProducts = [];
    $statInStock = 0;
}

$placeholderImage = '/sweepxpress/assets/placeholder.jpg';
?>

<style>
/* ============================================================
   STOREFRONT (Phase 9b) — tokens mula sa assets/style.css
   ============================================================ */
.store { padding-block: var(--sx-space-5) var(--sx-space-8); }

/* ---------- Hero ---------- */
.store-hero {
    position: relative;
    display: grid;
    gap: var(--sx-space-7);
    align-items: center;
    padding: var(--sx-space-7) var(--sx-space-5);
    border: 1px solid var(--sx-border-light);
    border-radius: var(--sx-radius-xl);
    background:
        radial-gradient(circle at 12% 18%, var(--sx-primary-soft) 0%, transparent 45%),
        radial-gradient(circle at 88% 85%, var(--sx-accent-soft) 0%, transparent 45%),
        var(--sx-surface);
    overflow: hidden;
}
.store-hero-text { position: relative; z-index: 1; }
.store-hero h1 { margin-bottom: var(--sx-space-4); }
.store-hero-accent {
    background-image: var(--sx-gradient-wordmark);
    background-size: 100% 133%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    -webkit-text-fill-color: transparent;
}
.store-hero .sx-lead { max-width: 520px; margin: 0 0 var(--sx-space-5); }
.store-hero-ctas { display: flex; flex-wrap: wrap; gap: var(--sx-space-3); margin-bottom: var(--sx-space-6); }
.store-hero-points {
    display: grid;
    gap: var(--sx-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}
.store-hero-points li {
    display: flex;
    align-items: center;
    gap: var(--sx-space-2);
    font-size: var(--sx-text-sm);
    font-weight: var(--sx-weight-semibold);
    color: var(--sx-text);
}
.store-hero-points i {
    display: inline-grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: var(--sx-radius-sm);
    background: var(--sx-accent-soft);
    color: var(--sx-accent-dark);
    font-size: 0.8rem;
}

/* Collage: 1 matangkad sa kaliwa, 2 sa kanan */
.store-collage {
    position: relative;
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: var(--sx-space-3);
    height: 340px;
}
.store-collage .sx-media { box-shadow: var(--sx-shadow-md); background: var(--sx-surface-alt); }
.store-collage .sx-media:first-child { grid-row: span 2; }
.store-collage .sx-media img { transition: transform 0.5s ease; }
.store-collage .sx-media:hover img { transform: scale(1.05); }
.store-collage-empty {
    grid-row: span 2;
    grid-column: span 2;
    display: grid;
    place-items: center;
    border-radius: var(--sx-radius-xl);
    background: var(--sx-gradient-brand);
    color: #fff;
    font-size: 5rem;
    font-weight: 900;
}

/* ---------- Catalog header ---------- */
.store-catalog { padding-top: var(--sx-space-8); scroll-margin-top: 80px; }
.store-catalog-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--sx-space-4);
    margin-bottom: var(--sx-space-5);
}
.store-catalog-head p { margin: var(--sx-space-2) 0 0; color: var(--sx-muted); }
.store-search {
    display: flex;
    gap: var(--sx-space-2);
    width: 100%;
    max-width: 420px;
}
.store-search .sx-input { border-radius: var(--sx-radius-pill); padding-inline: 18px; }
.store-chips {
    display: flex;
    gap: var(--sx-space-2);
    margin-bottom: var(--sx-space-5);
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.store-chips::-webkit-scrollbar { display: none; }
.store-chips .sx-chip { flex-shrink: 0; }
.store-count { grid-column: 1 / -1; margin: 0 0 calc(-1 * var(--sx-space-2)); font-size: var(--sx-text-sm); color: var(--sx-muted); }

/* ---------- Sticky filter bar (lumalabas kapag nag-scroll) ---------- */
.store-sticky {
    position: fixed;
    top: 62px;
    left: 0;
    right: 0;
    z-index: 1080;
    padding-block: var(--sx-space-3);
    background: color-mix(in srgb, var(--sx-surface) 94%, transparent);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--sx-border-light);
    box-shadow: var(--sx-shadow-sm);
    transform: translateY(-120%);
    transition: transform 0.3s ease;
}
.store-sticky.visible { transform: translateY(0); }
.store-sticky .sx-container { display: flex; align-items: center; gap: var(--sx-space-4); }
.store-sticky .store-search { max-width: 300px; flex-shrink: 0; }
.store-sticky .sx-input { padding-block: 8px; }
.store-sticky .store-chips { margin: 0; }

/* ---------- Responsive ---------- */
@media (min-width: 992px) {
    .store-hero { grid-template-columns: 1.1fr 1fr; padding: var(--sx-space-8) var(--sx-space-7); }
    .store-collage { height: 420px; }
    .store-hero-points { grid-template-columns: repeat(3, auto); justify-content: start; gap: var(--sx-space-5); }
}
@media (max-width: 767.98px) {
    .store-sticky .store-chips { display: none; }
    .store-sticky .store-search { max-width: none; flex: 1; }
}
@media (max-width: 575.98px) {
    .store-hero { padding: var(--sx-space-6) var(--sx-space-4); }
    .store-collage { height: 260px; }
    .store-sticky { top: 58px; }
}
@media (prefers-reduced-motion: reduce) {
    .store-collage .sx-media img,
    .store-sticky { transition: none; }
    .store-collage .sx-media:hover img { transform: none; }
}
</style>

<!-- Sticky filter bar (revealed on scroll) -->
<div class="store-sticky" id="stickyBar">
    <div class="sx-container">
        <form action="index.php#products" method="get" class="store-search" role="search">
            <input type="text" name="search" class="sx-input" placeholder="Search products..." value="<?php echo h($searchTerm); ?>" aria-label="Search products">
            <button type="submit" class="sx-btn sx-btn-primary sx-btn-sm" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>

        <?php if (!empty($categories)): ?>
        <div class="store-chips">
            <a href="index.php#products" class="sx-chip sticky-cat-btn <?php echo $selectedCategory === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($categories as $cat): ?>
            <a href="?category=<?php echo urlencode($cat); ?>#products"
               class="sx-chip sticky-cat-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>">
                <?php echo h($cat); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="store sx-container">

    <!-- Hero -->
    <section class="store-hero">
        <div class="store-hero-text">
            <span class="sx-eyebrow">Cleaning supplies for homes &amp; businesses</span>
            <h1 class="sx-heading-xl">Clean spaces start with the <span class="store-hero-accent">right supplies.</span></h1>
            <p class="sx-lead">Shop cleaning chemicals, tools, mats and equipment — or order in bulk with a business purchase order.</p>

            <div class="store-hero-ctas">
                <a href="#products" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-arrow">
                    Shop Now <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </a>
                <?php if (!$isUserLoggedIn): ?>
                <a href="/sweepxpress/register.php" class="sx-btn sx-btn-outline sx-btn-lg">
                    <i class="fa-solid fa-building"></i> For Businesses
                </a>
                <?php endif; ?>
            </div>

            <ul class="store-hero-points">
                <li><i class="fa-solid fa-boxes-stacked"></i> Real-time stock</li>
                <li><i class="fa-solid fa-file-invoice"></i> B2B purchase orders</li>
                <li><i class="fa-solid fa-money-bill-wave"></i> Cash on delivery</li>
            </ul>
        </div>

        <div class="store-collage">
            <?php if (count($heroProducts) >= 3): ?>
                <?php foreach ($heroProducts as $i => $hp): ?>
                <a href="/sweepxpress/product.php?id=<?php echo (int) $hp['id']; ?>" class="sx-media" aria-label="<?php echo h($hp['name']); ?>">
                    <img src="<?php echo h(trim($hp['image_path'])); ?>" alt="<?php echo h($hp['name']); ?>"
                         <?php echo $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
                         onerror="this.src='<?php echo h($placeholderImage); ?>'">
                    <?php if ($i === 0): ?>
                        <span class="sx-badge-float sx-badge-float-dark sx-pos-bl">
                            <strong><?php echo $statInStock; ?></strong><span>Products in stock</span>
                        </span>
                    <?php elseif ($i === 1): ?>
                        <span class="sx-badge-float sx-pos-tr">
                            <strong><?php echo count($categories); ?></strong><span>Categories</span>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="store-collage-empty" aria-hidden="true">X</div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Catalog -->
    <section class="store-catalog" id="products">
        <div class="store-catalog-head">
            <div>
                <span class="sx-eyebrow">Catalog</span>
                <h2 class="sx-heading-lg">Shop Cleaning Supplies</h2>
                <p>Find the best cleaning products for your home or business.</p>
            </div>
            <form action="index.php#products" method="get" class="store-search" role="search">
                <input type="text" name="search" class="sx-input" placeholder="Search products..." value="<?php echo h($searchTerm); ?>" aria-label="Search products">
                <button type="submit" class="sx-btn sx-btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
            </form>
        </div>

        <?php if (!empty($categories)): ?>
        <div class="store-chips">
            <a href="index.php#products" class="sx-chip category-btn <?php echo $selectedCategory === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($categories as $cat): ?>
            <a href="?category=<?php echo urlencode($cat); ?>#products"
               class="sx-chip category-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>">
                <?php echo h($cat); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Pinapalitan ng AJAX category filter ang laman nito (id="product-real") -->
        <div class="store-grid" id="product-real">
            <?php if (empty($products)): ?>
                <div class="sx-card sx-empty">
                    <i class="fa-solid fa-box-open"></i>
                    <h3>No products found</h3>
                    <p style="margin: 0 0 var(--sx-space-4)">Try a different search or category.</p>
                    <a href="index.php#products" class="sx-btn sx-btn-outline sx-btn-sm">View all products</a>
                </div>
            <?php else: ?>
                <p class="store-count">
                    Showing <?php echo count($products); ?> product<?php echo count($products) === 1 ? '' : 's'; ?>
                    <?php if ($searchTerm !== ''): ?> for “<?php echo h($searchTerm); ?>”<?php endif; ?>
                    <?php if ($selectedCategory !== ''): ?> in <?php echo h($selectedCategory); ?><?php endif; ?>
                </p>
                <?php foreach ($products as $p) sx_product_card($p, $isUserLoggedIn); ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
(() => {
    'use strict';

    /* -----------------------------------------------------
       Sticky filter bar: reveal after scrolling past the catalog header
    ----------------------------------------------------- */
    function initStickyBar() {
        const stickyBar = document.getElementById('stickyBar');
        const catalog = document.getElementById('products');
        if (!stickyBar || !catalog) return;
        let ticking = false;

        const updateStickyBar = () => {
            stickyBar.classList.toggle('visible', catalog.getBoundingClientRect().top < 0);
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
                sxLazyImages(productReal);
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
            document.getElementById('products').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        window.addEventListener('popstate', () => {
            loadCategory(window.location.href, { pushState: false });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initStickyBar();
        sxLazyImages();
        initCategoryFilterAjax();
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
