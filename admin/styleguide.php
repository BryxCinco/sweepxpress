<?php
/**
 * STYLEGUIDE (Phase 6d) — lahat ng sx- components sa iisang page.
 * Admin lang; reference para sa paglipat ng mga page sa Phase 9.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config.php';

if (!is_admin()) {
    header("Location: /sweepxpress/login.php");
    exit;
}

// Totoong product image para sa media/badge demo
$sample = $pdo->query("SELECT name, image_path FROM products WHERE COALESCE(image_path, '') <> '' ORDER BY id LIMIT 1")->fetch();

$colors = [
    'primary', 'primary-light', 'primary-soft',
    'accent', 'accent-dark', 'accent-light', 'accent-soft',
    'dark', 'text', 'muted', 'border', 'border-light', 'bg', 'surface', 'surface-alt',
    'success', 'warning', 'danger', 'info',
];
$gradients = ['brand', 'wordmark', 'x', 'arrow'];
$statuses = ['pending', 'preparing', 'shipped', 'delivered', 'completed', 'cancelled'];

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* Layout ng styleguide lang (sg-); ang mga component ay galing sa style.css (sx-) */
.sg-block { margin-bottom: var(--sx-space-7); }
.sg-title { margin: 0 0 var(--sx-space-4); font-size: var(--sx-text-lg); font-weight: var(--sx-weight-bold); color: var(--sx-dark); }
.sg-row { display: flex; flex-wrap: wrap; gap: var(--sx-space-3); align-items: center; }
.sg-grid { display: grid; gap: var(--sx-space-4); grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
.sg-swatch { height: 64px; border-radius: var(--sx-radius-md); border: 1px solid var(--sx-border); }
.sg-code { font-family: var(--sx-font-mono); font-size: var(--sx-text-xs); color: var(--sx-muted); }
</style>

<main class="sx-container sx-section">

    <header class="sx-page-header">
        <div>
            <span class="sx-eyebrow">Phase 6 · Design Foundation</span>
            <h1 class="sx-page-title">SweepXpress Styleguide</h1>
            <p class="sx-page-subtitle">Lahat ng <code>sx-</code> components at design tokens sa iisang lugar.</p>
        </div>
        <div class="sg-row">
            <!-- Dark mode: gamitin ang buwan/araw sa navbar (site-wide na mula 9h) -->
            <a href="dashboard.php" class="sx-btn sx-btn-outline"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
        </div>
    </header>

    <!-- 1. Colors -->
    <section class="sg-block">
        <h2 class="sg-title">Colors</h2>
        <div class="sg-grid">
            <?php foreach ($colors as $c): ?>
                <div>
                    <div class="sg-swatch" style="background: var(--sx-<?= $c ?>)"></div>
                    <div class="sg-code">--sx-<?= $c ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="sg-grid" style="margin-top: var(--sx-space-4)">
            <?php foreach ($gradients as $g): ?>
                <div>
                    <div class="sg-swatch" style="background: var(--sx-gradient-<?= $g ?>)"></div>
                    <div class="sg-code">--sx-gradient-<?= $g ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 2. Wordmark -->
    <section class="sg-block">
        <h2 class="sg-title">Wordmark</h2>
        <div class="sx-card">
            <span class="sx-wordmark" style="font-size: var(--sx-text-4xl)">Sweep<span class="sx-wordmark-x">X</span>press</span>
            <p class="sx-feature-text" style="margin-top: var(--sx-space-2)">Itapat ang mouse — dumudulas pataas ang gradient.</p>
        </div>
    </section>

    <!-- 3. Typography -->
    <section class="sg-block">
        <h2 class="sg-title">Typography</h2>
        <div class="sx-card">
            <span class="sx-eyebrow">About Us</span>
            <h1 class="sx-heading-xl">A Clean Space, A Fresh Start</h1>
            <h2 class="sx-heading-lg" style="margin-top: var(--sx-space-5)">Supplies for Homes and Businesses</h2>
            <h3 class="sx-heading-md" style="margin-top: var(--sx-space-4)">Featured Products</h3>
            <p class="sx-lead" style="margin-top: var(--sx-space-4)">Lead paragraph — mas malaki at malambot na kulay para sa intro ng section.</p>
            <p style="margin: 0">Body text — Plus Jakarta Sans, 1rem, line-height 1.5.</p>
        </div>
    </section>

    <!-- 4. Buttons -->
    <section class="sg-block">
        <h2 class="sg-title">Buttons</h2>
        <div class="sg-row">
            <button class="sx-btn sx-btn-primary">Primary</button>
            <button class="sx-btn sx-btn-accent">Accent</button>
            <button class="sx-btn sx-btn-dark">Dark</button>
            <button class="sx-btn sx-btn-outline">Outline</button>
            <button class="sx-btn sx-btn-ghost">Ghost</button>
            <button class="sx-btn sx-btn-danger">Danger</button>
            <button class="sx-btn sx-btn-primary" disabled>Disabled</button>
        </div>
        <div class="sg-row" style="margin-top: var(--sx-space-4)">
            <button class="sx-btn sx-btn-dark sx-btn-sm">Small</button>
            <button class="sx-btn sx-btn-dark">Default</button>
            <button class="sx-btn sx-btn-dark sx-btn-lg">Large</button>
            <button class="sx-btn sx-btn-primary"><i class="fa-solid fa-cart-plus"></i> With icon</button>
        </div>
        <div class="sg-row" style="margin-top: var(--sx-space-4)">
            <a href="#" class="sx-btn sx-btn-dark sx-btn-arrow">Shop Now <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span></a>
            <a href="#" class="sx-btn sx-btn-primary sx-btn-arrow">Order in Bulk <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span></a>
            <a href="#" class="sx-btn sx-btn-accent sx-btn-arrow">Get Started <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span></a>
            <a href="#" class="sx-btn sx-btn-outline sx-btn-arrow">Explore Us <span class="sx-btn-icon"><i class="fa-solid fa-arrow-right"></i></span></a>
        </div>
    </section>

    <!-- 5. Cards -->
    <section class="sg-block">
        <h2 class="sg-title">Cards</h2>
        <div class="sg-grid">
            <div class="sx-card sx-stat">
                <span class="sx-stat-label">Total Valuation</span>
                <span class="sx-stat-value">₱128,450.00</span>
                <span class="sx-stat-meta">26 products</span>
            </div>
            <div class="sx-card sx-stat">
                <span class="sx-stat-label">Needs Reorder</span>
                <span class="sx-stat-value">4</span>
                <span class="sx-stat-meta">1 out of stock</span>
            </div>
            <div class="sx-card sx-card-hover">
                <h3 class="sx-heading-md" style="font-size: var(--sx-text-lg)">Hover card</h3>
                <p class="sx-feature-text" style="margin-top: var(--sx-space-2)">Itapat ang mouse — umaangat nang kaunti.</p>
            </div>
        </div>

        <div class="sg-grid" style="margin-top: var(--sx-space-4)">
            <div class="sx-media" style="aspect-ratio: 4 / 3; background: var(--sx-surface-alt)">
                <?php if ($sample): ?>
                    <img src="<?= h($sample['image_path']) ?>" alt="<?= h($sample['name']) ?>">
                <?php endif; ?>
                <div class="sx-badge-float sx-badge-float-dark sx-pos-tr">
                    <strong>100+</strong><span>Products in stock</span>
                </div>
                <div class="sx-badge-float sx-pos-bl">
                    <strong><i class="fa-solid fa-star" style="color: var(--sx-warning)"></i> 4.9/5.0</strong><span>Trusted by businesses</span>
                </div>
            </div>
            <div class="sx-card" style="display: grid; gap: var(--sx-space-4); align-content: start">
                <div class="sx-feature">
                    <div class="sx-feature-icon"><i class="fa-solid fa-truck-fast"></i></div>
                    <div><p class="sx-feature-title">On-Time Delivery</p><p class="sx-feature-text">Nationwide, tracked from warehouse to door.</p></div>
                </div>
                <div class="sx-feature">
                    <div class="sx-feature-icon"><i class="fa-solid fa-leaf"></i></div>
                    <div><p class="sx-feature-title">Eco-Friendly Products</p><p class="sx-feature-text">Safe for homes, offices, and daycares.</p></div>
                </div>
                <div class="sx-feature">
                    <div class="sx-feature-icon"><i class="fa-solid fa-file-invoice"></i></div>
                    <div><p class="sx-feature-title">B2B Purchase Orders</p><p class="sx-feature-text">Bulk ordering with payment terms.</p></div>
                </div>
            </div>
        </div>

        <div class="sg-row" style="margin-top: var(--sx-space-4)">
            <a href="#" class="sx-chip is-active"><i class="fa-solid fa-border-all"></i> All</a>
            <a href="#" class="sx-chip"><i class="fa-solid fa-spray-can-sparkles"></i> Cleaners</a>
            <a href="#" class="sx-chip"><i class="fa-solid fa-broom"></i> Tools</a>
            <a href="#" class="sx-chip"><i class="fa-solid fa-box"></i> Packaging</a>
        </div>
    </section>

    <!-- 6. Forms -->
    <section class="sg-block">
        <h2 class="sg-title">Forms</h2>
        <form class="sx-card" style="max-width: 560px" onsubmit="return false">
            <div class="sx-field">
                <label class="sx-label" for="sg-name">Full name</label>
                <input class="sx-input" id="sg-name" type="text" placeholder="Juan Dela Cruz">
                <span class="sx-hint">Gaya ng nasa valid ID.</span>
            </div>
            <div class="sx-field is-invalid">
                <label class="sx-label" for="sg-email">Email</label>
                <input class="sx-input" id="sg-email" type="email" value="juan@">
                <span class="sx-error">Please enter a valid email address.</span>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="sg-type">Order type</label>
                <select class="sx-select" id="sg-type">
                    <option>B2C — Personal</option>
                    <option>B2B — Business</option>
                </select>
            </div>
            <div class="sx-field">
                <label class="sx-label" for="sg-notes">Notes</label>
                <textarea class="sx-textarea" id="sg-notes" placeholder="Delivery instructions..."></textarea>
            </div>
            <label class="sx-check"><input type="checkbox" checked> Save this address</label>
            <div class="sg-row" style="margin-top: var(--sx-space-5)">
                <button class="sx-btn sx-btn-primary">Save</button>
                <button class="sx-btn sx-btn-ghost" type="reset">Cancel</button>
            </div>
        </form>
    </section>

    <!-- 7. Table + status pills (paliitin ang window para makita ang card view) -->
    <section class="sg-block">
        <h2 class="sg-title">Table & Status</h2>
        <div class="sx-table-wrap sx-stack">
            <table class="sx-table">
                <thead>
                    <tr><th>Order</th><th>Customer</th><th>Date</th><th class="sx-num">Total</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($statuses as $i => $s): ?>
                        <tr>
                            <td data-label="Order">#<?= 1040 + $i ?></td>
                            <td data-label="Customer">Sample Customer <?= $i + 1 ?></td>
                            <td data-label="Date"><?= date('M j, Y', strtotime("-$i days")) ?></td>
                            <td data-label="Total" class="sx-num">₱<?= number_format(1250 + $i * 375, 2) ?></td>
                            <td data-label="Status"><span class="sx-pill sx-pill-<?= $s ?>"><?= $s ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 8. Empty state -->
    <section class="sg-block">
        <h2 class="sg-title">Empty state</h2>
        <div class="sx-card sx-empty">
            <i class="fa-solid fa-box-open"></i>
            <h3>No products found</h3>
            <p style="margin: 0 0 var(--sx-space-4)">Try a different search or filter.</p>
            <a href="#" class="sx-btn sx-btn-outline sx-btn-sm">Clear filters</a>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
