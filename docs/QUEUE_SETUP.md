# EMC Backend — Queue Setup Guide

## Overview

The EMC backend processes these tasks asynchronously via queued jobs:

| Job | Queue | Trigger |
|-----|-------|---------|
| `SendEmailJob` | default | Registration, password reset, certificate |
| `SendNotificationJob` | default | Every user action |
| `SendWhatsAppJob` | default | Registration confirmation |
| `IssueCertificateJob` | default | Admin issues certificate |
| `GenerateReportJob` | default | Admin generates report (up to 5 min) |
| `ProcessPaymentWebhookJob` | webhooks | Stripe / PayPal webhook received |
| `DispatchWebhookJob` | webhooks | Outbound webhook delivery |
| `GenerateAiResponseJob` | default (or ai) | AI conversation message |
| `GenerateEmbeddingJob` | default | Knowledge article published |
| `GenerateAiInsightsJob` | default | Scheduled AI insight generation |
| `GenerateMeetingSummaryJob` | default | Meeting saved with AI summary |
| `GenerateStudentRecommendationsJob` | default | Scheduled recommendation refresh |

All jobs implement `ShouldQueue` and are safe to retry (`$tries` defined per job).
`ProcessPaymentWebhookJob` uses `WithoutOverlapping` to prevent concurrent payment races.

---

## Environment Configuration

### Development (no worker needed)

```env
QUEUE_CONNECTION=sync
```

Jobs run inline — no worker process required. Good for local development.

### Development with real async queue (recommended)

```env
QUEUE_CONNECTION=database
```

Requires running a worker separately:

```bash
php artisan queue:work --tries=3 --timeout=90
```

### Production (Redis)

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_QUEUE=default
```

---

## Local Development

### Option 1 — Sync (simplest, no worker)

```env
# .env
QUEUE_CONNECTION=sync
```

Jobs execute immediately in the same HTTP request. No background processing — useful for rapid development but hides async issues.

### Option 2 — Database queue (realistic async testing)

```env
# .env
QUEUE_CONNECTION=database
```

Start the worker in a separate terminal:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

Useful flags:
```bash
# Process one job at a time (good for debugging):
php artisan queue:work --once

# Specify a queue:
php artisan queue:work --queue=webhooks,default

# Verbose output:
php artisan queue:work -v
```

Check pending and failed jobs:
```bash
php artisan queue:monitor default:50,webhooks:10
php artisan queue:failed
```

### Option 3 — Redis queue (production-identical)

Install Redis locally, then:

```bash
# Docker (recommended for local):
docker run --name redis-emc -p 6379:6379 -d redis:alpine

# Windows alternative (if Docker unavailable):
# Download from: https://github.com/microsoftarchive/redis/releases
# Or use WSL2: wsl -d Ubuntu -- sudo apt install redis && redis-server

# Verify Redis is running:
redis-cli ping
# Expected: PONG
```

Update `.env`:
```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Test via Tinker:
```bash
php artisan tinker
>>> Cache::put('emc_test', 'working', 60);
>>> Cache::get('emc_test');
// Expected: "working"
```

Start the worker:
```bash
php artisan queue:work redis --sleep=3 --tries=3 --timeout=90
```

---

## Verifying Queue Works (End-to-End Test)

```bash
# 1. Make sure the worker is running in another terminal
php artisan queue:work --sleep=1

# 2. In another terminal, dispatch a test job via Tinker:
php artisan tinker
>>> \App\Jobs\SendNotificationJob::dispatch(
...   \App\Models\User::first(),
...   'info',
...   ['title' => 'Queue test', 'message' => 'Queue is working!']
... );

# 3. Watch the worker terminal — you should see the job processed

# 4. Check the database (if using database driver):
>>> \Illuminate\Support\Facades\DB::table('platform_notifications')->latest()->first();
```

---

## Production — Supervisor Configuration

Copy `docs/supervisor.conf` to `/etc/supervisor/conf.d/emc.conf` and update paths.

The production config manages these processes:

```ini
[program:emc-worker]
process_name    = %(program_name)s_%(process_num)02d
command         = php /var/www/emc-backend/artisan queue:work redis \
                      --queue=default \
                      --sleep=3 \
                      --tries=3 \
                      --timeout=90 \
                      --max-time=3600 \
                      --memory=256
autostart       = true
autorestart     = true
stopasgroup     = true
killasgroup     = true
user            = www-data
numprocs        = 2
startsecs       = 5
stopwaitsecs    = 3600
redirect_stderr = true
stdout_logfile  = /var/log/supervisor/emc-worker.log
stdout_logfile_maxbytes = 10MB
stdout_logfile_backups  = 5

[program:emc-webhook-worker]
process_name    = %(program_name)s
command         = php /var/www/emc-backend/artisan queue:work redis \
                      --queue=webhooks,default \
                      --sleep=1 \
                      --tries=5 \
                      --timeout=60 \
                      --max-time=3600
autostart       = true
autorestart     = true
user            = www-data
numprocs        = 1
redirect_stderr = true
stdout_logfile  = /var/log/supervisor/emc-webhook-worker.log

[group:emc]
programs = emc-worker,emc-webhook-worker,emc-scheduler
```

**Deploy + restart workers:**

```bash
php artisan queue:restart          # signal workers to finish current job
sudo supervisorctl restart emc:*   # supervisor starts fresh workers
sudo supervisorctl status          # verify all running
```

---

## Failed Jobs

```bash
# List failed jobs:
php artisan queue:failed

# Retry a specific failed job:
php artisan queue:retry {id}

# Retry all failed jobs:
php artisan queue:retry all

# Delete a specific failed job:
php artisan queue:forget {id}

# Delete all failed jobs:
php artisan queue:flush

# Prune failed jobs older than 7 days (also runs in scheduler):
php artisan queue:prune-failed --hours=168
```

The scheduler automatically prunes failed jobs older than 7 days every Sunday at 03:00.

---

## Payment Webhooks — Special Notes

Payment webhooks (`ProcessPaymentWebhookJob`) are dispatched to the `webhooks` queue and use `WithoutOverlapping` to prevent concurrent processing of the same payment ID.

**Critical:** The `emc-webhook-worker` Supervisor process **must be running at all times**. If it's down when Stripe sends a webhook, Stripe will retry — but only for a limited window. Missing webhooks = unconfirmed payments.

Do not throttle the webhook endpoints (`/api/payments/stripe/webhook`, `/api/payments/paypal/webhook`). These are verified by signature, not rate-limited.

---

## Monitoring Queue Health

```bash
# Real-time queue status:
php artisan queue:monitor default:50,webhooks:10

# Failed jobs count:
php artisan queue:failed | wc -l

# Check via health endpoint:
curl -s https://api.emc.sa/api/health | python3 -m json.tool
# Look for: "queue": {"status": "ok", "failed_jobs": 0}
```

Alert if `failed_jobs > 0` — set up monitoring with UptimeRobot or a similar tool.

---

## Queue Driver Comparison

| Driver | Good for | Not good for |
|--------|----------|-------------|
| `sync` | Local dev only | Production (blocks HTTP requests) |
| `database` | Small apps, simple setup | High throughput (DB lock contention) |
| `redis` | **Production** | Requires Redis running |
| `sqs` | AWS environments | Requires AWS credentials + setup |

**Recommendation:** `database` for early-stage launch, switch to `redis` before 100 concurrent users.
