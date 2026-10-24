<?php
// =================================================================================================
// 1. PHP INCLUDES AND LOGIC
// =================================================================================================
require_once __DIR__ . '/includes/header.php';

// Application Data Variables
$company_name = "SweepXpress";
$established_year = 2020;
$mission = "Our mission is to empower every individual and business to create a spotless, healthy, and vibrant environment. We achieve this by meticulously curating and supplying the most effective and innovative cleaning tools available.";
$vision = "To be the Philippines' most trusted and leading provider of high-quality cleaning supplies, known for our commitment to customer satisfaction and environmental sustainability.";
$about_banner_image_path = '/sweepxpress/assets/Banner1.jpg';

// Team Data Structure
$team_members = [
    ['name' => 'Brian Sebastian Montero', 'role' => 'Founder & CEO', 'image' => '/SWEEPXPRESS/assets/D2.jpg', 'bio' => 'Visionary leader with 15+ years in supply chain excellence'],
    ['name' => 'Joanna Elizabeth Cervantes', 'role' => 'Head of Operations', 'image' => '/SWEEPXPRESS/assets/D4.jpg', 'bio' => 'Operations expert ensuring seamless delivery nationwide'],
    ['name' => 'Kristine Serena Lopez', 'role' => 'Product Director', 'image' => '/SWEEPXPRESS/assets/D1.jpg', 'bio' => 'Curating the finest eco-friendly cleaning innovations'],
    ['name' => 'Ernesto Jake Esteban', 'role' => 'Customer Success Lead', 'image' => '/SWEEPXPRESS/assets/D8.jpg', 'bio' => 'Championing exceptional customer experiences daily']
];

// Testimonial Data Structure
$testimonials = [
    ['name' => 'Jaybee Samuel Alcantara', 'company' => 'Green Valley Hotels', 'comment' => 'SweepXpress transformed our housekeeping operations. Their premium supplies and expert guidance helped us achieve 5-star cleanliness standards across all properties.', 'avatar' => '/sweepxpress/assets/D6.jpg'],
    ['name' => 'Kennendy Monteforte ', 'company' => 'Metro Cleaning Services', 'comment' => 'The quality-to-price ratio is unmatched. We\'ve equipped our entire 50-person team with their products and seen 40% improvement in efficiency.', 'avatar' => '/sweepxpress/assets/D7.jpg'],
    ['name' => 'Marielle Ayesha Cinco', 'company' => 'Sunshine Daycare Center', 'comment' => 'As a daycare, safety is paramount. Their eco-friendly, non-toxic products give us peace of mind while keeping our facility spotless.', 'avatar' => '/sweepxpress/assets/D5.jpg']
];

// Journey Timeline Data Structure - NOW AS CARDS
$timeline = [
    ['year' => 2020, 'title' => 'Foundation', 'desc' => 'Started with a small warehouse and big dreams'],
    ['year' => 2021, 'title' => 'Expansion', 'desc' => '500+ products added, nationwide delivery launched'],
    ['year' => 2022, 'title' => 'Eco Initiative', 'desc' => 'Introduced sustainable product line'],
    ['year' => 2023, 'title' => 'Digital Transform', 'desc' => 'Launched AI-powered recommendation engine'],
    ['year' => 2024, 'title' => 'Industry Leader', 'desc' => 'Recognized as top cleaning supplier in Philippines'],
    ['year' => 2025, 'title' => 'Innovation Hub', 'desc' => 'Opening state-of-the-art distribution center']
];

// PHP helper for safely outputting HTML content
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}
?>


<style>
/* Load modern UI font (Plus Jakarta Sans) */
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

