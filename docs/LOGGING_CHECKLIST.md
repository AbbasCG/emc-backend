# EMC Backend — Logging Checklist

## Production Environment Values

Confirm these are set in `.env` before every deployment:

```env
APP_DEBUG=false           # CRITICAL — never true in production
LOG_CHANNEL=stack         # Uses LOG_STACK channels
LOG_STACK=daily           # Rotate logs daily; add ,sentry if Sentry installed
LOG_LEVEL=error           # Only log errors and above (error, critical, alert, emergency)
LOG_DAILY_DAYS=14         # Keep 14 days of log files
```

> **LOG_LEVEL options (least → most verbose):** `emergency` | `alert` | `critical` | `error` | `warning` | `notice` | `info` | `debug`
>
> Use `error` in production. Use `warning` if you want to capture unusual-but-non-fatal events. Never use `debug` or `info` in production.

---

## What Is and Is Not Logged

### ✅ Safe to log (currently implemented)

| Log call | Location | Content |
|----------|----------|---------|
| `Log::error(...)` | PaymentController | Stripe secret missing — no sensitive data |
| `Log::critical(...)` | PaymentController | PayPal webhook_id missing — no sensitive data |
| `Log::info('Certificate issued', ...)` | IssueCertificateJob | Certificate ID only |
| `Log::info('ProcessPaymentWebhookJob: already processed', ...)` | ProcessPaymentWebhookJob | Payment ID only |
| `Log::warning('...payment not found', ...)` | ProcessPaymentWebhookJob | Payment ID only |
| `Log::info('GenerateAiInsightsJob completed', ...)` | GenerateAiInsightsJob | Count only |
| `Log::error/critical` | All jobs | Exception message (no secrets) |
| Audit log entries | AuditLogService | Admin actions, entity IDs (DB table, not log file) |

### ✅ Fixed (removed in production hardening)

| Was logged | File | Status |
|-----------|------|--------|
| Full registration payload (name, email, phone, city) | `StoreRegistrationRequest.php` | **Removed** — was marked "Temporary", contained PII |
| Public course count + filter params on every list request | `CourseController.php` | **Removed** — operational noise with no value |

### ❌ Never log these

- **Passwords** (plaintext or hashed)
- **Sanctum tokens** (Bearer tokens)
- **Stripe secret key** (`STRIPE_SECRET`)
- **Stripe webhook secret** (`STRIPE_WEBHOOK_SECRET`)
- **PayPal client secret** (`PAYPAL_LIVE_CLIENT_SECRET`)
- **Stripe raw webhook payloads** (contain card metadata)
- **Full HTTP request bodies** on auth endpoints
- **Database connection strings** with passwords
- **`.env` file contents**
- **User passwords during reset flow**

---

## Verify No Sensitive Logging (Run Before Deploy)

Search for potentially unsafe log calls:

```bash
# Find Log::debug calls (should be zero in production — LOG_LEVEL=error suppresses them
# but they shouldn't exist to avoid confusion):
grep -rn "Log::debug\|Log::info" app/ --include="*.php" | grep -v vendor | grep -v ".git"

# Find any raw $request->all() being logged:
grep -rn "Log::" app/ --include="*.php" | grep "request\->all\|request\->input\|->all()"

# Find any token/password strings in log calls:
grep -rn "Log::" app/ --include="*.php" | grep -i "token\|password\|secret\|key\|webhook"
```

Expected output for the last two commands: **empty** (no matches).

---

## Log Rotation

### Laravel daily driver (automatic)

With `LOG_STACK=daily` and `LOG_DAILY_DAYS=14`, Laravel automatically creates dated log files:

```
storage/logs/
├── laravel-2026-05-31.log
├── laravel-2026-05-30.log
└── ...
```

Files older than 14 days are deleted automatically by PHP's `RotatingFileHandler`.

### Scheduler-based safety net

The scheduler also enforces a 100MB size cap on `laravel.log`:

```php
// routes/console.php — runs daily at 04:00
Schedule::call(function () {
    $logPath = storage_path('logs/laravel.log');
    if (file_exists($logPath) && filesize($logPath) > 100 * 1024 * 1024) {
        file_put_contents($logPath, '');
        Log::info('Log file rotated by scheduler (exceeded 100 MB).');
    }
})->dailyAt('04:00')->name('rotate-log')->withoutOverlapping();
```

### Linux logrotate (optional additional layer)

```conf
# /etc/logrotate.d/emc
/var/www/emc-backend/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    sharedscripts
    postrotate
        /bin/kill -USR1 $(cat /var/run/php8.3-fpm.pid 2>/dev/null) 2>/dev/null || true
    endscript
}
```

---

## Centralized Logging (Optional)

### Sentry (recommended for errors)

```bash
composer require sentry/sentry-laravel
```

```env
SENTRY_LARAVEL_DSN=https://YOUR_DSN@sentry.io/PROJECT_ID
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_LOG_LEVEL=error
LOG_STACK=daily,sentry
```

Verify:
```bash
php artisan sentry:test
```

### Slack alerts for critical errors

```env
LOG_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
LOG_STACK=daily,slack
```

The slack channel in `config/logging.php` fires on `LOG_LEVEL` severity (default: critical).

### Papertrail / Logtail / Datadog

Add the relevant channel to `config/logging.php` and include it in `LOG_STACK`.

---

## Log Viewing Commands

```bash
# View last 100 lines of today's log:
tail -n 100 storage/logs/laravel.log

# Follow the log in real time:
tail -f storage/logs/laravel.log

# Filter for errors only:
grep -i "ERROR\|CRITICAL\|ALERT\|EMERGENCY" storage/logs/laravel.log | tail -50

# View scheduler log:
tail -n 50 storage/logs/scheduler.log

# Count errors in the last 24h log:
grep "$(date +%Y-%m-%d)" storage/logs/laravel.log | grep -c "ERROR"

# Laravel Pail (real-time, dev only):
php artisan pail --filter="error"
```

---

## Pre-Deployment Log Checklist

- [ ] `LOG_LEVEL=error` in `.env`
- [ ] `APP_DEBUG=false` in `.env`
- [ ] `LOG_STACK=daily` (optionally `daily,sentry`)
- [ ] No `Log::debug` calls in production code paths
- [ ] No `Log::info` calls on high-frequency endpoints (per-request logging)
- [ ] `storage/logs/` is writable: `ls -la storage/logs/`
- [ ] Old log files present (rotation is working): `ls storage/logs/ | wc -l`
- [ ] Sentry configured and `php artisan sentry:test` passes (if using Sentry)
- [ ] Slack webhook works for critical alerts (if using Slack channel)
