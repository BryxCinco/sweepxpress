<?php
// =================================================================================================
// ABOUT (Phase 9b) — brand story + live catalog numbers; walang gawa-gawang bilang o testimonial
// =================================================================================================
require_once __DIR__ . '/includes/header.php';

$company_name = "SweepXpress";
$mission = "Our mission is to empower every individual and business to create a spotless, healthy, and vibrant environment. We achieve this by meticulously curating and supplying the most effective and innovative cleaning tools available.";
$vision = "To be the Philippines' most trusted and leading provider of high-quality cleaning supplies, known for our commitment to customer satisfaction and environmental sustainability.";

// Live na bilang mula sa catalog
try {
    $statProducts   = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $statCategories = (int) $pdo->query("SELECT COUNT(DISTINCT category) FROM products WHERE category IS NOT NULL AND category <> ''")->fetchColumn();
    $statInStock    = (int) $pdo->query("SELECT COUNT(DISTINCT product_id) FROM inventory WHERE quantity > 0")->fetchColumn();
} catch (PDOException $e) {
    $statProducts = $statCategories = $statInStock = 0;
}

// Ang totoong team na gumawa ng platform
$team_members = [
    ['name' => 'Brian Paul Francisco', 'role' => 'Full Stack Developer / Team Lead'],
    ['name' => 'Ian Barquilla',        'role' => 'Backend Developer'],
    ['name' => 'Kenneth Monforte',     'role' => 'Front-End Developer'],
    ['name' => 'Jaybee Arca',          'role' => 'Documentation'],
    ['name' => 'Vladimir Amplayo',     'role' => 'Documentation'],
];

// Unang titik ng una at huling pangalan, hal. "Brian Paul Francisco" → "BF"
function about_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
}

$features = [
    ['fa-boxes-stacked', 'Real-time inventory', 'Stock levels update with every order, so what you see is what we have.'],
    ['fa-file-invoice',  'Retail & business ordering', 'Shop item by item, or send a purchase order with payment terms.'],
    ['fa-truck-fast',    'Order tracking', 'Follow each order from preparing to delivered, with email updates.'],
    ['fa-money-bill-wave', 'Cash on delivery', 'Pay when your order arrives at your door.'],
];

$values = [
    ['fa-gem',             'Uncompromising quality', 'Only tools that pass performance and durability checks make it to our shelves.'],
    ['fa-leaf',            'Environmental care', 'Pushing for safer, lower-impact solutions in every new product line.'],
    ['fa-handshake',       'Customer-first', 'We solve for your use case, not just push products.'],
    ['fa-arrows-rotate',   'Continuous improvement', 'Always scouting and testing better cleaning tools.'],
];

// PHP helper for safely outputting HTML content
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
?>

<style>
/* ============================================================
   ABOUT (Phase 9b) — tokens mula sa assets/style.css
   ============================================================ */
.ab { padding-block: var(--sx-space-5) var(--sx-space-8); }
.ab-section { padding-top: var(--sx-space-8); }
.ab-head { max-width: 640px; margin-bottom: var(--sx-space-6); }
.ab-head p { margin: var(--sx-space-3) 0 0; color: var(--sx-muted); }

/* Hero */
.ab-hero {
    display: grid;
    gap: var(--sx-space-6);
    align-items: center;
    padding: var(--sx-space-7) var(--sx-space-5);
    border: 1px solid var(--sx-border-light);
    border-radius: var(--sx-radius-xl);
    background:
        radial-gradient(circle at 10% 15%, var(--sx-primary-soft) 0%, transparent 45%),
        radial-gradient(circle at 90% 90%, var(--sx-accent-soft) 0%, transparent 45%),
        var(--sx-surface);
}
.ab-hero h1 { margin-bottom: var(--sx-space-4); }
.ab-hero .sx-lead { max-width: 540px; margin: 0 0 var(--sx-space-5); }
.ab-ctas { display: flex; flex-wrap: wrap; gap: var(--sx-space-3); }

/* Brand panel: logo + live stats */
.ab-panel {
    position: relative;
    display: grid;
    gap: var(--sx-space-4);
    padding: var(--sx-space-6);
    border-radius: var(--sx-radius-xl);
    background: var(--sx-gradient-brand);
    color: #fff;
    overflow: hidden;
    box-shadow: var(--sx-shadow-lg);
}
.ab-panel::after {                       /* malambot na mint glow */
    content: "";
    position: absolute;
    right: -90px;
    bottom: -90px;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(110, 231, 183, 0.35) 0%, transparent 70%);
}
.ab-panel .sx-logo-mark { width: 64px; height: 64px; font-size: 2rem; border-radius: 18px; background: rgba(255, 255, 255, 0.15); box-shadow: none; }
.ab-panel-title { margin: 0; font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); letter-spacing: var(--sx-tracking-tight); }
.ab-panel-text { margin: 0; color: rgba(255, 255, 255, 0.8); font-size: var(--sx-text-sm); }
.ab-stats { position: relative; z-index: 1; display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--sx-space-3); }
.ab-stat {
    padding: var(--sx-space-3);
    border-radius: var(--sx-radius-md);
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}
.ab-stat strong { display: block; font-size: var(--sx-text-2xl); font-weight: var(--sx-weight-extrabold); line-height: 1.1; }
.ab-stat span { font-size: var(--sx-text-xs); color: rgba(255, 255, 255, 0.8); }