/* -------------------------------------------------------------------------------- */
/* 1. DESIGN TOKENS - MODERN CLEAN PALETTE (Dribbble-inspired) */
/* -------------------------------------------------------------------------------- */
:root {
    --color-primary: #2563eb;           /* Clean blue */
    --color-primary-soft: #e0ecff;
    --color-accent: #22c55e;            /* Accent green */
    --neutral-50: #f9fafb;
    --neutral-100: #f3f4f6;
    --neutral-200: #e5e7eb;
    --neutral-300: #d1d5db;
    --neutral-400: #9ca3af;
    --neutral-500: #6b7280;
    --neutral-600: #4b5563;
    --neutral-700: #374151;
    --neutral-900: #0f172a;
    
    --font-family-sans: "Plus Jakarta Sans", system-ui, -apple-system, BlinkMacSystemFont, SF Pro Text, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif;
    
    --spacing-1: 0.25rem;
    --spacing-2: 0.5rem;
    --spacing-3: 0.75rem;
    --spacing-4: 1rem;
    --spacing-5: 1.5rem;
    --spacing-6: 2rem;
    --spacing-7: 2.5rem;
    --spacing-8: 3rem;
    
    --radius-sm: 0.5rem;
    --radius-md: 0.75rem;
    --radius-lg: 1.25rem;
    --radius-pill: 999px;
    
    --shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
    --shadow-sm: 0 4px 10px rgba(15, 23, 42, 0.06);
    --shadow-md: 0 14px 40px rgba(15, 23, 42, 0.16);
    --shadow-lg: 0 30px 80px rgba(15, 23, 42, 0.24);
}

/* -------------------------------------------------------------------------------- */
/* 2. GLOBAL LAYOUT & TYPOGRAPHY */
/* -------------------------------------------------------------------------------- */
body {
    background-color: var(--neutral-50);
    color: var(--neutral-700);
    font-family: var(--font-family-sans);
    line-height: 1.7;
}

/* Page background only inside about-page so header.php is not affected */
.about-page {
    background:
        radial-gradient(circle at top left, #e5edff 0%, #ffffff 40%, var(--neutral-50) 100%);
    padding-bottom: var(--spacing-8);
}

.container {
    max-width: 1120px;
    margin: 0 auto;
    padding: 0 var(--spacing-5);
}

.section { padding: var(--spacing-8) 0; }

.section-header {
    max-width: 640px;
    margin: 0 auto var(--spacing-6) auto;
    text-align: center;
}

.section-eyebrow {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--color-primary);
    margin-bottom: var(--spacing-2);
}

.section-title {
    font-size: 2.2rem;
    font-weight: 700;
    color: var(--neutral-900);
    letter-spacing: -0.03em;
    margin-bottom: var(--spacing-3);
}

.section-subtitle {
    font-size: 1rem;
    color: var(--neutral-500);
}

/* Simple fade-up animation (no JS needed) */
.fade-in {
    opacity: 0;
    transform: translateY(16px);
    animation: fadeUp 0.7s ease-out forwards;
}

@keyframes fadeUp {
    to { opacity: 1; transform: translateY(0); }
}

/* Subtle floating animation for hero card */
@keyframes floatCard {
    0%   { transform: translateY(0) rotateX(0deg) rotateY(0deg); }
    50%  { transform: translateY(-6px) rotateX(2deg) rotateY(-2deg); }
    100% { transform: translateY(0) rotateX(0deg) rotateY(0deg); }
}

/* Generic 3D card behaviour */
.card-3d {
    transform-style: preserve-3d;
    will-change: transform, box-shadow;
    transition:
        transform 0.35s cubic-bezier(0.19, 1, 0.22, 1),
        box-shadow 0.35s ease-out,
        border-color 0.25s ease-out,
        background 0.25s ease-out;
}

.card-3d:hover {
    transform: translateY(-8px) translateZ(18px) rotateX(4deg) rotateY(-4deg);
    box-shadow: var(--shadow-lg);
}

/* Reduce motion for accessibility */
@media (prefers-reduced-motion: reduce) {
    .fade-in {
        opacity: 1;
        transform: none;
        animation: none;
    }
    .card-3d,
    .card-3d:hover,
    .hero-card {
        transform: none !important;
        animation: none !important;
        transition: none !important;
    }
}

/* -------------------------------------------------------------------------------- */
/* 3. HERO - CLEAN MODERN */
/* -------------------------------------------------------------------------------- */
.hero {
    padding-top: calc(var(--spacing-8) + 3rem);
    padding-bottom: var(--spacing-8);
}

.hero-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: var(--spacing-7);
    align-items: center;
}

.hero-pill {
    display: inline-flex;
    align-items: center;
    gap: var(--spacing-2);
    padding: var(--spacing-1) var(--spacing-3);
    border-radius: var(--radius-pill);
    background: linear-gradient(120deg, rgba(37,99,235,0.12), rgba(37,99,235,0.02));
    border: 1px solid rgba(37, 99, 235, 0.18);
    font-size: 0.8rem;
    color: var(--color-primary);
    margin-bottom: var(--spacing-3);
}

