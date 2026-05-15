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

Create `/etc/supervisor/conf.d/emc-worker.conf`:

```ini
[program:emc-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/emc-backend/artisan queue:work database --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/emc-queue-worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start emc-queue-worker:*
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

## Post-Deployment Verification

```bash
# Health check
curl https://api.emc.sa/up

# Test auth endpoint
curl -X POST https://api.emc.sa/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@emc.sa","password":"your-password"}'
```
