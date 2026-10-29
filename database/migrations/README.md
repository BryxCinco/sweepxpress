# Database migrations

Mga schema change na kailangang patakbuhin sa bawat database (local at live server).

- **Patakbuhin ayon sa petsa** sa pangalan ng file, sunod-sunod.
- **Laging mag-mysqldump muna** bago magpatakbo ng anumang migration.
- **I-deploy muna ang code, saka ang migration.** Ligtas ang pagitan dahil "fail open" ang rate limit helpers.
  **Exception: 7a pataas — migration MUNA, saka ang code** (sumusulat ang bagong code sa mga bagong column). Ligtas ito dahil puro dagdag ang 7a, kaya gumagana pa rin ang lumang code.
- Basahin ang comments sa bawat file. Ang ilan (gaya ng 4a) ay dapat patakbuhin nang isa-isang step, may verification.
- Alamin ang version ng DB sa server (`SELECT VERSION();`). May alternatibong query sa 4a para sa MySQL 5.7.
- **Schema lang ang ilagay dito. Huwag na huwag maglagay ng data dump** (may customer data). Ignored pa rin ng git ang lahat ng ibang `.sql`.

| File | Ano |
|---|---|
| `2026-10-26_4a_users_cleanup.sql` | Tanggalin ang dobleng column sa `users`, username backfill, UNIQUE username |
| `2026-10-26_4b_rate_limits.sql` | Gumawa ng `rate_limits` table |
| `2026-10-27_4c_contact_user_seen.sql` | Idagdag ang `contact_messages.user_seen` (dating ginagawa ng contact.php sa runtime) |
| `2026-10-29_7a_supply_chain.sql` | `suppliers`, `purchase_orders`, `purchase_order_items`; `products.supplier_id/cost_price/reorder_point/reorder_qty`; `stock_movements.ref_type/ref_id` |
| `2026-10-29_8e_copilot_log.sql` | `copilot_log` table (tanong, tools, tokens ng Admin Copilot; hindi ang sagot) |
