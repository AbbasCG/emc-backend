# EMC Backend — Production Deployment Checklist

**Run this document top-to-bottom for every production deployment.**
Use `scripts/deploy.sh` for automated deploys (it executes steps 3–9 automatically).
This checklist is the manual reference and verification layer.

---

## Pre-Deployment Gate (run before every deploy)

```bash
# 1. Run the full test suite — must be 100% green
php artisan test

# 2. Run the production readiness check — must exit code 0
php artisan emc:production-check

# 3. Snapshot current git state
git log --oneline -5
git status
```

- [ ] `php artisan test` → all tests pass
- [ ] `php artisan emc:production-check` → exits 0
- [ ] No uncommitted changes that should be included

---

## Step 1 — Pre-Deploy Database Backup

```bash
php artisan emc:backup --db --prune-days=30
# Verify backup was written:
ls -lh storage/app/backups/
```

- [ ] Backup file written to `storage/app/backups/`
- [ ] File size is non-zero and matches expected DB size
- [ ] Copy backup off-server (S3 / SCP) if critical deploy

---

## Step 2 — Notify Maintenance Window

- [ ] Notify team in Slack / internal channel before extended maintenance
- [ ] Confirm no pending payments are in `pending_payment` status (check admin panel)
- [ ] No active queue jobs that must not be interrupted:
  ```bash
  php artisan queue:monitor
  ```

---

## Step 3 — Pull & Install

```bash
cd /var/www/emc-backend

git pull origin main
# OR specific branch:
# git checkout release/v2.x && git pull origin release/v2.x

composer install --no-dev --optimize-autoloader --no-interaction
```

- [ ] `git pull` succeeds, shows expected commits
- [ ] `composer install` completes without errors
- [ ] No new packages with known CVEs introduced (check with `composer audit`)

---

## Step 4 — Maintenance Mode

```bash
php artisan down --refresh=15
```

- [ ] `/up` endpoint returns 503
- [ ] Frontend shows maintenance page or returns expected error

---

## Step 5 — Database Migrations

```bash
php artisan migrate --force
```

- [ ] All migrations succeed
- [ ] No "Column already exists" or "Table already exists" errors
- [ ] Verify migration list: `php artisan migrate:status`

> **Rollback if migrations fail:**
> ```bash
> php artisan migrate:rollback
> php artisan up
> ```

---

## Step 6 — Cache Rebuild

```bash
php artisan optimize:clear
php artisan optimize
```

This runs: `config:cache`, `route:cache`, `view:cache`, `event:cache`.

- [ ] No cache errors
- [ ] `bootstrap/cache/` contains fresh `config.php`, `routes.php`, `events.php`

---

## Step 7 — Queue Workers

```bash
# Signal workers to stop after current job (they self-restart via Supervisor):
php artisan queue:restart

# Wait 5 seconds, then restart via Supervisor:
sleep 5
sudo supervisorctl restart emc:*

# Verify workers are running:
sudo supervisorctl status
```

- [ ] All Supervisor processes show `RUNNING` status
- [ ] `emc-worker:00`, `emc-worker:01` running
- [ ] `emc-webhook-worker` running
- [ ] `emc-scheduler` running

---

## Step 8 — Bring App Online

```bash
php artisan up
```

- [ ] `/up` returns HTTP 200
- [ ] `GET /api/health` returns `{"status":"ok"}`

---

## Step 9 — Post-Deploy Verification

```bash
# Deep health check (DB, cache, queue, storage):
curl -s https://api.emc.sa/api/health | python3 -m json.tool

# Full production readiness check:
php artisan emc:production-check

# Test authentication:
curl -X POST https://api.emc.sa/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@emc.sa","password":"YOUR_PASSWORD"}'

# Check failed jobs (should be 0):
php artisan queue:monitor

# Check scheduled tasks:
php artisan schedule:list
```

- [ ] `/api/health` → `"status":"ok"` (or `"warn"` with 0 failed_jobs)
- [ ] `emc:production-check` → exit 0
- [ ] Admin login succeeds, token returned
- [ ] Failed jobs count = 0
- [ ] 5 scheduled tasks visible in `schedule:list`

---

## Rollback Plan

