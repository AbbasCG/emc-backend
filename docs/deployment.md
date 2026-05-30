# EMC Backend — Deployment Guide

## Overview

This document covers deploying the EMC backend to a production Linux server (Ubuntu 22.04 recommended).

---

## Server Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.2+ |
| PHP extensions | BCMath, Ctype, Curl, DOM, Fileinfo, JSON, Mbstring, OpenSSL, PCRE, PDO, PDO_MySQL, Tokenizer, XML |
| MySQL / MariaDB | 8.0+ / 10.6+ |
| Nginx or Apache | latest |
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

> Clear all caches after every deployment:
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
    index index.php;

    # Force HTTPS redirect
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
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Block direct access to storage private files
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
# Edit the file to confirm /var/www/emc-backend paths are correct
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

Add to `/etc/crontab` or `crontab -e` for `www-data`:

```cron
* * * * * www-data php /var/www/emc-backend/artisan schedule:run >> /dev/null 2>&1
```

---

## 9. SSL (Let's Encrypt)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d api.emc.sa
```

Auto-renewal is handled by Certbot's systemd timer.

---

## 10. Zero-Downtime Deployment Script

```bash
#!/bin/bash
set -e

cd /var/www/emc-backend

git pull origin main
composer install --no-dev --optimize-autoloader

php artisan down --secret="deploy-token-here"
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan up

sudo supervisorctl restart emc-queue-worker:*

echo "Deployment complete."
```

---

## 11. Logging

Logs are in `storage/logs/laravel.log`.

In production, set:
```env
LOG_CHANNEL=daily
LOG_LEVEL=warning
```

Consider integrating with Papertrail, Logtail, or Datadog for centralized logging.

---

## 10. Zero-Downtime Deployment Script (updated)

```bash
#!/bin/bash
set -e
cd /var/www/emc-backend

git pull origin main
composer install --no-dev --optimize-autoloader

php artisan down --refresh=15

# Production readiness gate — abort if critical failures found
php artisan emc:production-check || { php artisan up; echo "Deploy aborted: production check failed."; exit 1; }

php artisan migrate --force
php artisan optimize:clear && php artisan optimize

php artisan queue:restart
sudo supervisorctl restart emc:*

php artisan up

# Verify health
curl -sf https://api.emc.sa/api/health | grep '"status":"ok"' || echo "WARNING: health check degraded"
echo "Deployment complete."
```

---

## 11. Backups

```bash
# Database backup (output to storage/app/backups/):
php artisan emc:backup --db

# Storage backup:
php artisan emc:backup --storage

# Both:
php artisan emc:backup --all

# Enable nightly automated backups via scheduler:
# Set ENABLE_SCHEDULED_BACKUP=true in .env
```

---

## Post-Deployment Verification

```bash
# Full production check (exit 1 on failures):
php artisan emc:production-check

# Health endpoint — checks DB, cache, queue, storage:
curl -s https://api.emc.sa/api/health | python3 -m json.tool

# Test auth endpoint
curl -X POST https://api.emc.sa/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@emc.sa","password":"your-password"}'

# Check scheduled tasks are wired up:
php artisan schedule:list
```
