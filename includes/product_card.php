<?php
// includes/product_card.php — iisang product card para sa storefront (index.php)
// at "related products" (product.php). Styles: .store-card sa assets/style.css.
// Kailangan ng row na may id, name, price, category, description, image_path, stock_quantity.

if (!function_exists('sx_product_card')) {
    function sx_product_card(array $p, bool $isLoggedIn): void
    {
        $placeholder = '/sweepxpress/assets/placeholder.jpg';
        $lowStock    = 10; // pareho sa admin/inventory.php

        $stockQty  = (int) ($p['stock_quantity'] ?? 0);
        $isInStock = $stockQty > 0;
        $imageUrl  = !empty($p['image_path']) ? trim($p['image_path']) : $placeholder;
        $detailUrl = '/sweepxpress/product.php?id=' . (int) $p['id'];
        $name      = htmlspecialchars($p['name'] ?? '', ENT_QUOTES, 'UTF-8');
        ?>
        <article class="store-card <?php echo $isInStock ? '' : 'is-out'; ?>">
            <a href="<?php echo $detailUrl; ?>" class="store-card-media" tabindex="-1" aria-hidden="true">
                <img
                    data-src="<?php echo htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3C/svg%3E"
                    alt=""
                    loading="lazy"
                    onerror="this.src='<?php echo $placeholder; ?>'; this.classList.add('loaded');">
                <?php if (!empty($p['category'])): ?>
                    <span class="store-tag"><?php echo htmlspecialchars($p['category'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if (!$isInStock): ?>
                    <span class="store-stock out">Out of stock</span>
                <?php elseif ($stockQty <= $lowStock): ?>
                    <span class="store-stock low"><?php echo $stockQty; ?> left</span>
                <?php else: ?>
                    <span class="store-stock in">In stock</span>
                <?php endif; ?>
            </a>
            <div class="store-card-body">
                <h3 class="store-card-title">
                    <a href="<?php echo $detailUrl; ?>"><?php echo $name; ?></a>
                </h3>
                <p class="store-card-desc"><?php echo htmlspecialchars($p['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="store-card-foot">
                    <span class="store-price">₱<?php echo number_format((float) $p['price'], 2); ?></span>
                    <?php if (!$isInStock): ?>
                        <button type="button" class="store-add" disabled aria-label="Out of stock"><i class="fa-solid fa-ban"></i></button>
                    <?php elseif ($isLoggedIn): ?>
                        <button type="button" class="store-add" onclick="sxAddToCart(<?php echo (int) $p['id']; ?>, this)"
                                aria-label="Add <?php echo $name; ?> to cart"><i class="fa-solid fa-cart-plus"></i></button>
                    <?php else: ?>
                        <a href="/sweepxpress/login.php" class="store-add" aria-label="Log in to add to cart"><i class="fa-solid fa-cart-plus"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php
    }
}
