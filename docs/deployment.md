# Deploying to InfinityFree: upload list and SQL order

Exact steps for bringing the live site (`https://sweepxpress.infinityfree.io/sweepxpress/`) up to date with this
repository. The live site was last deployed from commit **`8594145`** ("Pre-deploy", Oct 26); everything that changed
since then is listed below, generated with `git diff 8594145 HEAD`.

> If you already uploaded some of these files, you can skip them. Re-uploading a file is always safe, with one
> exception: see *config_oauth.php* in step 5.

**Order of work: back up → check the database → run the SQL → edit `.env` → upload files → delete old files → smoke test.**
The SQL goes **before** the code because the new code writes to columns and tables that the migrations create, and
the migrations only add things, so the old code keeps working in between.

---

## 1. Back up (10 minutes)

1. phpMyAdmin → select the database → **Export** → Quick, format SQL → **Go**. Keep the file on your computer.
   Never commit it (it contains customer data).
2. FTP: download at least `.env`, `config_oauth.php` and the `uploads/` folder from `htdocs/sweepxpress/`.
3. Write down the commit you are deploying: `git log --oneline -1`.

## 2. Check which migrations the live database already has

phpMyAdmin → database → **SQL** tab → paste → **Go**:

```sql
SELECT
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username')          AS m4a_username,
  (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_limits')                                   AS m4b_rate_limits,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND COLUMN_NAME = 'user_seen') AS m4c_user_seen,
  (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'suppliers')                                     AS m7a_suppliers,
  (SELECT COUNT(*) FROM information_schema.TABLES  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copilot_log')                                   AS m8e_copilot_log,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_logs' AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%') AS m11a_user_logs_id;
```

`1` means that migration is already applied, `0` means it still has to run. A live database that was exported from the
local one on Oct 26 should read **`1, 1, 1, 0, 0, 0`**.

## 3. Run the SQL in this order

Open each file from `database/migrations/`, copy everything, paste it in the **SQL** tab, **Go**. One at a time, and
only the ones that read `0` above.

| # | File | Run if | What it does |
|---|---|---|---|
| 0 | `2026-10-26_4a_users_cleanup.sql`, `2026-10-26_4b_rate_limits.sql`, `2026-10-27_4c_contact_user_seen.sql` | `m4a`, `m4b` or `m4c` is `0` (not expected) | Older migrations. Run in date order. Read the comments in `4a` first; it has verification steps. |
| 1 | `2026-10-29_7a_supply_chain.sql` | `m7a_suppliers = 0` | Suppliers, restock purchase orders, supply columns on products, stock movement references |
| 2 | `2026-10-29_8e_copilot_log.sql` | `m8e_copilot_log = 0` | Audit table for the Admin Copilot |
| 3 | `2026-10-30_11a_user_logs_autoincrement.sql` | `m11a_user_logs_id = 0` | Fixes `user_logs.id` so that sign-in logs work again |

> **Never run `7a` twice.** Its `ALTER TABLE` statements are not repeatable and the second run stops with
> `Duplicate column name 'supplier_id'`. `8e` and `11a` are safe to repeat.

Run the check from step 2 again. It must now read **`1, 1, 1, 1, 1, 1`**.

> `database/schema.sql` is only for a brand-new empty database. **Do not import it into the live database.**

This path was rehearsed on a copy of the pre-7a database: after the three files the structure matched
`database/schema.sql` exactly (no differences).

## 4. Edit the live `.env`

Edit `htdocs/sweepxpress/.env` (File Manager or FTP). Compare with `.env.example`. Add or confirm:

```
# Sign-in with Google (needs https on the live site)
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://sweepxpress.infinityfree.io/sweepxpress/google_callback.php

# Must stay 0 on the live site
DEMO_MODE=0

# Admin Copilot (optional)
GROQ_API_KEY=...
GROQ_MODEL=openai/gpt-oss-120b
```

- The same redirect URI must be listed in the Google Cloud Console (Credentials → your OAuth client).
- Do not paste the real values anywhere except this file. `.env` is blocked from the web by the root `.htaccess`.
- `DEMO_MODE=1` removes the sign-in code for the three demo addresses. Never turn it on where real customers exist.

## 5. Upload the files

Upload to `htdocs/sweepxpress/`, keeping the folder structure and **overwriting** existing files. Use FTP (for
example FileZilla) so that whole folders can be dropped at once. Suggested order, so that no page ever points to a
missing include: `includes/` → `assets/` → `admin/`, `customers/`, `business/` → root files.