.hero-pill-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background-color: var(--color-accent);
    box-shadow: 0 0 0 6px rgba(34,197,94,0.25);
}

.hero-title {
    font-size: 2.9rem;
    letter-spacing: -0.05em;
    font-weight: 700;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-4);
}

.hero-body {
    font-size: 1rem;
    color: var(--neutral-500);
    margin-bottom: var(--spacing-5);
}

.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--spacing-3);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--spacing-2);
    padding: 0.75rem 1.8rem;
    border-radius: var(--radius-pill);
    font-size: 0.95rem;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.2s ease-out;
    white-space: nowrap;
    position: relative;
    overflow: hidden;
}

.btn-primary {
    background: linear-gradient(135deg, #2563eb, #4f46e5);
    color: #ffffff;
    box-shadow: 0 14px 35px rgba(37, 99, 235, 0.35);
}

.btn-primary::after {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at 0 0, rgba(255,255,255,0.35), transparent 55%);
    opacity: 0;
    transition: opacity 0.25s ease-out;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 20px 45px rgba(37, 99, 235, 0.45);
}

.btn-primary:hover::after {
    opacity: 1;
}

.btn-ghost {
    background-color: #ffffff;
    color: var(--neutral-700);
    border-color: var(--neutral-200);
}

.btn-ghost:hover {
    border-color: var(--neutral-300);
    background-color: var(--neutral-100);
}

/* Hero side card - glassy + 3D */
.hero-card {
    background: linear-gradient(145deg, rgba(255,255,255,0.92), rgba(241,245,255,0.9));
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-md);
    padding: var(--spacing-6);
    border: 1px solid rgba(148,163,184,0.35);
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(18px);
    animation: floatCard 12s ease-in-out infinite alternate;
}

.hero-card::before {
    content: '';
    position: absolute;
    inset: -40%;
    background: radial-gradient(circle at 0 0, rgba(59,130,246,0.18), transparent 55%);
    opacity: 0.7;
    pointer-events: none;
}

.hero-card-tag {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.15em;
    color: var(--neutral-400);
    margin-bottom: var(--spacing-2);
}

.hero-card-metric {
    font-size: 2.1rem;
    font-weight: 700;
    color: var(--color-primary);
    margin-bottom: var(--spacing-2);
}

.hero-card-label {
    font-size: 0.9rem;
    color: var(--neutral-500);
}

.hero-card-image {
    margin-top: var(--spacing-5);
    border-radius: var(--radius-md);
    overflow: hidden;
    border: 1px solid var(--neutral-200);
    position: relative;
}

.hero-card-image::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(15,23,42,0.25), transparent 60%);
    opacity: 0.6;
    mix-blend-mode: multiply;
    pointer-events: none;
}

.hero-card-image img {
    width: 100%;
    height: 220px;
    object-fit: cover;
    display: block;
    transform: scale(1.02);
    transition: transform 0.8s ease-out;
}

.hero-card.card-3d:hover .hero-card-image img {
    transform: scale(1.06);
}

/* -------------------------------------------------------------------------------- */
/* 4. STATS STRIP */
/* -------------------------------------------------------------------------------- */
.stats { padding-top: 0; }

.stats-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--spacing-4);
    padding: var(--spacing-5);
    border-radius: var(--radius-lg);
    background-color: #ffffff;
    border: 1px solid var(--neutral-200);
    box-shadow: var(--shadow-xs);
}

.stat {
    padding: var(--spacing-2) 0;
    border-left: 1px solid var(--neutral-200);
    padding-left: var(--spacing-4);
}

.stat:first-child { border-left: none; padding-left: 0; }

.stat-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: var(--neutral-400);
    margin-bottom: var(--spacing-1);
}

.stat-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-1);
}

.stat-note {
    font-size: 0.85rem;
    color: var(--neutral-500);
}

/* -------------------------------------------------------------------------------- */
/* 5. ABOUT SPLIT */
/* -------------------------------------------------------------------------------- */
.about-split {
    background-color: #ffffff;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: var(--spacing-7);
    border: 1px solid var(--neutral-200);
}

