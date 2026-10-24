<?php
// ============================================================
// SweepXpress - Footer Template (FIXED)
// ============================================================
?>
</main> <!-- Close the main tag opened in header.php -->

<footer class="modern-footer">
    <div class="footer-content">
        <!-- Top Section -->
        <div class="footer-top">
            <div class="footer-brand">
                <h2><span class="brand-accent">sweep</span>Xpress</h2>
                <p>Your trusted partner for premium cleaning supplies and equipment.</p>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="footer-grid">
            <!-- Products -->
            <div class="footer-col">
                <h4>Products</h4>
                <ul>
                    <li><a href="/sweepxpress/index.php?category=Cleaning+Supplies">Cleaning Supplies</a></li>
                    <li><a href="/sweepxpress/index.php?category=Tapes+%26+Adhesives">Tapes & Adhesives</a></li>
                    <li><a href="/sweepxpress/index.php?category=Equipment">Equipment</a></li>
                    <li><a href="/sweepxpress/index.php?category=Floor+Mats">Floor Mats</a></li>
                    <li><a href="/sweepxpress/index.php?category=Tools+%26+Accessories">Tools & Accessories</a></li>
                    <li><a href="/sweepxpress/index.php">View All</a></li>
                </ul>
            </div>

            <!-- Company -->
            <div class="footer-col">
                <h4>Company</h4>
                <ul>
                    <li><a href="/sweepxpress/about.php">About Us</a></li>
                    <li><a href="/sweepxpress/contact.php">Contact</a></li>
                    <li><a href="#">Careers</a></li>
                    <li><a href="#">Our Team</a></li>
                </ul>
            </div>

            <!-- Support -->
            <div class="footer-col">
                <h4>Support</h4>
                <ul>
                    <li><a href="/sweepxpress/privacy.php">Privacy Policy</a></li>
                    <li><a href="/sweepxpress/terms.php">Terms & Conditions</a></li>
                    <li><a href="#">FAQ</a></li>
                    <li><a href="#">Shipping Info</a></li>
                </ul>
            </div>

            <!-- Contact -->
            <div class="footer-col">
                <h4>Get in Touch</h4>
                <ul class="contact-list">
                    <li>
                        <i class="bi bi-telephone"></i>
                        <span>(63) 761-77232</span>
                    </li>
                    <li>
                        <i class="bi bi-envelope"></i>
                        <span>sweepxpress@gmail.com</span>
                    </li>
                </ul>
                <div class="social-links">
                    <a href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                    <a href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                    <a href="#" aria-label="Twitter"><i class="bi bi-twitter"></i></a>
                    <a href="#" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                </div>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> SweepXpress. All rights reserved.</p>
        </div>
    </div>
</footer>

<style>
    /* Modern Professional Footer */
    .modern-footer {
        background: #1a1a1a;
        color: #e5e5e5;
        padding: 4rem 0 0;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
        margin-top: auto;
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 2rem;
    }

    /* Top Section */
    .footer-top {
        padding-bottom: 3rem;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 3rem;
    }

    .footer-brand h2 {
        font-size: 1.75rem;
        font-weight: 700;
        color: #ffffff;
        margin-bottom: 0.75rem;
        letter-spacing: -0.02em;
    }

    .brand-accent {
        color: #3366ff;
    }

    .footer-brand p {
        font-size: 0.9375rem;
        color: #9ca3af;
        max-width: 400px;
        line-height: 1.6;
    }

    /* Grid Layout */
    .footer-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 3rem 2rem;
        margin-bottom: 3rem;
    }

    .footer-col h4 {
        font-size: 0.875rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #ffffff;
        margin-bottom: 1.25rem;
    }

    .footer-col ul {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .footer-col ul li {
        margin-bottom: 0.75rem;
    }

    .footer-col ul li a {
        color: #9ca3af;
        text-decoration: none;
        font-size: 0.9375rem;
        transition: all 0.2s ease;
        display: inline-block;
    }

    .footer-col ul li a:hover {
        color: #3366ff;
        transform: translateX(4px);
    }

    /* Contact List */
    .contact-list li {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        color: #9ca3af;
        font-size: 0.9375rem;
        margin-bottom: 0.75rem;
    }

    .contact-list i {
        color: #3366ff;
        font-size: 1rem;
        width: 20px;
    }

    /* Social Links */
    .social-links {
        display: flex;
        gap: 0.75rem;
        margin-top: 1.5rem;
    }

    .social-links a {
        width: 40px;
        height: 40px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #9ca3af;
        font-size: 1.125rem;
        transition: all 0.2s ease;
        text-decoration: none;
    }

    .social-links a:hover {
        background: #3366ff;
        border-color: #3366ff;
        color: #ffffff;
        transform: translateY(-2px);
    }

    /* Bottom Bar */
    .footer-bottom {
        padding: 1.5rem 0;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        text-align: center;
    }

    .footer-bottom p {
        color: #6b7280;
        font-size: 0.875rem;
        margin: 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .modern-footer {
            padding: 3rem 0 0;
        }

        .footer-content {
            padding: 0 1.5rem;
        }

        .footer-top {
            padding-bottom: 2rem;
            margin-bottom: 2rem;
        }

        .footer-grid {
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        .footer-brand h2 {
            font-size: 1.5rem;
        }
    }

    @media (max-width: 480px) {
        .social-links {
            justify-content: flex-start;
        }
    }
</style>

<script>
// Ensure any footer-specific JS runs here
document.addEventListener('DOMContentLoaded', function() {
    console.log('SweepXpress footer loaded');
});
</script>
</body>
</html>