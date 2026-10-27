<?php
require_once __DIR__ . '/config.php';

/* -----------------------------------------------------------
   Request input (bago ang header: ginagamit ng search sa navbar)
----------------------------------------------------------- */
$selectedCategory = trim($_GET['category'] ?? '');
$searchTerm       = trim($_GET['search'] ?? '');
$sortOptions = [
    'latest'     => ['Latest',    'p.created_at DESC'],
    'popular'    => ['Top sales', 'sold DESC, p.created_at DESC'],
    'price_asc'  => ['Price ↑',   'p.price ASC'],
    'price_desc' => ['Price ↓',   'p.price DESC'],
];
$sort = isset($sortOptions[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'latest';

// Search sa navbar (index lang) — tingnan ang includes/header.php
$sxHeaderSearch = true;
$sxSearchValue  = $searchTerm;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/product_card.php';

if (!function_exists('h')) {
    /** Escape a string for safe HTML output. */
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

// Ilang piraso na ang naibenta bawat produkto (hindi kasama ang cancelled na order)
$soldJoin = "LEFT JOIN (SELECT oi.product_id, SUM(oi.quantity) AS sold
                        FROM order_items oi
                        JOIN orders o ON o.id = oi.order_id AND o.status <> 'cancelled'
                        GROUP BY oi.product_id) s ON s.product_id = p.id";

/* -----------------------------------------------------------
   Categories (tiles + chips) na may bilang ng produkto
----------------------------------------------------------- */
try {
    $categoryCounts = $pdo
        ->query("SELECT category, COUNT(*) AS n FROM products
                 WHERE category IS NOT NULL AND category <> ''
                 GROUP BY category ORDER BY category ASC")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $categoryCounts = [];
}
$categories = array_keys($categoryCounts);
$totalProducts = array_sum($categoryCounts);

// Icon bawat category (default: kahon)
$categoryIcons = [
    'Cleaning Supplies'     => 'fa-spray-can-sparkles',
    'Equipment'             => 'fa-screwdriver-wrench',
    'Floor & Surface Care'  => 'fa-broom',
    'Floor Mats'            => 'fa-rug',
    'Tapes & Adhesives'     => 'fa-tape',
    'Tools & Accessories'   => 'fa-toolbox',
];

/* -----------------------------------------------------------
   Products (search + category filters + sort, in-stock first)
----------------------------------------------------------- */
$sql = "SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity, COALESCE(s.sold, 0) AS sold
        FROM products p
        LEFT JOIN inventory i ON p.id = i.product_id
        $soldJoin
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

// In-stock items muna, saka ayon sa napiling sort (galing sa whitelist sa itaas)
$sql .= " ORDER BY CASE WHEN COALESCE(i.quantity, 0) > 0 THEN 0 ELSE 1 END ASC, " . $sortOptions[$sort][1];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$isUserLoggedIn = is_logged_in();
$isFiltered = $searchTerm !== '' || $selectedCategory !== '';

/* -----------------------------------------------------------
   Hero + best sellers
----------------------------------------------------------- */
try {
    $heroProducts = $pdo->query("SELECT p.id, p.name, p.image_path
                                 FROM products p
                                 JOIN inventory i ON i.product_id = p.id
                                 WHERE COALESCE(p.image_path, '') <> '' AND i.quantity > 0
                                 ORDER BY p.created_at DESC
                                 LIMIT 3")->fetchAll();
    $statInStock = (int) $pdo->query("SELECT COUNT(DISTINCT product_id) FROM inventory WHERE quantity > 0")->fetchColumn();

    // Top 6 na may benta at may stock (nakatago kapag wala pang benta, hal. sa bagong DB)
    $bestSellers = $pdo->query("SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity, s.sold
                                FROM products p
                                JOIN inventory i ON i.product_id = p.id AND i.quantity > 0
                                $soldJoin
                                WHERE s.sold > 0
                                ORDER BY s.sold DESC
                                LIMIT 6")->fetchAll();
} catch (PDOException $e) {
    $heroProducts = [];
    $statInStock = 0;
    $bestSellers = [];
}

$placeholderImage = '/sweepxpress/assets/placeholder.jpg';

// URL ng filter/sort na pinapanatili ang ibang napili
function store_url(array $changes): string {
    global $selectedCategory, $searchTerm, $sort;
    $q = array_filter(array_merge(
        ['category' => $selectedCategory, 'search' => $searchTerm, 'sort' => $sort === 'latest' ? '' : $sort],
        $changes
    ), fn($v) => $v !== '' && $v !== null);
    return 'index.php' . ($q ? '?' . http_build_query($q) : '') . '#products';
}
?>

<style>
/* ============================================================
   STOREFRONT (Phase 9b, Shopee-style) — tokens mula sa assets/style.css
   ============================================================ */
.store-wide {
    max-width: 1440px;
    margin-inline: auto;
    padding: var(--sx-space-4) var(--sx-space-4) var(--sx-space-8);
}
.store-section { margin-top: var(--sx-space-7); }
.store-section-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--sx-space-3);
    margin-bottom: var(--sx-space-4);
}
.store-section-head h2 {
    display: flex;
    align-items: center;
    gap: var(--sx-space-2);
    margin: 0;
    font-size: var(--sx-text-xl);
    font-weight: var(--sx-weight-extrabold);
    letter-spacing: var(--sx-tracking-tight);
    color: var(--sx-dark);
}
.store-section-head h2 small { font-size: var(--sx-text-sm); font-weight: var(--sx-weight-medium); color: var(--sx-muted); }

/* ---------- Banner (compact hero) ---------- */
.store-banner {
    position: relative;
    display: grid;
    gap: var(--sx-space-5);
    align-items: center;
    padding: var(--sx-space-6) var(--sx-space-5);
    border-radius: var(--sx-radius-xl);
    background: var(--sx-gradient-brand);
    color: #fff;
    overflow: hidden;
}
.store-banner::before,
.store-banner::after {                      /* malalambot na bilog sa background */
    content: "";
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
}
.store-banner::before { width: 360px; height: 360px; right: -80px; top: -160px; background: radial-gradient(circle, rgba(110, 231, 183, 0.35) 0%, transparent 70%); }
.store-banner::after  { width: 260px; height: 260px; left: 30%; bottom: -170px; background: radial-gradient(circle, rgba(255, 255, 255, 0.12) 0%, transparent 70%); }
.store-banner-text { position: relative; z-index: 1; }
.store-banner .sx-eyebrow { color: rgba(255, 255, 255, 0.8); margin-bottom: var(--sx-space-2); }
.store-banner h1 {
    margin: 0 0 var(--sx-space-3);
    font-size: clamp(1.7rem, 1.1rem + 2vw, 2.6rem);
    font-weight: var(--sx-weight-extrabold);
    letter-spacing: var(--sx-tracking-tight);
    line-height: var(--sx-leading-tight);
    color: #fff;
}
.store-banner h1 span { color: var(--sx-accent-light); }
.store-banner p { margin: 0 0 var(--sx-space-5); max-width: 480px; color: rgba(255, 255, 255, 0.85); }
.store-banner-ctas { display: flex; flex-wrap: wrap; gap: var(--sx-space-3); }
.store-banner .sx-btn-outline { background: transparent; color: #fff; border-color: rgba(255, 255, 255, 0.35); }
.store-banner .sx-btn-outline:hover { color: #fff; border-color: #fff; }
.store-banner-stats { display: flex; flex-wrap: wrap; gap: var(--sx-space-2); margin-top: var(--sx-space-5); }
.store-banner-stats span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: var(--sx-radius-pill);
    background: rgba(255, 255, 255, 0.14);
    font-size: var(--sx-text-xs);
    font-weight: var(--sx-weight-semibold);
}
.store-banner-stats i { color: var(--sx-accent-light); }

/* 3 produkto na naka-"fan" sa kanan ng banner */
.store-banner-art { position: relative; z-index: 1; display: none; height: 230px; }
.store-banner-art a {
    position: absolute;
    width: 170px;
    aspect-ratio: 1 / 1;
    border: 4px solid rgba(255, 255, 255, 0.9);
    border-radius: var(--sx-radius-lg);
    background: #fff;
    overflow: hidden;
    box-shadow: var(--sx-shadow-lg);
    transition: transform 0.3s ease;
}
.store-banner-art a img { width: 100%; height: 100%; object-fit: cover; }
.store-banner-art a:nth-child(1) { left: 0;     top: 40px; transform: rotate(-8deg); }
.store-banner-art a:nth-child(2) { left: 130px; top: 0;    transform: rotate(3deg); z-index: 2; }
.store-banner-art a:nth-child(3) { left: 260px; top: 50px; transform: rotate(10deg); }
.store-banner-art a:hover { transform: rotate(0) translateY(-6px) scale(1.03); z-index: 3; }

/* ---------- Category tiles ---------- */
.store-cats {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(118px, 1fr);
    gap: var(--sx-space-3);
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.store-cats::-webkit-scrollbar { display: none; }
.store-cat {
    display: grid;
    justify-items: center;
    gap: 6px;
    padding: var(--sx-space-4) var(--sx-space-2);
    border: 1px solid var(--sx-border);
    border-radius: var(--sx-radius-lg);
    background: var(--sx-surface);
    text-align: center;
    text-decoration: none;
    transition: border-color var(--sx-transition), transform var(--sx-transition), box-shadow var(--sx-transition);
}
.store-cat:hover { transform: translateY(-3px); border-color: var(--sx-primary); box-shadow: var(--sx-shadow-md); }
.store-cat i {
    display: inline-grid;
    place-items: center;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--sx-primary-soft);
    color: var(--sx-primary);
    font-size: 1.15rem;
}
.store-cat strong { font-size: var(--sx-text-xs); font-weight: var(--sx-weight-bold); line-height: 1.25; color: var(--sx-dark); }
.store-cat span { font-size: 0.7rem; color: var(--sx-muted); }
.store-cat.active { border-color: var(--sx-primary); background: var(--sx-primary-soft); }
.store-cat.active i { background: var(--sx-primary); color: var(--sx-on-primary); }

/* ---------- Sticky toolbar (chips + sort) — sticky sa ilalim ng navbar ---------- */
.store-catalog { scroll-margin-top: calc(var(--sx-nav-h, 70px) + 8px); }
.store-toolbar {
    position: sticky;
    top: var(--sx-nav-h, 70px);   /* sinusukat ng JS sa ibaba mula sa totoong taas ng navbar */
    z-index: 20;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--sx-space-3);
    margin: 0 calc(-1 * var(--sx-space-4)) var(--sx-space-4);
    padding: var(--sx-space-3) var(--sx-space-4);
    background: color-mix(in srgb, var(--sx-bg) 92%, transparent);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--sx-border-light);
}
.store-chips {
    display: flex;
    flex: 1 1 320px;
    gap: var(--sx-space-2);
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.store-chips::-webkit-scrollbar { display: none; }
.store-chips .sx-chip { flex-shrink: 0; padding: 6px 14px; }
.store-sort { display: flex; align-items: center; gap: 4px; padding: 3px; border: 1px solid var(--sx-border); border-radius: var(--sx-radius-pill); background: var(--sx-surface); }
.store-sort > span { padding: 0 6px 0 10px; font-size: var(--sx-text-xs); font-weight: var(--sx-weight-semibold); color: var(--sx-muted); }
.store-sort a {
    padding: 5px 12px;
    border-radius: var(--sx-radius-pill);
    font-size: var(--sx-text-xs);
    font-weight: var(--sx-weight-semibold);
    color: var(--sx-text);
    text-decoration: none;
    white-space: nowrap;
}
.store-sort a:hover { background: var(--sx-surface-alt); }
.store-sort a.active { background: var(--sx-dark); color: var(--sx-on-dark); }
.store-count { grid-column: 1 / -1; margin: 0; font-size: var(--sx-text-sm); color: var(--sx-muted); }

/* ---------- Mas siksik na grid (6 column sa malaking screen) ---------- */
.store-grid-dense { grid-template-columns: repeat(auto-fill, minmax(176px, 1fr)); gap: var(--sx-space-3); }
.store-grid-dense .store-card-desc { display: none; }
.store-grid-dense .store-card-body { padding: var(--sx-space-3); }
.store-grid-dense .store-card-title { font-size: var(--sx-text-sm); }
.store-grid-dense .store-price { font-size: var(--sx-text-base); color: var(--sx-primary); }
.store-grid-dense .store-add { width: 36px; height: 36px; }

/* ---------- Responsive ---------- */
@media (min-width: 768px) {
    .store-wide { padding-inline: var(--sx-space-6); }
    .store-toolbar { margin-inline: calc(-1 * var(--sx-space-6)); padding-inline: var(--sx-space-6); }
    .store-banner { padding: var(--sx-space-6) var(--sx-space-7); }
}
@media (min-width: 992px) {
    .store-banner { grid-template-columns: 1fr 440px; min-height: 280px; }
    .store-banner-art { display: block; }
}
@media (max-width: 575.98px) {
    .store-grid-dense { grid-template-columns: repeat(2, 1fr); }
    .store-sort > span { display: none; }
    .store-sort { overflow-x: auto; max-width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .store-banner-art a,
    .store-cat { transition: none; }
}
</style>

<div class="store-wide">

    <!-- Banner (compact hero) -->
    <section class="store-banner">
        <div class="store-banner-text">
            <span class="sx-eyebrow">Cleaning supplies for homes &amp; businesses</span>
            <h1>Clean spaces start with the <span>right supplies.</span></h1>
            <p>Chemicals, tools, mats and equipment — order per piece, or in bulk with a business purchase order.</p>
            <div class="store-banner-ctas">
                <a href="#products" class="sx-btn sx-btn-accent sx-btn-arrow">
                    Shop now <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </a>
                <?php if (!$isUserLoggedIn): ?>
                    <a href="/sweepxpress/register.php" class="sx-btn sx-btn-outline"><i class="fa-solid fa-building"></i> For businesses</a>
                <?php endif; ?>
            </div>
            <div class="store-banner-stats">
                <span><i class="fa-solid fa-boxes-stacked"></i> <?php echo $statInStock; ?> products in stock</span>
                <span><i class="fa-solid fa-file-invoice"></i> B2B purchase orders</span>
                <span><i class="fa-solid fa-money-bill-wave"></i> Cash on delivery</span>
            </div>
        </div>

        <?php if (count($heroProducts) >= 3): ?>
            <div class="store-banner-art" aria-hidden="true">
                <?php foreach ($heroProducts as $i => $hp): ?>
                    <a href="/sweepxpress/product.php?id=<?php echo (int) $hp['id']; ?>" tabindex="-1">
                        <img src="<?php echo h(trim($hp['image_path'])); ?>" alt=""
                             <?php echo $i === 1 ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
                             onerror="this.src='<?php echo h($placeholderImage); ?>'">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Category tiles -->
    <?php if ($categories): ?>
    <section class="store-section" aria-label="Categories">
        <div class="store-section-head"><h2>Categories</h2></div>
        <div class="store-cats">
            <a href="<?php echo h(store_url(['category' => ''])); ?>" class="store-cat category-btn <?php echo $selectedCategory === '' ? 'active' : ''; ?>">
                <i class="fa-solid fa-border-all"></i><strong>All products</strong><span><?php echo $totalProducts; ?> items</span>
            </a>
            <?php foreach ($categoryCounts as $cat => $n): ?>
                <a href="<?php echo h(store_url(['category' => $cat])); ?>" class="store-cat category-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>">
                    <i class="fa-solid <?php echo $categoryIcons[$cat] ?? 'fa-box'; ?>"></i>
                    <strong><?php echo h($cat); ?></strong>
                    <span><?php echo (int) $n; ?> item<?php echo (int) $n === 1 ? '' : 's'; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Best sellers (totoong benta) -->
    <?php if ($bestSellers && !$isFiltered): ?>
    <section class="store-section" aria-label="Best sellers">
        <div class="store-section-head">
            <h2><i class="fa-solid fa-fire" style="color: var(--sx-warning)"></i> Best sellers</h2>
            <a href="<?php echo h(store_url(['sort' => 'popular'])); ?>" class="sx-btn sx-btn-ghost sx-btn-sm">View all <i class="fa-solid fa-arrow-right"></i></a>
        </div>
        <div class="store-grid store-grid-dense">
            <?php foreach ($bestSellers as $p) sx_product_card($p, $isUserLoggedIn); ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Catalog -->
    <section class="store-section store-catalog" id="products">
        <div class="store-section-head">
            <h2>
                <?php if ($searchTerm !== ''): ?>
                    Results for “<?php echo h($searchTerm); ?>”
                <?php else: ?>
                    <?php echo $selectedCategory !== '' ? h($selectedCategory) : 'All products'; ?>
                <?php endif; ?>
            </h2>
            <?php if ($searchTerm !== ''): ?>
                <a href="<?php echo h(store_url(['search' => ''])); ?>" class="sx-btn sx-btn-ghost sx-btn-sm"><i class="fa-solid fa-xmark"></i> Clear search</a>
            <?php endif; ?>
        </div>

        <div class="store-toolbar">
            <?php if ($categories): ?>
            <div class="store-chips">
                <a href="<?php echo h(store_url(['category' => ''])); ?>" class="sx-chip category-btn <?php echo $selectedCategory === '' ? 'active' : ''; ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="<?php echo h(store_url(['category' => $cat])); ?>" class="sx-chip category-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>"><?php echo h($cat); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="store-sort" role="group" aria-label="Sort products">
                <span>Sort</span>
                <?php foreach ($sortOptions as $key => [$label]): ?>
                    <a href="<?php echo h(store_url(['sort' => $key === 'latest' ? '' : $key])); ?>" class="sort-btn <?php echo $sort === $key ? 'active' : ''; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pinapalitan ng AJAX filter ang laman nito (id="product-real") -->
        <div class="store-grid store-grid-dense" id="product-real">
            <?php if (empty($products)): ?>
                <div class="sx-card sx-empty">
                    <i class="fa-solid fa-box-open"></i>
                    <h3>No products found</h3>
                    <p style="margin: 0 0 var(--sx-space-4)">Try a different search or category.</p>
                    <a href="index.php#products" class="sx-btn sx-btn-outline sx-btn-sm">View all products</a>
                </div>
            <?php else: ?>
                <p class="store-count">
                    <?php echo count($products); ?> product<?php echo count($products) === 1 ? '' : 's'; ?>
                    · sorted by <?php echo strtolower($sortOptions[$sort][0]); ?>
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
       Sukatin ang totoong taas ng navbar (nagbabago kapag nag-scroll,
       at mas mataas sa mobile dahil sa search) → --sx-nav-h para sa sticky toolbar
    ----------------------------------------------------- */
    function trackNavHeight() {
        const nav = document.querySelector('.navbar');
        if (!nav) return;
        const set = () => {
            const h = nav.offsetHeight;
            document.documentElement.style.setProperty('--sx-nav-h', h + 'px');
            document.body.style.paddingTop = h + 'px';
        };
        set();
        if ('ResizeObserver' in window) new ResizeObserver(set).observe(nav);
        else window.addEventListener('resize', set);
    }

    /* -----------------------------------------------------
       Category / sort filter: kunin ang bagong listahan nang hindi
       nire-reload ang buong page, saka palitan ang laman ng #product-real
    ----------------------------------------------------- */
    function initFilterAjax() {
        const productReal = document.getElementById('product-real');
        if (!productReal) return;
        const selector = '.category-btn, .sort-btn';

        const syncActiveState = (sourceDoc) => {
            const activeHrefs = new Set(
                Array.from(sourceDoc.querySelectorAll('.category-btn.active, .sort-btn.active'))
                    .map((a) => a.getAttribute('href'))
            );
            document.querySelectorAll(selector).forEach((btn) => {
                btn.classList.toggle('active', activeHrefs.has(btn.getAttribute('href')));
            });
            // Bagong href ng mga chip/sort (pinapanatili ang napiling category at sort)
            const fresh = Array.from(sourceDoc.querySelectorAll(selector));
            document.querySelectorAll(selector).forEach((btn, i) => {
                if (fresh[i]) btn.setAttribute('href', fresh[i].getAttribute('href'));
            });
            const freshTitle = sourceDoc.querySelector('#products .store-section-head');
            const title = document.querySelector('#products .store-section-head');
            if (freshTitle && title) title.innerHTML = freshTitle.innerHTML;
        };

        const load = async (url, { pushState = true } = {}) => {
            productReal.classList.add('is-loading');
            try {
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                if (!res.ok) throw new Error(`Request failed with ${res.status}`);
                const freshDoc = new DOMParser().parseFromString(await res.text(), 'text/html');
                const freshProductReal = freshDoc.getElementById('product-real');
                if (!freshProductReal) throw new Error('Product list not found in response');

                productReal.innerHTML = freshProductReal.innerHTML;
                sxLazyImages(productReal);
                syncActiveState(freshDoc);
                if (pushState) history.pushState({ ajaxFilter: true }, '', url);
            } catch (err) {
                console.error('Filter failed, falling back to normal navigation:', err);
                window.location.href = url;
            } finally {
                productReal.classList.remove('is-loading');
            }
        };

        document.addEventListener('click', (e) => {
            const link = e.target.closest(selector);
            if (!link) return;
            e.preventDefault();
            load(link.href);
            document.getElementById('products').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        window.addEventListener('popstate', () => load(window.location.href, { pushState: false }));
    }

    document.addEventListener('DOMContentLoaded', () => {
        trackNavHeight();
        sxLazyImages();
        initFilterAjax();
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