Do **not** upload: `.env`, `vendor/`, `uploads/` (user files), `database/`, `docs/`, `tools/`, `README.md`,
`.gitignore`, `.git/`. `composer.json`, `composer.lock`, `.htaccess` and `config.php` did not change.

> **`config_oauth.php`**: it is now in the repository and only reads the Google keys from `.env`. If the live copy
> has the keys written inside the file instead, put them in `.env` (step 4) first, or skip uploading this one file.

**94 files** to upload (new or changed since `8594145`):

**`includes`** (17)

```
admin_products.php
auth_layout.php
copilot_tools.php
copilot_widget.php
footer.php
forecast_ops.php
groq_client.php
header.php
order_document.php
order_ops.php
order_status_notifier.php
product_card.php
purchasing_ops.php
sidebar.php
stock_ops.php
supplier_ops.php
theme_init.php
```

**`assets`** (3)

```
copilot.js
script.js
style.css
```

**`assets/brand`** (3)

```
apple-touch-icon.png
favicon-32.png
logo-256.png
```

**`admin`** (50)

```
about_edit.php
add_product.php
admin_contact.php
adminreceipt.php
allorders.php
copilot_api.php
cron_stock_alert.php
customer_management.php
dashboard.php
delete.php
deliveries.php
deliveries_edit.php
edit_product.php
edit_role.php
generate_order_pdf.php
generate_pdf.php
import_csv.php
inventory.php
inventory_full_history.php
order_all.php
order_details.php
order_details_b2b.php
order_details_b2c.php
order_details_complete.php
order_details_deliveries.php
orders.php
orders_archive_all.php
orders_cancellation.php
orders_completed.php
orders_pending.php
product_history.php
products.php
purchase_order.php
purchase_order_print.php
purchase_orders.php
reorder.php
reorder_points.php
reports.php
settings_admin.php
stock_adjust.php
stock_in.php
stock_out.php
styleguide.php
supplier.php
supplier_scorecard.php
suppliers.php
suspend.php
unsuspend.php
user_actions.php
view_logs.php
```

**`customers`** (4)

```
UserReceipt.php
my_orders.php
order_details.php
settings_customer.php
```

**`business`** (1)

```
purchaseorder.php
```

**project root (`htdocs/sweepxpress/`)** (16)

```
Invoice.php
about.php
buy_now_checkout.php
cart.php
checkout.php
config_oauth.php
contact.php
forgot_password.php
google_callback.php
index.php
login.php
privacy.php
product.php
register.php
reset_password.php
terms.php
```

## 6. Delete these files from the server

They were removed from the project. Leaving them wastes space but is harmless, except `content/about.html`, which
nothing reads any more.

**29 files** (relative to `htdocs/sweepxpress/`):

```
assets/BDO.png
assets/BPI.png
assets/Banner1.jfif
assets/Banner1.jpg
assets/Cebuana_Lhuillier_Logo.png
assets/D1.jpg
assets/D2.jpg
assets/D3.jpg
assets/D4.jpg
assets/D5.jpg
assets/D6.jpg
assets/D7.jpg
assets/D8.jpg
assets/Go-tyme.png
assets/MasterCard_Logo.svg.png
assets/Maya.jpg
assets/Metrobank.jpg
assets/Paymaya.png
assets/SecurityBank.png
assets/SweepxPress.png
assets/Visa.png
assets/cleanhouse.png
assets/gcash-logo.png
assets/r10.jpg
assets/r6.jpg
assets/r7.jpg
assets/r8.png
assets/sweepxpress_logo.png
content/about.html
```

## 7. Optional data