.about-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr);
    gap: var(--spacing-7);
    align-items: center;
}

.about-title {
    font-size: 1.9rem;
    font-weight: 700;
    color: var(--neutral-900);
    letter-spacing: -0.03em;
    margin-bottom: var(--spacing-3);
}

.about-copy {
    font-size: 0.98rem;
    color: var(--neutral-500);
    margin-bottom: var(--spacing-5);
}

.about-highlights { display: grid; gap: var(--spacing-4); }

.about-highlight-title {
    font-size: 0.98rem;
    font-weight: 600;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-1);
}

.about-highlight-body {
    font-size: 0.9rem;
    color: var(--neutral-500);
}

.about-card {
    background-color: var(--color-primary-soft);
    border-radius: var(--radius-md);
    padding: var(--spacing-5);
    border: 1px solid rgba(37, 99, 235, 0.18);
}

.about-card-row {
    display: flex;
    justify-content: space-between;
    gap: var(--spacing-5);
    margin-bottom: var(--spacing-4);
}

.about-card-metric {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-1);
}

.about-card-label {
    font-size: 0.85rem;
    color: var(--neutral-500);
}

.about-card-footer {
    font-size: 0.85rem;
    color: var(--neutral-500);
}

/* -------------------------------------------------------------------------------- */
/* 6. MISSION VISION CARDS */
/* -------------------------------------------------------------------------------- */
.mv-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--spacing-5);
}

.mv-card {
    background-color: #ffffff;
    border-radius: var(--radius-lg);
    border: 1px solid var(--neutral-200);
    box-shadow: var(--shadow-xs);
    padding: var(--spacing-6);
}

.mv-label {
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: var(--neutral-400);
    margin-bottom: var(--spacing-2);
}

.mv-title {
    font-size: 1.4rem;
    font-weight: 600;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-3);
}

.mv-body {
    font-size: 0.95rem;
    color: var(--neutral-500);
}

/* -------------------------------------------------------------------------------- */
/* 7. TIMELINE AS CARDS GRID */
/* -------------------------------------------------------------------------------- */
.timeline-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--spacing-4);
    perspective: 1400px;
    perspective-origin: center top;
}

.timeline-card {
    background-color: #ffffff;
    border-radius: var(--radius-lg);
    border: 1px solid var(--neutral-200);
    box-shadow: var(--shadow-xs);
    padding: var(--spacing-5);
    position: relative;
    overflow: hidden;
}

.timeline-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom right, rgba(37,99,235,0.08), transparent);
    opacity: 0;
    transition: opacity 0.16s ease-out;
}

.timeline-card-inner { position: relative; z-index: 1; }

.timeline-year-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: var(--radius-pill);
    background-color: rgba(37, 99, 235, 0.08);
    color: var(--color-primary);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: var(--spacing-3);
}

.timeline-card-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-2);
}

.timeline-card-body {
    font-size: 0.9rem;
    color: var(--neutral-500);
}

.timeline-card.card-3d:hover::before {
    opacity: 1;
}

/* -------------------------------------------------------------------------------- */
/* 8. TEAM GRID */
/* -------------------------------------------------------------------------------- */
.team-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: var(--spacing-4);
    perspective: 1400px;
    perspective-origin: center top;
}

.team-card {
    background-color: #ffffff;
    border-radius: var(--radius-lg);
    border: 1px solid var(--neutral-200);
    box-shadow: var(--shadow-xs);
    overflow: hidden;
}

.team-image {
    height: 220px;
    overflow: hidden;
}

.team-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.5s ease-out;
}

.team-card.card-3d:hover .team-image img { transform: scale(1.06); }

.team-body {
    padding: var(--spacing-4);
    text-align: center;
}

.team-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-1);
}

.team-role {
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--color-primary);
    margin-bottom: var(--spacing-2);
}

.team-bio {
    font-size: 0.85rem;
    color: var(--neutral-500);
}

