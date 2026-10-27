/* ================================
   SweepXpress JavaScript
   ================================ */

/* -------------------------------
   Add to Cart with counter update
   ------------------------------- */
function addToCart(productId) {
    fetch('/sweepxpress/api/add_to_cart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'product_id=' + productId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let cartCountElem = document.getElementById('cart-count');
            if (cartCountElem) {
                let currentCount = parseInt(cartCountElem.innerText) || 0;
                cartCountElem.innerText = currentCount + 1;
            }
            alert("Product added to cart!");
        } else {
            alert("Failed to add to cart!");
        }
    })
    .catch(err => console.error(err));
}

/* -------------------------------
   Toast notification (kapalit ng alert())
   sxToast('Added to cart', 'success' | 'error')
   ------------------------------- */
function sxToast(message, type = 'success') {
    let stack = document.querySelector('.sx-toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'sx-toast-stack';
        stack.setAttribute('role', 'status');
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = `sx-toast sx-toast-${type}`;
    const icon = document.createElement('i');
    icon.className = type === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check';
    const text = document.createElement('span');
    text.textContent = message;
    toast.append(icon, text);
    stack.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('is-leaving');
        setTimeout(() => toast.remove(), 200);
    }, 2600);
}

/* -------------------------------
   Lazy-load ng mga larawan na may data-src (product cards)
   ------------------------------- */
function sxLazyImages(scope = document) {
    const PLACEHOLDER = '/sweepxpress/assets/placeholder.jpg';
    const images = scope.querySelectorAll('img[data-src]');

    const loadImage = (img) => {
        const src = img.dataset.src;
        img.removeAttribute('data-src');

        if (!src || src === 'null' || src === 'undefined') {
            img.src = PLACEHOLDER;
            img.classList.add('loaded');
            return;
        }

        const preload = new Image();
        preload.onload = () => { img.src = src; img.classList.add('loaded'); };
        preload.onerror = () => { img.src = PLACEHOLDER; img.classList.add('loaded'); };
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
    }, { rootMargin: '200px' });

    images.forEach((img) => observer.observe(img));
}

/* -------------------------------
   Add to cart (product cards, product page)
   "sx" prefix: hiwalay sa lumang addToCart() sa itaas
   ------------------------------- */
function sxAddToCart(productId, btn) {
    const icon = btn ? btn.querySelector('i') : null;
    const iconClass = icon ? icon.className : '';
    if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
    if (icon) icon.className = 'fa-solid fa-spinner';

    fetch(`${window.location.origin}/sweepxpress/api/add_to_cart.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `product_id=${encodeURIComponent(productId)}`,
    })
        .then((res) => res.json())
        .then((data) => {
            if (data.success) {
                if (typeof updateCartBadge === 'function') updateCartBadge(data.count);
                sxToast('Added to cart', 'success');
            } else {
                sxToast(data.message || 'Failed to add to cart', 'error');
            }
        })
        .catch((err) => {
            console.error('Error:', err);
            sxToast('Failed to add to cart', 'error');
        })
        .finally(() => {
            if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); }
            if (icon) icon.className = iconClass;
        });
}

/* -------------------------------
   Buy now: itakda muna ang item sa session (api/buy_now.php),
   saka lumipat sa checkout — hindi sabay, para walang lumang item
   ------------------------------- */
function sxBuyNow(productId, btn) {
    if (btn) btn.disabled = true;

    fetch(`${window.location.origin}/sweepxpress/api/buy_now.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `product_id=${encodeURIComponent(productId)}`,
    })
        .then((res) => res.json())
        .then((data) => {
            if (data.status === 'success') {
                window.location.href = data.redirect;
                return;
            }
            sxToast(data.message || 'Something went wrong', 'error');
            if (data.redirect) setTimeout(() => { window.location.href = data.redirect; }, 1200);
            if (btn) btn.disabled = false;
        })
        .catch((err) => {
            console.error('Error:', err);
            sxToast('Something went wrong', 'error');
            if (btn) btn.disabled = false;
        });
}

/* -------------------------------
   Sidebar Toggle
   ------------------------------- */
document.addEventListener("DOMContentLoaded", () => {
    const sidebar = document.getElementById("sidebar");
    const toggleBtn = document.getElementById("sidebarToggle");
    const closeBtn = document.getElementById("closeSidebar");

    if (toggleBtn) {
        toggleBtn.addEventListener("click", () => {
            if (sidebar) sidebar.classList.add("active");
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener("click", () => {
            if (sidebar) sidebar.classList.remove("active");
        });
    }

    // Close sidebar if user clicks outside
    document.addEventListener("click", (e) => {
        if (
            sidebar &&
            sidebar.classList.contains("active") &&
            !sidebar.contains(e.target) &&
            e.target !== toggleBtn
        ) {
            sidebar.classList.remove("active");
        }
    });
});