/* Grids */
.ab-grid-2,
.ab-grid-4,
.ab-team { display: grid; gap: var(--sx-space-4); }
.ab-story p { margin: 0 0 var(--sx-space-4); line-height: 1.7; color: var(--sx-text); }
.ab-story p:last-child { margin-bottom: 0; }

.ab-mv-label {
    display: inline-flex;
    align-items: center;
    gap: var(--sx-space-2);
    margin-bottom: var(--sx-space-3);
    font-size: var(--sx-text-xs);
    font-weight: var(--sx-weight-bold);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--sx-accent-dark);
}
.ab-mv-card h3 { margin: 0 0 var(--sx-space-3); font-size: var(--sx-text-xl); font-weight: var(--sx-weight-extrabold); color: var(--sx-dark); }
.ab-mv-card p { margin: 0; line-height: 1.7; color: var(--sx-muted); }

.ab-value .sx-feature-icon { margin-bottom: var(--sx-space-3); }
.ab-value h3 { margin: 0 0 var(--sx-space-2); font-size: var(--sx-text-base); font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.ab-value p { margin: 0; font-size: var(--sx-text-sm); color: var(--sx-muted); }

/* Team */
.ab-member { display: flex; align-items: center; gap: var(--sx-space-3); }
.ab-avatar {
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: var(--sx-gradient-brand);
    color: #fff;
    font-weight: var(--sx-weight-extrabold);
    letter-spacing: 0.02em;
}
.ab-member.is-lead .ab-avatar { box-shadow: 0 0 0 3px var(--sx-surface), 0 0 0 5px var(--sx-accent); }
.ab-member h3 { margin: 0; font-size: var(--sx-text-base); font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.ab-member p { margin: 2px 0 0; font-size: var(--sx-text-sm); color: var(--sx-muted); }

/* Final CTA */
.ab-cta {
    display: grid;
    gap: var(--sx-space-4);
    justify-items: start;
    padding: var(--sx-space-7) var(--sx-space-5);
    border-radius: var(--sx-radius-xl);
    background: var(--sx-footer-bg);
    color: var(--sx-footer-text);
}
.ab-cta h2 { margin: 0; color: #fff; }
.ab-cta p { margin: 0; max-width: 520px; color: var(--sx-footer-muted); }
.ab-cta .sx-btn-outline { background: transparent; color: #fff; border-color: rgba(255, 255, 255, 0.25); }
.ab-cta .sx-btn-outline:hover { border-color: #fff; color: #fff; }

@media (min-width: 768px) {
    .ab-grid-2 { grid-template-columns: repeat(2, 1fr); }
    .ab-grid-4 { grid-template-columns: repeat(2, 1fr); }
    .ab-team { grid-template-columns: repeat(2, 1fr); }
}
@media (min-width: 992px) {
    .ab-hero { grid-template-columns: 1.15fr 1fr; padding: var(--sx-space-8) var(--sx-space-7); gap: var(--sx-space-8); }
    .ab-grid-4 { grid-template-columns: repeat(4, 1fr); }
    .ab-team { grid-template-columns: repeat(3, 1fr); }
    .ab-cta { grid-template-columns: 1fr auto; align-items: center; padding: var(--sx-space-7); }
}
@media (max-width: 575.98px) {
    .ab-hero { padding: var(--sx-space-6) var(--sx-space-4); }
    .ab-stat strong { font-size: var(--sx-text-xl); }
}
</style>

<div class="ab sx-container">

    <!-- Hero -->
    <section class="ab-hero">
        <div>
            <span class="sx-eyebrow">About <?php echo h($company_name); ?></span>
            <h1 class="sx-heading-xl">Cleaning supplies, from our warehouse to your door.</h1>
            <p class="sx-lead">We make professional-grade cleaning tools easy to order for every Filipino home and business — one item at a time, or in bulk.</p>
            <div class="ab-ctas">
                <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-dark sx-btn-lg sx-btn-arrow">
                    Shop Now <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </a>
                <a href="/sweepxpress/contact.php" class="sx-btn sx-btn-outline sx-btn-lg"><i class="fa-solid fa-envelope"></i> Contact us</a>
            </div>
        </div>

        <aside class="ab-panel" aria-label="Catalog at a glance">
            <span class="sx-logo-mark" aria-hidden="true">X</span>
            <div>
                <p class="ab-panel-title">Our catalog today</p>
                <p class="ab-panel-text">Live numbers from our store.</p>
            </div>
            <div class="ab-stats">
                <div class="ab-stat"><strong><?php echo $statProducts; ?></strong><span>Products</span></div>
                <div class="ab-stat"><strong><?php echo $statCategories; ?></strong><span>Categories</span></div>
                <div class="ab-stat"><strong><?php echo $statInStock; ?></strong><span>In stock</span></div>
            </div>
        </aside>
    </section>

    <!-- Who we are -->
    <section class="ab-section">
        <div class="ab-grid-2">
            <div class="ab-head">
                <span class="sx-eyebrow">Who we are</span>
                <h2 class="sx-heading-lg">A local brand with a professional standard for clean.</h2>
            </div>
            <div class="ab-story">
                <p><?php echo h($company_name); ?> exists to close the gap between everyday cleaning and professional-grade results, making high-performing tools accessible to every Filipino home and business.</p>
                <p>From hotels and offices to daycares and households, we listen to the people who clean every day and shape our catalog around what actually works for them.</p>
            </div>
        </div>
    </section>

    <!-- What we offer (totoong features ng platform) -->
    <section class="ab-section">
        <div class="ab-head">
            <span class="sx-eyebrow">How we work</span>
            <h2 class="sx-heading-lg">Built for homes and businesses</h2>
            <p>Everything you need to stock up — whether you're cleaning one room or supplying a whole team.</p>
        </div>
        <div class="ab-grid-4">
            <?php foreach ($features as [$icon, $title, $text]): ?>
                <div class="sx-card ab-value">
                    <div class="sx-feature-icon"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                    <h3><?php echo h($title); ?></h3>
                    <p><?php echo h($text); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Mission & Vision -->
    <section class="ab-section" id="mission-vision">
        <div class="ab-head">
            <span class="sx-eyebrow">Direction</span>
            <h2 class="sx-heading-lg">Mission and vision that guide us</h2>
        </div>
        <div class="ab-grid-2">
            <article class="sx-card ab-mv-card">
                <span class="ab-mv-label"><i class="fa-solid fa-bullseye"></i> Our mission</span>
                <h3>Empowering spotless, healthy spaces</h3>
                <p><?php echo h($mission); ?></p>
            </article>
            <article class="sx-card ab-mv-card">
                <span class="ab-mv-label"><i class="fa-solid fa-eye"></i> Our vision</span>
                <h3>The most trusted cleaning partner in the Philippines</h3>
                <p><?php echo h($vision); ?></p>
            </article>
        </div>
    </section>

    <!-- Values -->
    <section class="ab-section">
        <div class="ab-head">
            <span class="sx-eyebrow">Values</span>
            <h2 class="sx-heading-lg">What keeps us grounded</h2>
        </div>
        <div class="ab-grid-4">
            <?php foreach ($values as [$icon, $title, $text]): ?>
                <div class="sx-card ab-value">
                    <div class="sx-feature-icon"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                    <h3><?php echo h($title); ?></h3>
                    <p><?php echo h($text); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Team -->
    <section class="ab-section">
        <div class="ab-head">
            <span class="sx-eyebrow">The team</span>
            <h2 class="sx-heading-lg">The people who built <?php echo h($company_name); ?></h2>
            <p>Designed, developed and documented by a five-person team.</p>
        </div>
        <div class="ab-team">
            <?php foreach ($team_members as $i => $member): ?>
                <div class="sx-card ab-member <?php echo $i === 0 ? 'is-lead' : ''; ?>">
                    <span class="ab-avatar" aria-hidden="true"><?php echo h(about_initials($member['name'])); ?></span>
                    <div>
                        <h3><?php echo h($member['name']); ?></h3>
                        <p><?php echo h($member['role']); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Final CTA -->
    <section class="ab-section">
        <div class="ab-cta">
            <div>
                <h2 class="sx-heading-lg">Ready to stock up?</h2>
                <p>Browse the catalog, or create a business account to order in bulk with a purchase order.</p>
            </div>
            <div class="ab-ctas">
                <a href="/sweepxpress/index.php#products" class="sx-btn sx-btn-accent sx-btn-lg sx-btn-arrow">
                    Shop Now <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span>
                </a>
                <?php if (!is_logged_in()): ?>
                    <a href="/sweepxpress/register.php" class="sx-btn sx-btn-outline sx-btn-lg"><i class="fa-solid fa-building"></i> For Businesses</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