**One-off stock correction (order #127).** Only if the live database has order #127 as a *cancelled* order whose stock
was never returned. It does nothing if the order is not cancelled or the correction already exists:

<details>
<summary>SQL</summary>

```sql
-- SweepXpress: one-time na pagbalik ng stock ng order #127
-- (na-cancel bago pa ang Phase 9f, kaya hindi naibalik ang stock noon).
--
-- Paano: phpMyAdmin > piliin ang database > SQL tab > i-paste lahat > Go.
-- Ligtas patakbuhin ulit: walang gagawin kapag may "Order #127 cancelled..." IN log na,
-- o kapag hindi "cancelled" ang status ng order #127.

START TRANSACTION;

-- 1) Gumawa ng inventory row sa location 1 kung wala pa (karaniwang mayroon na)
INSERT INTO inventory (product_id, location_id, quantity, updated_at)
SELECT oi.product_id, 1, 0, NOW()
FROM order_items oi
JOIN orders o ON o.id = oi.order_id AND o.status = 'cancelled'
WHERE oi.order_id = 127
  AND NOT EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = oi.product_id AND i.location_id = 1)
  AND NOT EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.movement_type = 'IN' AND sm.remark LIKE 'Order #127 cancelled%')
GROUP BY oi.product_id;

-- 2) Ibalik ang dami sa inventory (location 1)
UPDATE inventory i
JOIN (
    SELECT oi.product_id, SUM(oi.quantity) AS qty
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id AND o.status = 'cancelled'
    WHERE oi.order_id = 127
    GROUP BY oi.product_id
) t ON t.product_id = i.product_id
SET i.quantity = i.quantity + t.qty,
    i.updated_at = NOW()
WHERE i.location_id = 1
  AND NOT EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.movement_type = 'IN' AND sm.remark LIKE 'Order #127 cancelled%');

-- 3) I-log bilang IN (dito rin nalalaman sa susunod na tapos na ito)
INSERT INTO stock_movements (product_id, location_id, movement_type, quantity, remark, user_name, created_by, created_at)
SELECT oi.product_id, 1, 'IN', SUM(oi.quantity), 'Order #127 cancelled - stock returned (backfill)', 'System', 'System', NOW()
FROM order_items oi
JOIN orders o ON o.id = oi.order_id AND o.status = 'cancelled'
WHERE oi.order_id = 127
  AND NOT EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.movement_type = 'IN' AND sm.remark LIKE 'Order #127 cancelled%')
GROUP BY oi.product_id;

COMMIT;

-- Tingnan ang resulta
SELECT sm.product_id, p.name, sm.quantity AS returned, i.quantity AS stock_now, sm.created_at
FROM stock_movements sm
JOIN products p ON p.id = sm.product_id
LEFT JOIN inventory i ON i.product_id = sm.product_id AND i.location_id = 1
WHERE sm.remark LIKE 'Order #127 cancelled%';
```

</details>

**Demo data** (`database/demo/*.sql`): adds fake suppliers, about 150 orders and restock orders so the dashboard,
forecasts and scorecard are not empty. Everything is marked `[DEMO]` and `remove_demo_data.sql` removes it again.
Use it only if the live database has no real customer orders you care about, in this order: `catalog.sql` (only on an
empty catalogue), `suppliers.sql`, `sales.sql`, `purchase_orders.sql`. Skip `accounts.sql` and `tools/setup_demo.php` on the live site: with
`DEMO_MODE=0` the demo accounts cannot receive a sign-in code, so they could never sign in (and their password is public).

## 8. Smoke test

Hard-refresh the browser (Ctrl+F5) after uploading.

| Check | Expected |
|---|---|
| `/sweepxpress/` and a product page | Load with images; no "Warning" text anywhere |
| `/sweepxpress/terms.php` | Section 6 says returns within 7 days for damaged or incorrect items |
| Sign in with a real admin account (email code) | Lands on the dashboard, with a **Copilot** strip |
| `admin/suppliers.php`, `reorder.php`, `reorder_points.php`, `supplier_scorecard.php`, `purchase_orders.php`, `inventory.php` | All open without errors |
| Add a test supplier in `suppliers.php` | Saved and shown as a card |
| phpMyAdmin → `user_logs`, newest rows | A `LOGIN_SUCCESS` row for your sign-in with a real, increasing `id` (not 0) |
| Copilot button → "Give me today's brief" | A short summary, or one of the messages in `docs/admin-copilot.md` |
| `/sweepxpress/.env`, `/sweepxpress/database/schema.sql`, `/sweepxpress/README.md` | **Forbidden** (403 or 404) |

If the Copilot says *"Could not reach the AI service"*, the free host is blocking outbound requests. Everything else
keeps working; the Copilot then only runs locally.

The stock-alert email script (`admin/cron_stock_alert.php`) needs a scheduler and only runs from the command line, so
it does nothing on this hosting. That is expected.

PHP errors are in the InfinityFree control panel under **Error Logs**; lines starting with `copilot` come from the
assistant.

## 9. If something goes wrong

- **A page breaks after the upload:** re-upload the previous version of that file from your FTP backup. The database
  does not need to change, because the migrations only added tables and columns.
- **A migration fails:** nothing else has been uploaded yet, so the site still runs on the old code. Fix or skip it, or
  restore the export from step 1 (phpMyAdmin → Import).
- **Sign-in stops working:** check that `.env` still has the `MAIL_*` keys. Sign-in sends a code by email.

## 10. Security reminders

- Rotate the Gmail App Password and the database password if they were ever pasted anywhere outside `.env`.
- `GOOGLE_REDIRECT_URI` must use `https`.
- Keep `DEMO_MODE=0`.
- The Groq key belongs only in the live `.env`; it is free but still a secret.