### Application Rollback (code only)

```bash
# Put app in maintenance:
php artisan down

# Revert to previous git tag / commit:
git checkout <previous-tag-or-sha>
composer install --no-dev --optimize-autoloader

# Rebuild caches:
php artisan optimize:clear && php artisan optimize

# Restart workers:
php artisan queue:restart && sudo supervisorctl restart emc:*

# Bring back up:
php artisan up
```

### Database Rollback (after bad migration)

```bash
# Roll back the last migration batch:
php artisan migrate:rollback

# If multiple migrations need reverting, specify steps:
php artisan migrate:rollback --step=3
```

### Full Restore from Backup

```bash
# 1. Find latest backup
ls -lh storage/app/backups/*.sql

# 2. Restore to temp DB first to verify
mysql -u root -p -e "CREATE DATABASE emc_restore;"
mysql -u root -p emc_restore < storage/app/backups/YYYY-MM-DD_HH-MM-SS_db_emc_db.sql

# 3. Spot-check (verify row counts match expected)
mysql -u root -p emc_restore -e "SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM registrations;"

# 4. If counts are correct, restore to production (DESTRUCTIVE)
# php artisan down
# mysql -u root -p emc_db < storage/app/backups/YYYY-MM-DD_HH-MM-SS_db_emc_db.sql
# php artisan migrate --force   # re-apply any pending migrations
# php artisan up
```

---

## Environment Variables — Required for Production

All these must be set and verified by `php artisan emc:production-check`:

```env
# Core
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generated>
APP_URL=https://api.emc.sa
APP_VERSION=<release-version>
FRONTEND_URL=https://app.emc.sa

# Database
DB_CONNECTION=mysql
DB_HOST=<host>
DB_DATABASE=<db>
DB_USERNAME=<user>
DB_PASSWORD=<password>

# Cache & Queue
CACHE_STORE=database          # or redis
QUEUE_CONNECTION=database     # or redis

# Mail (not log/array)
MAIL_MAILER=smtp              # or ses, mailgun, postmark
MAIL_FROM_ADDRESS=noreply@emc.sa
MAIL_FROM_NAME="EMC Platform"

# Payments
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
PAYPAL_MODE=live
PAYPAL_LIVE_CLIENT_ID=<id>
PAYPAL_LIVE_CLIENT_SECRET=<secret>
PAYPAL_WEBHOOK_ID=<id>

# Security
BCRYPT_ROUNDS=12
SANCTUM_TOKEN_EXPIRATION=1440
SESSION_SECURE_COOKIE=true
ALLOW_FAKE_PAYMENTS=false     # must be false or unset

# Logging
LOG_CHANNEL=daily
LOG_LEVEL=warning

# Backups (optional)
ENABLE_SCHEDULED_BACKUP=true

# Sentry (optional)
SENTRY_LARAVEL_DSN=https://...@sentry.io/...
```

---

## Scheduler Cron Entry

Add to `/etc/crontab` or `crontab -e` for `www-data`:

```cron
* * * * * php /var/www/emc-backend/artisan schedule:run >> /dev/null 2>&1
```

Verify: `crontab -l | grep artisan`

---

## Storage Setup (first deploy or after server change)

```bash
php artisan storage:link
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

Verify:
```bash
ls -la public/ | grep storage   # should show symlink
ls -la storage/app/             # should show backups/, private/, public/
```

---

## SSL / HTTPS

```bash
# Check certificate expiry:
openssl s_client -connect api.emc.sa:443 2>/dev/null | openssl x509 -noout -dates

# Auto-renew (Let's Encrypt):
sudo certbot renew --dry-run
```

- [ ] Certificate valid for > 30 days
- [ ] HTTPS redirect active (HTTP → HTTPS)
- [ ] HSTS header present

---

## Final Sign-Off

| Check | Verified by | Time |
|-------|-------------|------|
| All tests green | | |
| production-check exits 0 | | |
| /api/health → ok | | |
| Admin login works | | |
| Supervisor workers running | | |
| No failed jobs | | |
| SSL certificate valid | | |
| Backup written | | |

**Deployed by:** ________________  
**Deploy time:** ________________  
**Commit SHA:** ________________  
