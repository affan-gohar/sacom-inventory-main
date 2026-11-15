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

1. **hPanel → Advanced → PHP Configuration:** PHP **8.4** (composer.lock needs ≥ 8.4.1). Extensions: intl, gd, zip, bcmath, pdo_mysql.
2. **hPanel → Databases → MySQL:** create a database + user. Note the names (prefixed `u836570940_`).
3. **hPanel → Advanced → SSH Access:** enable SSH; add the deploy **public** key. Note host, port (65002), username.
4. **Server `.env`:** SSH in and run
   ```sh
   mkdir -p ~/domains/mysacom.com/sacom-inventory/shared
   nano ~/domains/mysacom.com/sacom-inventory/shared/.env   # paste .env.production.example, fill DB_*
   ```
   Leave `APP_KEY=` empty for now.
5. **GitHub → Settings → Secrets and variables → Actions** (environment `production` or repo):
   - Secrets: `SSH_PRIVATE_KEY`, `SSH_HOST`, `SSH_PORT` (65002), `SSH_USER`
   - Optional variables: `APP_DIR`, `WEB_ROOT`, `PHP_BIN` (e.g. `/opt/alt/php84/usr/bin/php` if CLI `php -v` isn't 8.4)
6. Push to `main` (or *Actions → Test & Deploy → Run workflow*).
7. After the first deploy, SSH in once:
   ```sh
   cd ~/domains/mysacom.com/sacom-inventory/current
   php artisan key:generate --force && php artisan optimize
   php artisan db:seed --force      # creates Main Shop + admin; prints the admin password once
   ```
8. **hPanel → Security → SSL:** make sure stock.mysacom.com has an active certificate.

## Later

- Cron (Phase 3 backups/scheduler): `* * * * * cd ~/domains/mysacom.com/sacom-inventory/current && php artisan schedule:run >> /dev/null 2>&1`
- Rollback: `ln -sfn releases/<old-sha> current` inside `sacom-inventory/` (migrations are not rolled back).
- Forgot admin password: see design spec §8.