/* -------------------------------------------------------------------------------- */
/* 9. VALUES MARQUEE */
/* -------------------------------------------------------------------------------- */
.values-strip {
    overflow-x: auto;
    padding-bottom: var(--spacing-2);
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.values-strip::-webkit-scrollbar { display: none; }

.values-row {
    display: inline-flex;
    gap: var(--spacing-4);
    min-width: 100%;
}

.value-card {
    min-width: 260px;
    max-width: 280px;
    background-color: #ffffff;
    border-radius: var(--radius-lg);
    border: 1px solid var(--neutral-200);
    box-shadow: var(--shadow-xs);
    padding: var(--spacing-4);
}

.value-icon {
    font-size: 1.6rem;
    margin-bottom: var(--spacing-2);
}

.value-title {
    font-size: 0.98rem;
    font-weight: 600;
    color: var(--neutral-900);
    margin-bottom: var(--spacing-1);
}

.value-body {
    font-size: 0.85rem;
    color: var(--neutral-500);
}

.values-carousel {
  position: relative;
  overflow: hidden;
  padding-bottom: var(--spacing-2);
}

.values-track {
  display: inline-flex;
  gap: var(--spacing-4);
  animation: valuesScroll 28s linear infinite;
}

.values-carousel:hover .values-track {
  animation-play-state: paused; /* pause on hover */
}

.value-card {
  min-width: 260px;
  max-width: 280px;
  background-color: #ffffff;
  border-radius: var(--radius-lg);
  border: 1px solid var(--neutral-200);
  box-shadow: var(--shadow-xs);
  padding: var(--spacing-4);
  flex-shrink: 0;
  transition: transform 0.25s ease-out, box-shadow 0.25s ease-out, border-color 0.25s ease-out;
}

.value-card:hover {
  transform: translateY(-6px) scale(1.02);
  box-shadow: var(--shadow-md);
  border-color: rgba(37,99,235,0.35);
}

.value-icon {
  font-size: 1.8rem;
  margin-bottom: var(--spacing-2);
}

@keyframes valuesScroll {
  0% {
    transform: translateX(0);
  }
  100% {
    /* move left by half the total width (since we duplicated items) */
    transform: translateX(-50%);
  }
}

/* Mobile: allow swipe / stop auto-scroll if you want */
@media (max-width: 640px) {
  .values-carousel {
    overflow-x: auto;
  }
  .values-track {
    animation: none;
  }
}


/* -------------------------------------------------------------------------------- */
/* 10. TESTIMONIALS */
/* -------------------------------------------------------------------------------- */
.testimonials-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--spacing-4);
    perspective: 1400px;
    perspective-origin: center top;
}

.testimonial-card {
    background-color: #ffffff;
    border-radius: var(--radius-lg);
    border: 1px solid var(--neutral-200);
    box-shadow: var(--shadow-xs);
    padding: var(--spacing-5);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: var(--spacing-4);
}

.testimonial-quote {
    font-size: 2.5rem;
    line-height: 1;
    color: rgba(37, 99, 235, 0.16);
}

.testimonial-text {
    font-size: 0.95rem;
    color: var(--neutral-600);
}

.testimonial-footer {
    display: flex;
    align-items: center;
    gap: var(--spacing-3);
}

.testimonial-avatar {
    width: 44px;
    height: 44px;
    border-radius: 999px;
    overflow: hidden;
    border: 2px solid rgba(37, 99, 235, 0.5);
    flex-shrink: 0;
}

.testimonial-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.testimonial-meta { font-size: 0.85rem; }

.testimonial-name {
    font-weight: 600;
    color: var(--neutral-900);
    margin-bottom: 2px;
}

.testimonial-company {
    color: var(--neutral-500);
}

/* -------------------------------------------------------------------------------- */
/* 11. CTA FOOTER */
/* -------------------------------------------------------------------------------- */
.final-cta {
    background-color: var(--neutral-900);
    border-radius: 1.75rem;
    padding: var(--spacing-7) var(--spacing-6);
    text-align: center;
    color: #ffffff;
    box-shadow: 0 40px 80px rgba(15,23,42,0.45);
}

.final-cta-title {
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: -0.04em;
    margin-bottom: var(--spacing-3);
}

.final-cta-body {
    font-size: 1rem;
    color: rgba(248, 250, 252, 0.8);
    margin-bottom: var(--spacing-5);
}

.final-cta .btn-primary {
    background: #ffffff;
    color: var(--neutral-900);
    border-color: transparent;
    box-shadow: 0 14px 35px rgba(15,23,42,0.55);
}

