# SweepXpress

**A supply-chain e-commerce platform for cleaning supplies, with demand forecasting and an AI admin assistant.**

SweepXpress sells to households (B2C) and to businesses on purchase orders (B2B), and closes the loop on the
supply side: sales reduce stock, low stock turns into reorder suggestions, suggestions become restock orders to
suppliers, deliveries go back into stock, and supplier performance is scored from what actually arrived.

![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-10.4-003545?logo=mariadb&logoColor=white)
![Bootstrap 5.3](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)
![Chart.js](https://img.shields.io/badge/Chart.js-4.4-FF6384?logo=chartdotjs&logoColor=white)

**Live demo:** <https://sweepxpress.infinityfree.io/sweepxpress/> (free hosting, so it can be slow to wake up)

<!--
SCREENSHOTS (owner: capture these at 1440px wide, save in docs/screenshots/, then uncomment this block)

| Storefront | Admin dashboard |
|---|---|
| ![Storefront](docs/screenshots/storefront.png) | ![Admin dashboard](docs/screenshots/dashboard.png) |

| Reorder points | Supplier scorecard |
|---|---|
| ![Reorder points](docs/screenshots/reorder-points.png) | ![Supplier scorecard](docs/screenshots/supplier-scorecard.png) |

| Admin Copilot | Dark mode |
|---|---|
| ![Admin Copilot](docs/screenshots/copilot.png) | ![Dark mode](docs/screenshots/dark-mode.png) |
-->

---

## What it does

### Storefront
- Product catalogue with categories, search, stock badges, cart, checkout (cash on delivery) and "Buy now".
- Customer accounts with order history, cancellation requests, printable invoices and receipts.
- **B2B purchase orders**: business accounts submit a PO with payment terms (Net 30 / 45 / 60). Stock is checked and
  reserved at submission, and the order waits for admin review.
- Sign in with email and a one-time code, or with Google. Dark mode follows the device and can be toggled.

### Admin
- **Bento dashboard**: revenue with month-over-month change, orders that need attention, inventory and supply-chain
  tiles, charts, recent orders and products running low.
- Products, orders, deliveries (kanban by due date), customers and sales reports, as cards or a list.
- **Inventory** with stock in / out / adjust, full movement history, QR labels, a QR scanner and CSV export.

### Supply chain
- **Suppliers** with lead time, payment terms and contact details.
- **Restock purchase orders** (draft, sent, partly received, received, cancelled) with partial receiving. Receiving goes
  through the same stock function as every other movement and updates the product's cost price.
- **Reorder page**: everything at or below its reorder point, minus what is already on order, grouped by supplier,
  with one click to draft the purchase order.

### Forecasting (statistics, no black box)
- **Sales velocity and days of stock left** from the order history, over a 30, 90 or 365 day window.
- **Suggested reorder points**, applied only when the admin confirms:

  ```
  reorder point = sales per day x supplier lead time + safety stock
  safety stock  = 1.65 x (day-to-day variation of sales) x sqrt(lead time)      (about 95% service level)
  order quantity = about 30 days of sales
  ```

  A single huge order is capped so it cannot distort the result, and products with too little history are left alone.
- **Supplier scorecard**: on-time delivery, fill rate, actual vs promised lead time and price changes, combined into a
  0 to 100 score (40% on-time, 40% fill rate, 20% price steadiness).

### Admin Copilot (LLM with tool calling)
A chat assistant on every admin page that answers from the store's own data, for example *"Which items will run out
before the supplier can deliver?"* or *"Ano ang dapat kong i-reorder ngayon?"*.

- The model never writes SQL. It can only call **10 read-only tools** (snapshot, sales, top products, low stock, stock
  forecast, reorder suggestions, orders, purchase orders, supplier scorecard, product lookup) with whitelisted
  parameters.
- No customer personal data is sent to the model. Every answer shows which tools were used.
- Admin only, CSRF-protected, rate-limited per minute, per day and globally (it fails closed), and the replies are
  rendered as plain text so they cannot inject HTML.
- Runs on [Groq](https://groq.com) (free tier). Details: [docs/admin-copilot.md](docs/admin-copilot.md).

---

## How the pieces fit

```
 Customer / business order ──► stock (inventory) ──► below reorder point?
        ▲                            ▲                       │
        │                            │                       ▼
   Storefront                 Receive delivery         Reorder page ──► Draft restock PO ──► Sent
                                     ▲                                                       │
                                     └───────────────────────────── Supplier delivers ◄──────┘
                                                      │
                          sales history ──► velocity ──► suggested reorder points
                          received POs  ──► supplier scorecard
                          all of the above ──► read-only tools ──► Admin Copilot
```

| Layer | Where |
|---|---|
| Pages | `*.php` in the root (storefront), `customers/`, `business/`, `admin/` |
| Business logic | `includes/` : `stock_ops.php`, `purchasing_ops.php`, `forecast_ops.php`, `supplier_ops.php`, `order_ops.php`, `copilot_tools.php`, `groq_client.php` |
| Design system | `assets/style.css` (`--sx-*` tokens, light and dark), shared components such as `sx-card`, `sx-btn`, `sx-pill` |
| Database | `database/schema.sql`, `database/migrations/`, `database/demo/` |

**Design decisions worth a look**
- Stock changes run in transactions with row locks and always write a stock movement. Purchase-order receiving and
  admin stock in / out / adjust share one function (`sx_stock_apply()`), and each movement points back to its source
  (order, purchase order), so the history can be explained.
- Pages process POST requests before any output and redirect afterwards (post/redirect/get), with CSRF tokens.
- The AI rate limit **fails closed** (if the limit cannot be checked, the request is refused) because that path costs money.
- The forecasting is plain, explainable maths that an operations person can check by hand.

## Tech stack

PHP 8.2 (PDO, no framework) · MariaDB 10.4 / MySQL · Bootstrap 5.3 · Font Awesome 6 · Chart.js 4 · Plus Jakarta Sans ·
PHPMailer · Dompdf · League OAuth2 (Google) · Groq API (OpenAI-compatible, tool calling)

## Getting started

**Requirements:** PHP 8.2 with `pdo_mysql`, `curl`, `mbstring`, `dom`, `openssl` and `gd`; MariaDB or MySQL; Composer.
XAMPP on Windows works. The app builds its URLs under **`/sweepxpress/`**, so it must be served from
`http://localhost/sweepxpress/`.

```bash
# 1. Put the project in your web root, e.g. C:\xampp\htdocs\sweepxpress
git clone https://github.com/BryxCinco/sweepxpress.git
cd sweepxpress
composer install

# 2. Configuration (never commit .env)
cp .env.example .env        # then edit it: database, mail (for sign-in codes), optional Google and Groq keys

# 3. Database
mysql -u root -e "CREATE DATABASE sweepxpress_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root sweepxpress_db < database/schema.sql
mysql -u root sweepxpress_db < database/demo/catalog.sql        # 26 sample products with stock
```

Open <http://localhost/sweepxpress/>. To get an admin, register an account and set its role in the database, or use the
demo accounts below.

### Demo data (optional but recommended)

These add suppliers, about 150 orders over the last 120 days and restock purchase orders, so the dashboard,
forecasts and scorecard have something to show. Everything is marked `[DEMO]` and can be removed.

```bash
mysql -u root sweepxpress_db < database/demo/suppliers.sql
mysql -u root sweepxpress_db < database/demo/sales.sql
mysql -u root sweepxpress_db < database/demo/purchase_orders.sql
# later, to remove all demo data:  database/demo/remove_demo_data.sql
```

### Demo accounts

`database/demo/accounts.sql` creates `admin@demo.sweepxpress.com`, `customer@demo.sweepxpress.com` and
`business@demo.sweepxpress.com`. The file contains no password; you choose one:

```bash
php tools/make_password_hash.php     # prints a hash, paste it into accounts.sql, then run the file
```

Every normal sign-in sends a one-time code by email, and the demo addresses have no mailbox. For a public demo site,
add `DEMO_MODE=1` to `.env`: it skips the code for exactly those three addresses and nothing else.

> **Only enable `DEMO_MODE` on a database that holds no real customer data.** The demo admin can see everything in it.

### Admin Copilot (optional)

Create a free key at <https://console.groq.com>, then in `.env`:

```
GROQ_API_KEY=your-key
GROQ_MODEL=openai/gpt-oss-120b
```

`php tools/groq_check.php` tests the key and lists the models your account can use (Groq retires models from time to
time). Without a key the Copilot button explains what is missing and everything else works as usual.

## Database

`database/schema.sql` is the complete current structure (18 tables, no data). `database/migrations/` holds the
incremental changes for databases that already exist, with the order and the rules in its own
[README](database/migrations/README.md). A fresh install uses the schema only.

## Security notes

- Prepared statements (PDO) for queries; output is escaped; passwords are hashed with `password_hash`.
- Sign-in uses a time-limited one-time code, with rate limits on sign-in, registration, password reset and the contact
  form, plus honeypot and time-trap spam guards. Suspended accounts cannot sign in.
- Role checks on every admin page; CSRF tokens on state-changing admin actions.
- Secrets live in `.env` (ignored by git). Sign-in attempts and admin actions are recorded in `user_logs`.

## Known limitations and roadmap

- Not built yet: a supplier portal (suppliers are records only), returns and refund handling beyond the written
  policy, delivery tracking with proof of delivery, and multiple warehouses.
- Facebook sign-in is disabled. Google sign-in does not yet check for suspended accounts.
- Checkout supports cash on delivery and purchase orders only (no online payment gateway).
- The free hosting may block outbound requests, in which case the Copilot only works locally.

## Author

Built by [BryxCinco](https://github.com/BryxCinco) as a portfolio project: *supply-chain e-commerce with AI*.
