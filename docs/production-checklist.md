# EMC Backend — Production Readiness Checklist

Use this checklist before every production deployment. Mark each item when verified.

---

## Production Readiness Command

Run this first — it checks most items below automatically:

```bash
php artisan emc:production-check
```

Exit code 0 = all checks passed (warnings may exist). Exit code 1 = critical failures must be resolved before deployment.

---

## Application

- [ ] `php artisan emc:production-check` exits with code 0
- [ ] `GET /api/health` returns HTTP 200 with all checks `"status": "ok"`
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` is set (run `php artisan key:generate` if missing)
- [ ] `APP_URL` is set to the production API URL (e.g. `https://api.emc.sa`)
- [ ] `APP_VERSION` is set (used by health endpoint)
- [ ] `FRONTEND_URL` is set to the React app URL (e.g. `https://app.emc.sa`)

## Database

- [ ] `DB_CONNECTION=mysql`
- [ ] `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` are all set
- [ ] Migrations have been run: `php artisan migrate --force`
- [ ] Database user has only necessary privileges (no SUPER/GRANT OPTION)
- [ ] Daily backups are configured (mysqldump, Forge backups, or managed DB snapshots)

## Storage & Files

- [ ] `php artisan storage:link` has been run
- [ ] `storage/` and `bootstrap/cache/` have `755` or `775` permissions
- [ ] Owner is `www-data` (or the webserver user)
- [ ] Private files (certificates, submissions, documents) are stored in `private` disk
- [ ] Public marketing assets are on `public` disk only
- [ ] S3 / cloud storage configured if file redundancy is required:
  - `FILESYSTEM_CLOUD=s3`
  - `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`

## Auth & Security

- [ ] `SANCTUM_STATEFUL_DOMAINS` set if using cookie-based auth (SPA)
- [ ] `SESSION_SECURE_COOKIE=true` if using cookies
- [ ] `CORS_ALLOWED_ORIGINS` contains only the real frontend origins
- [ ] `CORS_MAX_AGE=86400` (24h preflight cache in production)
- [ ] Rate limits are active (verify `php artisan route:list` shows `throttle:auth`, `throttle:registration`, etc.)

## Mail

- [ ] `MAIL_MAILER` set (smtp, ses, mailgun, postmark)
- [ ] `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` configured
- [ ] `MAIL_FROM_ADDRESS` set to a real address (e.g. `noreply@emc.sa`)
- [ ] `MAIL_FROM_NAME` set (e.g. `EMC Platform`)
- [ ] Test email delivery with `php artisan tinker` → `Mail::raw('test', fn($m) => $m->to('you@test.com')->subject('Test'))`

## Payments

- [ ] `STRIPE_KEY` (publishable) is set
- [ ] `STRIPE_SECRET` is set
- [ ] `STRIPE_WEBHOOK_SECRET` is set
- [ ] Stripe webhook endpoint registered in Stripe Dashboard: `POST https://api.emc.sa/api/webhooks/stripe`
- [ ] `PAYPAL_CLIENT_ID` is set (if using PayPal)
- [ ] `PAYPAL_CLIENT_SECRET` is set
- [ ] PayPal webhook registered in PayPal Developer Console
- [ ] `ALLOW_FAKE_PAYMENTS` is `false` (or not set) in production

## Queues

- [ ] `QUEUE_CONNECTION=database` (or `redis` if Redis is available)
- [ ] `php artisan queue:table && php artisan migrate` has been run
- [ ] Supervisor is configured and running (`supervisorctl status`)
- [ ] Queue worker restarts automatically after deploy

## Scheduler

- [ ] Cron entry added: `* * * * * www-data php /var/www/emc-backend/artisan schedule:run`
- [ ] Cron is active: `sudo systemctl status cron`

## SSL & Network

- [ ] SSL certificate is valid and auto-renews (Let's Encrypt / ACM)
- [ ] HTTPS redirect is enforced in Nginx config
- [ ] HSTS header is present (`Strict-Transport-Security`)
- [ ] Firewall allows only ports 80, 443 (and 22 for SSH from known IPs)

## Caching

- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `php artisan event:cache`

## Logging

- [ ] `LOG_CHANNEL=daily`
- [ ] `LOG_LEVEL=warning` (or `error` for minimal noise)
- [ ] Log rotation is configured (`logrotate` or Laravel's daily driver)
- [ ] Centralized logging is set up (optional but recommended)

## Backups

- [ ] `php artisan emc:backup --db` runs without error
- [ ] Backup file written to `storage/app/backups/`
- [ ] Restore test completed (restore latest backup to a temp DB, verify row counts)
- [ ] `ENABLE_SCHEDULED_BACKUP=true` in `.env` (daily 01:00 via scheduler)
- [ ] Backup files are copied off-server (S3 / SCP / external storage)

## Error Tracking

- [ ] Sentry or equivalent configured (`SENTRY_LARAVEL_DSN` in `.env`)
  - If not using Sentry: Slack webhook or email alerting configured for critical errors
- [ ] `php artisan sentry:test` succeeds (if Sentry installed)

## Monitoring

- [ ] `GET /api/health` returns `{"status":"ok"}` with HTTP 200
- [ ] Uptime monitor pointing to `GET https://api.emc.sa/api/health` (5-min interval)
- [ ] Alert configured when health endpoint returns 503 (degraded)
- [ ] Queue `failed_jobs` count monitored — alert when > 0

## Pre-launch Verification

- [ ] `php artisan test` passes (all tests green)
- [ ] `php artisan route:list` shows no unexpected public admin routes
- [ ] Admin credentials are changed from defaults
- [ ] API responds correctly to a test login
- [ ] Certificate verification endpoint tested: `GET /api/certificates/verify/{code}`
- [ ] Fake payment flow disabled in production

---

## Scheduled Jobs

- [ ] Cron entry active: `* * * * * www-data php /var/www/emc-backend/artisan schedule:run >> /dev/null 2>&1`
- [ ] `php artisan schedule:list` shows:
  - `emc:prune-notifications` — daily 02:30
  - `queue:prune-failed` — weekly Sunday 03:00
  - `sanctum:prune-expired` — daily 03:30
  - `emc:backup` — daily 01:00 (if `ENABLE_SCHEDULED_BACKUP=true`)
- [ ] Scheduler log exists: `storage/logs/scheduler.log`

## Quick Commands

```bash
# Full production readiness check:
php artisan emc:production-check

# Full deploy optimization:
php artisan optimize:clear && php artisan optimize

# Check queue status:
php artisan queue:monitor

# Clear failed jobs:
php artisan queue:flush

# Manually trigger scheduler:
php artisan schedule:run

# Manual backup:
php artisan emc:backup --all

# Prune old notifications manually:
php artisan emc:prune-notifications --days=90

# View recent logs:
tail -n 100 storage/logs/laravel.log

# View scheduler log:
tail -n 50 storage/logs/scheduler.log
```
