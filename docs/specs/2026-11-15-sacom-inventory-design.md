# SACOM Inventory — Design Spec

Date: 2026-11-15 · Status: approved

## 1. Purpose

A single owner-run record of which stock went from the Main Shop to which branch, what each
branch owes for it, and what has been paid — replacing memory with a ledger.

**Out of scope:** the `mysacom.com` storefront, barcodes, Urdu, email/SMS/WhatsApp notifications,
multiple admins, customer-level POS, supplier payables.

## 2. Actors

| Role | Access |
|---|---|
| **Admin** (one account, the owner) | Everything, at `/admin` |
| **Shop viewer** (any number per Tier 2/3 shop — owner or staff) | Read-only view of **their own shop** at `/shop`. Never sees purchase cost |

The admin creates every username and password and shares them in person. Only the admin adds
products and categories, and only the admin records any stock or money action — including
Tier 2 sales, returns and local stock-ins at branches (via a shop picker / shop page).

## 3. Decisions

| Topic | Decision |
|---|---|
| Currency | RM, stored as `DECIMAL(12,2)` |
| Time zone | Asia/Kuala_Lumpur (stored UTC). Months are calendar months in this zone |
| Prices | Product has optional `purchase_cost` (admin-only, updated from latest stock-in) and one `selling_price`, same for Tier 2 and Tier 3. Price is copied onto the transfer line and frozen |
| Valuation | Branch stock at selling price. Main Shop at purchase cost, falling back to selling price |
| Invoices | One per transfer, both tiers. Number `SAC-YYMM-#####`, sequential, never reused |
| Tier 2 money | Due when **sold**. Admin records the sale; admin later marks paid |
| Tier 3 money | Month-end credit. Admin marks quantities paid; paying records the sale |
| Partial payment | By quantity per invoice line |
| Local stock-in | Branch's own stock: not invoiced, not owed, cannot be returned to Main. Sales consume Main-supplied stock first |
| Returns | Oldest in-shop Main-supplied quantity first, credited at that line's price |
| Tier change | Shop type changes; line quantities keep their state; new tier's rules apply from then |
| Low-stock alerts | Dashboard only. Threshold per category (default 10), alert when qty ≤ threshold. Main Shop and Tier 2 only |
| Admins | One admin account |
| Corrections | Delete and re-enter. Delete reverses its effects, is blocked while later actions depend on it, and is recorded in the audit log |
| Counter sale | Quantity, selling price (pre-filled, editable), optional note |
| Reports | On screen, branded PDF, CSV |
| Devices | Laptop first, usable on phone |
| Hosting | Hostinger Cloud Startup, subdomain (e.g. `inventory.mysacom.com`), own MySQL database |
| Business profile | Name, address, email, phone, logo, invoice footer — set in Settings, not hard-coded |

## 4. Stack

PHP 8.2+, Laravel (current stable), Filament (two panels: `admin`, `shop`), MySQL,
barryvdh/laravel-dompdf for PDFs, Filament export actions for CSV, Vite + Tailwind built before
deploy, Pest for tests, Laravel Pint for style, GitHub Actions CI. Laravel scheduler via one
hPanel cron (nightly `mysqldump`, kept 35 days). Laravel daily log files.

## 5. Phases

| Phase | Delivers |
|---|---|
| **1. Core stock** | Setup + CI, admin login, categories, products, shops + viewer logins, stock-in, local stock-in, transfers, Tier 2 sales, counter sales, returns, delete with guards, ledger, audit log, read-only portal (stock) |
| **2. Credit & invoicing** | Invoices per transfer, line states, clearance screen (partial qty), returns against credit, tier change rules, branded invoice PDF, balances, portal invoices |
| **3. Reports & insight** | Dashboard cards, low-stock alerts, valuation, reports (stock in/out, 6-month product history, unpaid balances, monthly statement) as screen/PDF/CSV, portal history, nightly backup |

Invoice tables are created in Phase 1 (transfers write them) so Phase 2 only adds behaviour.

## 6. Data model

| Table | Key fields |
|---|---|
| `shops` | name, code, `type` (main/tier2/tier3), address, phone, active. Exactly one `main` |
| `users` | name, unique username, password (bcrypt), `role` (admin/viewer), shop_id (viewers), active |
| `categories` | name, `low_stock_threshold` (default 10) |
| `products` | category_id, name, `selling_price`, `purchase_cost` nullable, active |
| `shop_stock` | shop_id, product_id (unique pair), `qty_main`, `qty_local` (unsigned) |
| `stock_transactions` | `type` (stock_in, local_stock_in, transfer, branch_sale, counter_sale, return, payment), shop_id, occurred_at, note, created_by, deleted_at |
| `stock_movements` | transaction_id, shop_id, product_id, `qty` (signed), `source` (main/local), unit_price, unit_cost |
| `invoices` | number, shop_id, transaction_id, issued_at, deleted_at |
| `invoice_lines` | invoice_id, product_id, unit_price, `qty`, `qty_sold`, `qty_paid`, `qty_returned` |
| `payments` | shop_id, paid_at, total, note, deleted_at |
| `line_allocations` | invoice_line_id, `kind` (sold/paid/returned), qty, transaction_id or payment_id |
| `audit_logs` | occurred_at, user_id, action, shop_id, product_id, qty, before/after JSON, note. Append-only |
| `settings` | business profile key/values |

