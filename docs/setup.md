# EMC Backend — Local Development Setup

## Requirements

| Tool | Minimum Version |
|------|----------------|
| PHP | 8.2 |
| Composer | 2.x |
| MySQL | 8.0 |
| Node.js | 18+ (for assets, optional) |

---

## 1. Clone & Install

```bash
git clone https://github.com/your-org/emc-backend.git
cd emc-backend
composer install
```

---

## 2. Environment File

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your database credentials and mail config:

```env
APP_NAME="EMC Backend"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=emc_backend
DB_USERNAME=root
DB_PASSWORD=

MAIL_MAILER=log

QUEUE_CONNECTION=database
```

---

## 3. Database

```bash
# Create the database first in MySQL:
# CREATE DATABASE emc_backend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

php artisan migrate
php artisan db:seed   # optional — loads test data
```

---

## 4. Storage Link

```bash
php artisan storage:link
```

Creates `public/storage` → `storage/app/public` symlink for public files.

---

## 5. Queue Setup (database driver)

```bash
php artisan queue:table
php artisan migrate

# Start the worker (keep running in dev)
php artisan queue:work --sleep=3 --tries=3
```

---

## 6. Run the Server

```bash
php artisan serve
# API available at: http://localhost:8000/api
```

---

## 7. Run Tests

```bash
php artisan test
# or
./vendor/bin/pest
```

---

## Fake Payments

In `.env`, set:
```env
ALLOW_FAKE_PAYMENTS=true
```

Then use `payment_provider: "fake"` in registration requests.  
Confirm: `POST /api/payments/fake/confirm/{payment_id}`  
Fail:    `POST /api/payments/fake/fail/{payment_id}`

---

## CORS

Update `CORS_ALLOWED_ORIGINS` in `.env`:

```env
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000
```

---

## Sanctum Token Auth

This API uses **Bearer token authentication** (not cookies).

1. Login → receive token
2. Include in header: `Authorization: Bearer <token>`

If switching to cookie-based auth (for SPA same-domain):
- Set `supports_credentials = true` in `config/cors.php`
- Set `SANCTUM_STATEFUL_DOMAINS` in `.env`
- Use `axios.defaults.withCredentials = true` in React