/* -------------------------------------------------------------------------------- */
/* 12. RESPONSIVE BREAKPOINTS - FULLY MOBILE ADAPTIVE */
/* -------------------------------------------------------------------------------- */
@media (max-width: 1024px) {
    .hero-grid { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); }
    .about-grid { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); }
    .team-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .testimonials-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 768px) {
    .hero-grid { grid-template-columns: minmax(0, 1fr); }
    .hero { padding-top: var(--spacing-7); }
    .hero-title { font-size: 2.2rem; }
    .hero-actions { flex-direction: column; align-items: stretch; }
    
    .stats-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stat {
        border-left: none;
        padding-left: 0;
        border-top: 1px solid var(--neutral-200);
        padding-top: var(--spacing-3);
    }
    .stat:first-child, .stat:nth-child(2) { border-top: none; padding-top: 0; }
    
    .about-grid { grid-template-columns: minmax(0, 1fr); }
    .mv-grid { grid-template-columns: minmax(0, 1fr); }
    .team-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 640px) {
    .container { padding: 0 var(--spacing-4); }
    .section { padding: var(--spacing-6) 0; }
    .hero-title { font-size: 2rem; }
    .section-title { font-size: 1.7rem; }
    .stats-row { 
        grid-template-columns: minmax(0, 1fr);
        padding: var(--spacing-4);
    }
    .team-grid { grid-template-columns: minmax(0, 1fr); }
    .testimonials-grid { grid-template-columns: minmax(0, 1fr); }
    .final-cta { padding: var(--spacing-6) var(--spacing-4); }
    
    .timeline-cards { grid-template-columns: 1fr; }
    .values-row { gap: var(--spacing-3); }
    .value-card { min-width: 280px; }
}
</style>


