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

