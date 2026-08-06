# EMC Backend — Deployment Guide

## Overview

This document covers deploying the EMC backend to a production Linux server (Ubuntu 22.04 recommended).

---

## Server Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.3+ |
| PHP extensions | BCMath, Ctype, Curl, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO, PDO_MySQL, Tokenizer, XML |
| MySQL / MariaDB | 8.0+ / 10.6+ |
| Nginx | latest |
| Composer | 2.x |
| Supervisor | (queue workers) |

---

## 1. Upload / Clone

```bash
git clone https://github.com/your-org/emc-backend.git /var/www/emc-backend
cd /var/www/emc-backend
composer install --no-dev --optimize-autoloader
```

---

## 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Set all production values in `.env` (see `docs/production-checklist.md`).

---

## 3. Database

```bash
php artisan migrate --force
```

> `--force` is required in production environments.

---

## 4. Storage

```bash
php artisan storage:link
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

## 5. Caching

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> Clear and rebuild after every deployment:
> ```bash
> php artisan optimize:clear && php artisan optimize
> ```

---

## 6. Nginx Configuration

```nginx
server {
    listen 80;
    server_name api.emc.sa;
    root /var/www/emc-backend/public;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name api.emc.sa;
    root /var/www/emc-backend/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/api.emc.sa/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.emc.sa/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Block direct access to private storage files
    location ^~ /storage/private {
        deny all;
    }
}
```

---

## 7. Queue Workers (Supervisor)

Use the ready-made config in `docs/supervisor.conf`:

```bash
sudo cp docs/supervisor.conf /etc/supervisor/conf.d/emc.conf
# Verify /var/www/emc-backend paths match your server
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start emc:*
sudo supervisorctl status
```

The config runs:
- **emc-worker** (×2) — default queue, `--tries=3 --timeout=90 --max-time=3600`
- **emc-webhook-worker** (×1) — `webhooks,default` queue, `--tries=5`
- **emc-scheduler** (×1) — replaces cron if preferred

After every deployment, restart workers gracefully:

```bash
php artisan queue:restart        # signals workers to stop after current job
sudo supervisorctl restart emc:* # supervisor brings fresh workers up
```

---

## 8. Scheduler (Laravel Cron)

Add to `crontab -e` for `www-data`:

```cron
* * * * * php /var/www/emc-backend/artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs (defined in `routes/console.php`):

| Job | Schedule |
|-----|----------|
| `emc:prune-notifications --days=90` | Daily 02:30 |
| `queue:prune-failed --hours=168` | Weekly Sunday 03:00 |
| `sanctum:prune-expired --hours=24` | Daily 03:30 |
| Log rotation (>100 MB) | Daily 04:00 |
| `emc:backup --db --prune-days=30` | Daily 01:00 (if `ENABLE_SCHEDULED_BACKUP=true`) |

---

## 9. SSL (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.emc.sa
```

Auto-renewal is handled by Certbot's systemd timer.

---

## 10. Zero-Downtime Deployment

Use the bundled `scripts/deploy.sh` script:

```bash
sudo bash /var/www/emc-backend/scripts/deploy.sh
```

Or run steps manually:

```bash
cd /var/www/emc-backend

git pull origin main
composer install --no-dev --optimize-autoloader

# Production readiness gate — aborts if critical failures found
php artisan emc:production-check || exit 1

php artisan down --refresh=15
php artisan migrate --force
php artisan optimize:clear && php artisan optimize

php artisan queue:restart
sudo supervisorctl restart emc:*

php artisan up

# Verify
curl -sf https://api.emc.sa/api/health | python3 -m json.tool
```

---

## 11. Backups

```bash
# Database backup (→ storage/app/backups/):
php artisan emc:backup --db

# Storage backup (excludes the backups dir itself):
php artisan emc:backup --storage

# Both:
php artisan emc:backup --all

# With pruning — remove backup files older than 30 days:
php artisan emc:backup --db --prune-days=30

# Enable nightly automated backups via scheduler:
# Set ENABLE_SCHEDULED_BACKUP=true in .env
```

---

## 12. Restore from Backup

### Database restore

```bash
# 1. Locate the backup file
ls -lh storage/app/backups/*.sql

# 2. Restore to a temporary database first to verify integrity
mysql -u root -p -e "CREATE DATABASE emc_restore;"
mysql -u root -p emc_restore < storage/app/backups/2026-05-31_01-00-00_db_emc_db.sql

# 3. Spot-check row counts
mysql -u root -p emc_restore -e "
  SELECT 'users' AS tbl, COUNT(*) FROM users
  UNION ALL SELECT 'registrations', COUNT(*) FROM registrations
  UNION ALL SELECT 'payments', COUNT(*) FROM payments;
"

# 4. If counts look right, restore to production DB (DESTRUCTIVE)
# php artisan down
# mysql -u root -p emc_db < storage/app/backups/2026-05-31_01-00-00_db_emc_db.sql
# php artisan migrate --force   # apply any pending migrations on top
# php artisan up
```

### Storage restore

```bash
# 1. Locate the archive
ls -lh storage/app/backups/*.tar.gz

# 2. Extract to a temp location to verify
mkdir /tmp/emc_storage_restore
tar -xzf storage/app/backups/2026-05-31_01-00-00_storage.tar.gz -C /tmp/emc_storage_restore

# 3. Inspect key subdirectories
ls /tmp/emc_storage_restore/

# 4. If correct, replace storage/app (excluding backups dir)
# rsync -av --exclude='backups/' /tmp/emc_storage_restore/ storage/app/
# chown -R www-data:www-data storage/
```

---

## 13. Logging

Logs are in `storage/logs/laravel.log`.

In production, set:
```env
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
```

For centralized logging, configure `LOG_STACK=daily,slack` with a `LOG_SLACK_WEBHOOK_URL`.

---

## 14. Error Tracking (Sentry — optional)

```bash
composer require sentry/sentry-laravel
```

Set in `.env`:
```env
SENTRY_LARAVEL_DSN=https://your-dsn@sentry.io/project
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_LOG_LEVEL=error
LOG_STACK=daily,sentry
```

Verify:
```bash
php artisan sentry:test
```

---

## 15. Post-Deployment Verification

```bash
# Full production check (exit 1 on failures):
php artisan emc:production-check

# Health endpoint — checks DB, cache, queue, storage:
curl -s https://api.emc.sa/api/health | python3 -m json.tool

# Expected response shape:
# {
#   "status": "ok",
#   "app_version": "1.0.0",
#   "php_version": "8.3.x",
#   "checks": {
#     "database": {"status": "ok", "latency": "2.1ms"},
#     "cache":    {"status": "ok", "latency": "0.8ms"},
#     "queue":    {"status": "ok", "pending_jobs": 0, "failed_jobs": 0},
#     "storage":  {"status": "ok", "latency": "1.2ms"}
#   }
# }

# Test auth:
curl -X POST https://api.emc.sa/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@emc.sa","password":"your-password"}'

# Check scheduled tasks:
php artisan schedule:list
```