<div class="about-page">
    <!-- HERO -->
    <section class="hero section fade-in">
        <div class="container">
            <div class="hero-grid">
                <div>
                    <div class="hero-pill">
                        <span class="hero-pill-dot"></span>
                        <span>Established <?php echo (int)$established_year ?> - Trusted cleaning partner</span>
                    </div>
                    <h1 class="hero-title">The modern way to keep the Philippines spotless.</h1>
                    <p class="hero-body"><?php echo h($mission); ?></p>
                    <div class="hero-actions">
                        <a href="#mission-vision" class="btn btn-primary">Explore our mission</a>
                        <a href="/sweepxpress/products.php" class="btn btn-ghost">Browse all products</a>
                    </div>
                </div>
                <aside class="hero-card card-3d">
                    <div class="hero-card-tag">Snapshot</div>
                    <div class="hero-card-metric">15,000</div>
                    <div class="hero-card-label">Customers relying on <?php echo h($company_name); ?> nationwide.</div>
                    
                    <div class="about-card-row" style="margin-top: var(--spacing-5);">
                        <div>
                            <div class="about-card-metric">800</div>
                            <div class="about-card-label">Curated products</div>
                        </div>
                        <div>
                            <div class="about-card-metric">98</div>
                            <div class="about-card-label">Satisfaction rating</div>
                        </div>
                    </div>
                    
                    <div class="hero-card-image">
                        <img src="<?php echo h($about_banner_image_path); ?>" alt="SweepXpress warehouse and cleaning tools">
                    </div>
                </aside>
            </div>
        </div>
    </section>

    <!-- STATS STRIP -->
    <section class="stats section fade-in">
        <div class="container">
            <div class="stats-row">
                <div class="stat">
                    <div class="stat-label">Happy customers</div>
                    <div class="stat-value">15,000</div>
                    <div class="stat-note">Across homes, hotels, and businesses.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Premium SKUs</div>
                    <div class="stat-value">800</div>
                    <div class="stat-note">Curated, tested, and performance-driven.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Satisfaction rate</div>
                    <div class="stat-value">98</div>
                    <div class="stat-note">A service-first support philosophy.</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Support hours</div>
                    <div class="stat-value">24/7</div>
                    <div class="stat-note">Always-on for urgent needs.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- ABOUT SPLIT -->
    <section class="section fade-in">
        <div class="container">
            <div class="about-split">
                <div class="about-grid">
                    <div>
                        <div class="section-eyebrow">Who we are</div>
                        <h2 class="about-title">A local brand with a global standard for clean.</h2>
                        <p class="about-copy">
                            Founded in <?php echo (int)$established_year; ?>, <?php echo h($company_name); ?> exists to close the gap between everyday cleaning and professional-grade results, making high-performing tools accessible to every Filipino home and business.
                        </p>
                        <div class="about-highlights">
                            <div>
                                <div class="about-highlight-title">Mission-driven, not volume-driven</div>
                                <p class="about-highlight-body">Every product on our shelves has to earn its place through performance, durability, and safety for people and the planet.</p>
                            </div>
                            <div>
                                <div class="about-highlight-title">Built with operators in mind</div>
                                <p class="about-highlight-body">From hotels and malls to daycares and clinics, we listen to on-ground teams and evolve our catalog with their feedback.</p>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="about-card card-3d">
                            <div class="about-card-row">
                                <div>
                                    <div class="about-card-metric">3x</div>
                                    <div class="about-card-label">Average productivity lift for teams using our kits.</div>
                                </div>
                                <div>
                                    <div class="about-card-metric">Nationwide</div>
                                    <div class="about-card-label">Coverage across key cities and provinces.</div>
                                </div>
                            </div>
                            <p class="about-card-footer">The result: fewer replacements, faster routines, and a consistently spotless environment that your customers and staff feel the moment they walk in.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- MISSION VISION -->
    <section id="mission-vision" class="section fade-in">
        <div class="container">
            <div class="section-header">
                <div class="section-eyebrow">Direction</div>
                <h2 class="section-title">Mission and vision that guide us</h2>
                <p class="section-subtitle">Every decision we make is anchored on clarity of purpose and long-term impact.</p>
            </div>
            <div class="mv-grid">
                <article class="mv-card card-3d">
                    <div class="mv-label">Our Mission</div>
                    <div class="mv-title">Empowering spotless, healthy spaces</div>
                    <p class="mv-body"><?php echo h($mission); ?></p>
                </article>
                <article class="mv-card card-3d">
                    <div class="mv-label">Our Vision</div>
                    <div class="mv-title">The most trusted cleaning partner in the Philippines</div>
                    <p class="mv-body"><?php echo h($vision); ?></p>
                </article>
            </div>
        </div>
    </section>

    <!-- TIMELINE AS CARDS -->
    <section class="section fade-in">
        <div class="container">
            <div class="section-header">
                <div class="section-eyebrow">Journey</div>
                <h2 class="section-title">Milestones on our path</h2>
                <p class="section-subtitle">A quick look at how <?php echo h($company_name); ?> grew from a small warehouse into a nationwide partner.</p>
            </div>
            <div class="timeline-cards">
                <?php foreach ($timeline as $item): ?>
                <article class="timeline-card card-3d">
                    <div class="timeline-card-inner">
                        <div class="timeline-year-pill"><?php echo h($item['year']); ?></div>
                        <h3 class="timeline-card-title"><?php echo h($item['title']); ?></h3>
                        <p class="timeline-card-body"><?php echo h($item['desc']); ?></p>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- TEAM -->
    <section class="section fade-in">
        <div class="container">
            <div class="section-header">
                <div class="section-eyebrow">Team</div>
                <h2 class="section-title">The people behind the clean</h2>
                <p class="section-subtitle">A tight-knit leadership team obsessed with product quality, logistics precision, and everyday customer delight.</p>
            </div>
            <div class="team-grid">
                <?php foreach ($team_members as $member): ?>
                <article class="team-card card-3d">
                    <div class="team-image">
                        <img src="<?php echo h($member['image']); ?>" alt="<?php echo h($member['name']); ?>">
                    </div>
                    <div class="team-body">
                        <h3 class="team-name"><?php echo h($member['name']); ?></h3>
                        <div class="team-role"><?php echo h($member['role']); ?></div>
                        <p class="team-bio"><?php echo h($member['bio']); ?></p>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- VALUES -->
