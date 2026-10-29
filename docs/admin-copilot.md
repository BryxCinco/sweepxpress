# Admin Copilot

A chat assistant for the store admin. It answers questions about stock, sales, purchase orders and suppliers by
calling a fixed set of **read-only tools** on the store's own database. It cannot change anything.

It appears as a floating button on every admin page, plus a "Copilot" strip on the dashboard with a one-click
**Daily brief**.

## How it works

```
Admin types a question
  → admin/copilot_api.php   (admin only, POST, CSRF token, rate limits)
  → includes/groq_client.php sends the question + the list of tools to Groq
  → Groq asks for a tool  ("get_low_stock", "get_supplier_scorecard", ...)
  → includes/copilot_tools.php runs it with prepared statements and returns a small JSON result
  → repeat (max 4 rounds), then Groq writes the final answer
  → the answer and the names of the tools used are shown in the chat
```

The model never writes SQL. It can only pick a tool and fill in whitelisted parameters (a period, a limit, a name).

### Tools

| Tool | Answers |
|---|---|
| `get_business_snapshot` | Headline numbers right now (used by the Daily brief) |
| `get_sales_summary` | Sales and orders for a period, with change vs the previous period |
| `get_top_products` | Best sellers, or products that barely sell |
| `get_low_stock` | Items at or below their reorder point, with days left and supplier lead time |
| `get_stock_forecast` | Days of stock left from recent sales speed |
| `get_reorder_suggestions` | Suggested reorder points and order quantities |
| `get_orders_overview` | Orders by status, stale pending orders, cancellation requests |
| `get_purchase_orders` | Open, overdue, arriving, draft or received restock orders |
| `get_supplier_scorecard` | On-time %, fill rate, lead time and price changes per supplier |
| `find_product` | Price, cost, margin, stock and supplier of a product |

## Setup

1. Create a free key at <https://console.groq.com> (API Keys).
2. Add to `.env` (never commit this file):

   ```
   GROQ_API_KEY=<your key>
   GROQ_MODEL=openai/gpt-oss-120b
   ```

3. Run the migration `database/migrations/2026-10-29_8e_copilot_log.sql` (creates `copilot_log`).
4. Check the connection from a terminal:

   ```
   php tools/groq_check.php
   ```

   It prints whether the key works, which models your account can use, and whether `GROQ_MODEL` is one of them.
5. Reload any admin page and open the Copilot button.

Without a key the button still appears and explains what is missing. Customers never see it.

### Settings (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `GROQ_API_KEY` | none | Required |
| `GROQ_MODEL` | `openai/gpt-oss-120b` | Must be a current Groq chat model that supports tool calling |
| `COPILOT_MINUTE_LIMIT` | 3 | Questions per admin per minute |
| `COPILOT_DAILY_LIMIT` | 100 | Questions per admin per 24 hours |
| `COPILOT_GLOBAL_DAILY_LIMIT` | 300 | Questions from all admins per 24 hours |

Groq's free tier allows roughly 8,000 tokens per minute per model, and one question uses about 2,000 to 4,500, so
only 2 to 3 questions fit in a minute. The Copilot shows "try again in N seconds" when Groq says it is busy.

## Security and privacy

- **Admin only**: `is_admin()` on every call, POST only, CSRF token in a header.
- **API key** lives in `.env` and is only used by the server. It never reaches the browser or the logs
  (Groq error messages are scrubbed for anything that looks like a key).
- **Rate limits** per admin per minute and per day, plus a global daily cap. The check **fails closed**: if the
  `rate_limits` table cannot be read, the request is refused instead of allowed.
- **Read-only**: no tool writes to the database. To change something the Copilot points to the right admin page.
- **No personal data is sent to Groq.** Tools return only aggregates, products, suppliers and purchase orders:
  no customer names, emails, phone numbers or addresses, and no supplier contact details.
- **Untrusted text**: product names and notes are treated as data, not instructions. The chat renders answers
  with text nodes only (no `innerHTML`), so a reply cannot inject HTML or scripts.
- **Audit**: `copilot_log` stores who asked, the question (max 500 characters), the tools used, token counts and
  the status. It does **not** store the answers.

## Troubleshooting

| Message / symptom | Cause and fix |
|---|---|
| "The AI model ... is not available on Groq" | The model was retired or renamed. Run `php tools/groq_check.php`, then set `GROQ_MODEL` to one it lists. The Apache error log shows `model_not_found`. |
| "The AI service did not accept the API key" | Wrong or revoked key. Create a new one and update `.env`. |
| "The free AI limit was reached for now" | Groq's per-minute token limit. Wait the number of seconds shown. |
| "Could not reach the AI service" | The server cannot make outbound HTTPS requests (some free hosts block this) or there is no internet. |
| "The assistant is not set up yet" | `GROQ_API_KEY` is empty. |
| "The assistant got confused" | The model produced an invalid tool call. Ask again in different words. |
| No button on a page | It only appears on pages under `/admin/` for a logged-in admin. |

The real reason for any failure is in the PHP error log, on lines starting with `copilot`.

## Choosing a model

Model names on Groq change. Always check what your account can use (`php tools/groq_check.php`). In testing,
`openai/gpt-oss-120b` gave correct, grounded answers (including saying "not enough history" when a scorecard was
empty); the smaller `openai/gpt-oss-20b` was faster but once invented a conclusion from unrelated data.

## Testing without a key

`includes/groq_client.php` sends requests through `$GLOBALS['sx_groq_transport']` when it is set. Tests set it to a
fake that returns scripted tool calls, which covers the tool loop, limits, errors and the endpoint without network
access. The tools themselves can be called directly:

```php
require 'includes/copilot_tools.php';
$result = sx_copilot_run_tool($pdo, 'get_low_stock', ['limit' => 5]);
```

## Deploying

1. Back up the database, then run the `copilot_log` migration.
2. Upload `includes/copilot_tools.php`, `includes/groq_client.php`, `includes/copilot_widget.php`,
   `includes/footer.php`, `admin/copilot_api.php`, `admin/dashboard.php`, `assets/copilot.js`
   (`tools/groq_check.php` is only for a local terminal; it refuses to run in a browser).
3. Add the `GROQ_*` and `COPILOT_*` keys to the live `.env`.
4. Ask one question. If it says it cannot reach the AI service, the host blocks outbound requests; the Copilot then
   only works locally.
