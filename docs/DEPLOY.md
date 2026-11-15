# Deploying to Hostinger (stock.mysacom.com)

Pipeline: `.github/workflows/deploy.yml`. Every push to `main` runs the Pest suite on MySQL 8.4;
if green, it builds a production bundle (no-dev vendor + Vite assets) and rsyncs it over SSH to
Hostinger as a new release, runs migrations, then switches the `current` symlink atomically.

## Server layout

```
/home/u836570940/domains/mysacom.com/
  sacom-inventory/
    releases/<git-sha>/      last 5 kept
    shared/.env              production config (never in git)
    shared/storage/          logs, sessions, uploads
    current -> releases/<sha>
  public_html/stock -> sacom-inventory/current/public   (subdomain web root)
```

## One-time setup

1. **hPanel → Databases:** `u836570940_sacominv` (db + user). Separate from the main site's DB.
2. **hPanel → SSH Access:** SSH enabled; deploy key `github-actions-sacom-deploy` added.
3. **GitHub → Settings → Secrets and variables → Actions:**
   - Secrets: `SSH_PRIVATE_KEY` (contents of `.deploy-keys/hostinger_deploy`, local only), `SSH_HOST` = `147.93.25.68`,
     `SSH_PORT` = `65002`, `SSH_USER` = `u836570940`, `DB_PASSWORD`, `ADMIN_INITIAL_PASSWORD`
   - Optional variables: `PHP_BIN`, `WEB_PHP_HANDLER`, `DB_DATABASE`, `DB_USERNAME`, `APP_DIR`, `WEB_ROOT`
4. Push to `main`. The first deploy writes `shared/.env` from the secrets, generates `APP_KEY`, migrates,
   seeds the Main Shop + `admin` user (password = `ADMIN_INITIAL_PASSWORD`; change it after first login),
   and points `public_html/stock` at the app. Later deploys never overwrite `shared/.env`.
5. The main mysacom.com site, its PHP version and its database are not touched.

## Later

- Cron (Phase 3 backups/scheduler): `* * * * * cd ~/domains/mysacom.com/sacom-inventory/current && php artisan schedule:run >> /dev/null 2>&1`
- Rollback: `ln -sfn releases/<old-sha> current` inside `sacom-inventory/` (migrations are not rolled back).
- Forgot admin password: see design spec §8.