<!-- VALUES (Carousel) -->
<section class="section fade-in">
  <div class="container">
    <div class="section-header">
      <div class="section-eyebrow">Values</div>
      <h2 class="section-title">What keeps us grounded</h2>
      <p class="section-subtitle">
        These values shape how we work with suppliers, partners, and every customer we serve.
      </p>
    </div>

    <div class="values-carousel" id="valuesCarousel">
      <div class="values-track">
        <!-- Duplicate set #1 -->
        <article class="value-card">
          <div class="value-icon">💎</div>
          <h3 class="value-title">Uncompromising quality</h3>
          <p class="value-body">
            Only tools that pass performance and durability checks make it to our shelves.
          </p>
        </article>

        <article class="value-card">
          <div class="value-icon">🌿</div>
          <h3 class="value-title">Environmental care</h3>
          <p class="value-body">
            Pushing for safer, lower-impact solutions in every new product line.
          </p>
        </article>

        <article class="value-card">
          <div class="value-icon">🤝</div>
          <h3 class="value-title">Customer-first</h3>
          <p class="value-body">
            We solve for your use case, not just push products.
          </p>
        </article>

        <article class="value-card">
          <div class="value-icon">🚀</div>
          <h3 class="value-title">Continuous innovation</h3>
          <p class="value-body">
            Always scouting and testing better cleaning technologies.
          </p>
        </article>

        <!-- Duplicate set #2 for seamless loop -->
        <article class="value-card">
          <div class="value-icon">💎</div>
          <h3 class="value-title">Uncompromising quality</h3>
          <p class="value-body">
            Only tools that pass performance and durability checks make it to our shelves.
          </p>
        </article>

        <article class="value-card">
          <div class="value-icon">🌿</div>
          <h3 class="value-title">Environmental care</h3>
          <p class="value-body">
            Pushing for safer, lower-impact solutions in every new product line.
          </p>
        </article>

        <article class="value-card">
          <div class="value-icon">🤝</div>
          <h3 class="value-title">Customer-first</h3>
          <p class="value-body">
            We solve for your use case, not just push products.
          </p>
        </article>

        <article class="value-card">
          <div class="value-icon">🚀</div>
          <h3 class="value-title">Continuous innovation</h3>
          <p class="value-body">
            Always scouting and testing better cleaning technologies.
          </p>
        </article>
      </div>
    </div>
  </div>
</section>


    <!-- TESTIMONIALS -->
    <section class="section fade-in">
        <div class="container">
            <div class="section-header">
                <div class="section-eyebrow">Proof</div>
                <h2 class="section-title">Trusted by teams across industries</h2>
                <p class="section-subtitle">From hospitality to education, our partners share how better tools changed the way they clean.</p>
            </div>
            <div class="testimonials-grid">
                <?php foreach ($testimonials as $testimonial): ?>
                <article class="testimonial-card card-3d">
                    <div>
                        <div class="testimonial-quote">❝</div>
                        <p class="testimonial-text"><?php echo h($testimonial['comment']); ?></p>
                    </div>
                    <footer class="testimonial-footer">
                        <div class="testimonial-avatar">
                            <img src="<?php echo h($testimonial['avatar']); ?>" alt="Avatar of <?php echo h($testimonial['name']); ?>">
                        </div>
                        <div class="testimonial-meta">
                            <div class="testimonial-name"><?php echo h($testimonial['name']); ?></div>
                            <div class="testimonial-company"><?php echo h($testimonial['company']); ?></div>
                        </div>
                    </footer>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- FINAL CTA -->
    <section class="section fade-in">
        <div class="container">
            <div class="final-cta">
                <h2 class="final-cta-title">Ready to upgrade how you clean?</h2>
                <p class="final-cta-body">Join thousands of teams and households who chose <?php echo h($company_name); ?> for tools that simply work better, last longer, and feel good to use.</p>
                <a href="/sweepxpress/index.php" class="btn btn-primary">Shop now</a>
            </div>
        </div>
    </section>
</div>

<script>
  const valuesCarousel = document.getElementById('valuesCarousel');
  const valuesTrack = valuesCarousel?.querySelector('.values-track');

  if (valuesCarousel && valuesTrack) {
    ['mouseenter', 'touchstart'].forEach(evt =>
      valuesCarousel.addEventListener(evt, () => {
        valuesTrack.style.animationPlayState = 'paused';
      })
    );
    ['mouseleave', 'touchend', 'touchcancel'].forEach(evt =>
      valuesCarousel.addEventListener(evt, () => {
        valuesTrack.style.animationPlayState = 'running';
      })
    );
  }
</script>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