Derived per line: in shop = qty − sold − returned; sold-unpaid = sold − paid.
Shop balance = Σ (qty − returned − paid) × unit_price.

### Action effects

| Action | Stock | Invoice lines |
|---|---|---|
| Stock-in (Main) | Main `qty_main` + | — |
| Local stock-in | branch `qty_local` + | — |
| Transfer | Main −, branch `qty_main` + | new invoice + lines |
| Counter sale | Main − | — |
| Branch sale (Tier 2) | branch `qty_main` − first, then `qty_local` − | `qty_sold` + oldest lines first (Main part only) |
| Pay (Tier 2) | — | `qty_paid` + (≤ sold − paid) |
| Pay (Tier 3) | branch `qty_main` − | `qty_sold` + and `qty_paid` + (≤ in shop) |
| Return | branch `qty_main` −, Main + | `qty_returned` + oldest in-shop lines first |
| Delete | reverse its movements | remove its allocations; blocked if later actions depend on it |

## 7. Rules

- R1 Stock never negative: lock `shop_stock` rows (`FOR UPDATE`) inside the transaction.
- R2 Every action is one DB transaction: movements, totals, invoice, allocations, audit entry.
- R3 Double-submit protection on every form (one-time token).
- R4 Invoice line prices are frozen at transfer.
- R5 `paid ≤ sold ≤ qty − returned`, in code and as DB check constraints.
- R6–R8 Sale/payment/return allocation as in the table above.
- R9 Delete reverses exactly via `line_allocations`; blocked with a message naming the blockers.
- R10 Tier change only changes `type`; audited.
- R11 Line total = qty × price at 2 dp; totals are sums of line totals.
- R12 Invoice numbers generated in-transaction, never reused.
- R13 Six-month history = today back to the same day six months earlier.

## 8. Edge cases

| Case | Behaviour |
|---|---|
| Tier 2 → 3 with sold-unpaid units | Stay due; in-shop units become month-end credit |
| Tier 3 → 2 with unpaid units | In-shop units become "in shop, not yet due"; alerts start |
| Product price changed | New transfers only |
| Product deactivated | Hidden from new transactions; kept in history and stock |
| Shop deactivated | Logins stop; blocked while it has stock or a balance |
| Stock-in without cost | Allowed; valuation falls back to selling price |
| Overpay / oversell / over-return | Rejected with the maximum allowed |
| Concurrent sale of last unit | Row lock: one succeeds, the other gets "only N left" |
| Viewer opens another shop's record | 404 — portal queries are always scoped to the viewer's shop |
| Admin forgets password | `php artisan sacom:reset-admin-password` over SSH; audited |

## 9. Screens

**Admin (`/admin`):** Dashboard · Products (row actions: Stock In, Transfer, Counter Sale) ·
Categories · Stock In · Transfers (prints delivery slip) · Counter Sales · Shops (shop page tabs:
Stock, Invoices, Clearance, History, Logins; actions: Record Sale, Local Stock-In, Return to Main,
Change Tier, manage logins) · Invoices · Reports · Alerts · Audit Log · Settings.

**Shop portal (`/shop`, read-only):** My Dashboard (balance, paid, units, value) · Current Stock ·
Invoices (print/PDF) · History (6 months, monthly statement PDF).

**Documents:** invoice per transfer, monthly statement per shop, reports — one SACOM-branded
layout (teal/orange logo, From/Bill To, lines with status, totals, signature line).

## 10. Security and quality

- Panel access by role; every Filament resource has a Policy; portal queries scoped by
  `auth()->user()->shop_id`. Purchase cost never selected for the portal.
- Server-side validation; login rate limiting; bcrypt; HTTPS; CSRF; `APP_DEBUG=false` in prod;
  secrets only in `.env`.
- Indexed queries, paginated tables (25), SQL aggregation, chunked CSV export.
- Pest tests: each rule and edge case in plain PHP action classes (`TransferStock`,
  `RecordBranchSale`, `RecordPayment`, `ReturnToMain`, `DeleteTransaction`, `ChangeShopTier`),
  plus feature tests for panel access and shop scoping. CI runs tests on MySQL.
