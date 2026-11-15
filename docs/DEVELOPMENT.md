# SACOM Inventory — Development

Design: [specs/2026-11-15-sacom-inventory-design.md](specs/2026-11-15-sacom-inventory-design.md)

## Local setup (Docker / Laravel Sail)

Needs only Docker. PHP 8.5 and MySQL 8.4 run in containers.

```sh
./vendor/bin/sail up -d                                   # start (first build is slow)
./vendor/bin/sail artisan migrate:fresh --seed --seeder=DemoSeeder   # demo data; prints admin password
./vendor/bin/sail test                                    # run all tests (MySQL)
./vendor/bin/sail pint                                    # format code
./vendor/bin/sail down                                    # stop
```

If `vendor/` is missing, install it first:
`docker run --rm -v "$PWD":/app -w /app composer:2 composer install --ignore-platform-req=ext-intl`

| URL | Who |
|---|---|
| http://localhost:8080/admin | Admin (username `admin`, password printed by the seeder) |
| http://localhost:8080/shop | Shop logins — demo: `home_staff` / `password` (Tier 2), `zen_owner` / `password` (Tier 3) |

## Where things live

| Path | What |
|---|---|
| `app/Domain/Actions/*` | Every stock/money operation (one class each). The only code that changes stock or invoice quantities |
| `app/Domain/Ledger.php` | Locks `shop_stock` rows and writes `stock_movements`; refuses negative stock |
| `app/Domain/OpenLines.php` | Oldest-first allocation of sales/returns to invoice lines |
| `app/Domain/ShopFigures.php` | Balances, units, stock value, low-stock counts (SQL) |
| `app/Filament/Resources/*` | Admin panel (`/admin`) |
| `app/Filament/Shop/*` | Read-only shop portal (`/shop`), always scoped through `ShopScope` |
| `tests/Feature/Domain` | Business-rule tests · `tests/Feature/Admin`, `tests/Feature/Shop` — panel tests |

## Conventions

- Filament closures receive arguments **by parameter name** — use `$query`, `$record`, `$data`, never `$q`.
- Laravel 13 local scopes use the `#[Scope]` attribute, not a `scope` prefix.
- Money: `DECIMAL(12,2)` in the DB, integer sen in PHP (`App\Domain\Money`).
- App time zone is `Asia/Kuala_Lumpur`.
- Business-rule refusals throw `App\Domain\InventoryException`; Filament shows its message via `InventoryForm::run()`.

## Status

- **Phase 1 (core stock): done.** Catalogue, shops + logins, stock-in, local stock-in, transfers (invoice per transfer), Tier 2 sales, counter sales, returns, delete with guards, audit log, read-only portal (dashboard, stock, invoices, 6-month history).
- **Phase 2 (credit & invoicing): next.** Clearance screen (pay by quantity — domain logic `RecordPayment` already built and tested), branded invoice PDF/print, monthly statement.
- **Phase 3:** admin dashboard cards, low-stock alerts page, reports (screen/PDF/CSV), nightly backup, Hostinger deploy.
