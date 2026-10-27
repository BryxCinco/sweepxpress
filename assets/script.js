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

// POST na may 15s timeout; error kapag hindi JSON ang sagot (hal. error page ng hosting)
function sxPostJson(url, body, timeoutMs = 15000) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs);
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
        signal: ctrl.signal,
        credentials: 'same-origin',
    })
        .then((res) => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
        .finally(() => clearTimeout(timer));
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

    sxPostJson('/sweepxpress/api/add_to_cart.php', `product_id=${encodeURIComponent(productId)}`)
        .then((data) => {
            if (data.success) {
                if (typeof updateCartBadge === 'function') updateCartBadge(data.count);
                sxToast('Added to cart', 'success');
            } else {
                sxToast(data.message || 'Failed to add to cart', 'error');
            }
        })
        .catch((err) => {
            console.error('Add to cart:', err);
            sxToast(err.name === 'AbortError' ? 'Server is slow — please try again' : 'Failed to add to cart', 'error');
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

    sxPostJson('/sweepxpress/api/buy_now.php', `product_id=${encodeURIComponent(productId)}`)
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
            console.error('Buy now:', err);
            sxToast(err.name === 'AbortError' ? 'Server is slow — please try again' : 'Something went wrong', 'error');
            if (btn) btn.disabled = false;
        });
}

/* -------------------------------
   Auth helpers (login, register, reset password)
   ------------------------------- */

// 6 na OTP box → isang hidden input. Sumusuporta sa pag-paste ng buong code.
function sxOtpBoxes(container, hiddenInput, submitBtn) {
    if (!container || !hiddenInput) return;
    const boxes = Array.from(container.querySelectorAll('input'));

    const sync = () => {
        const code = boxes.map(b => b.value).join('');
        hiddenInput.value = code;
        if (submitBtn) submitBtn.disabled = code.length !== boxes.length;
    };

    const fill = (from, digits) => {
        digits.split('').forEach((d, i) => { if (boxes[from + i]) boxes[from + i].value = d; });
        const next = Math.min(from + digits.length, boxes.length - 1);
        boxes[next].focus();
        sync();
    };

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            const digits = box.value.replace(/\D/g, '');
            box.value = '';
            if (digits) fill(i, digits); else sync();
        });
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && i > 0) {
                boxes[i - 1].value = '';
                boxes[i - 1].focus();
                sync();
                e.preventDefault();
            } else if (e.key === 'ArrowLeft' && i > 0) {
                boxes[i - 1].focus();
            } else if (e.key === 'ArrowRight' && i < boxes.length - 1) {
                boxes[i + 1].focus();
            }
        });
        box.addEventListener('paste', (e) => {
            const digits = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, boxes.length);
            if (!digits) return;
            e.preventDefault();
            fill(i, digits);
        });
    });

    boxes[0].focus();
    sync();
}

// Mga button na nagpapakita/nagtatago ng password: <button data-sx-toggle="input-id">
function sxPasswordToggles(scope = document) {
    scope.querySelectorAll('[data-sx-toggle]').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.sxToggle);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        });
    });
}

// Live na checklist ng password rules: <li data-rule="length|letter|number|special">
function sxPasswordRules(input, list) {
    if (!input || !list) return;
    const tests = {
        length:  v => v.length >= 10,
        letter:  v => /[A-Za-z]/.test(v),
        number:  v => /\d/.test(v),
        special: v => /[\W_]/.test(v),
    };
    const update = () => {
        list.querySelectorAll('[data-rule]').forEach(li => {
            const ok = tests[li.dataset.rule](input.value);
            li.classList.toggle('is-met', ok);
            li.querySelector('i').className = ok ? 'fa-solid fa-circle-check' : 'fa-regular fa-circle';
        });
    };
    input.addEventListener('input', update);
    update();
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

