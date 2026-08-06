# EMC Backend — Backup Strategy

## Overview

Three things need to be backed up:
1. **MySQL database** — all application data (users, registrations, payments, courses)
2. **`storage/app/` files** — course materials, uploaded documents, certificates
3. **`.env`** — production environment secrets (store encrypted, off-server)

All commands below assume you are on the production server at `/var/www/emc-backend`.

---

## 1. Database Backup

### Local development backup

```bash
# Dump the database to a SQL file in the current directory:
mysqldump -u root -p emc_db > backup_emc_db_$(date +%Y-%m-%d).sql

# Restore from that file:
mysql -u root -p emc_db < backup_emc_db_2026-05-31.sql
```

### Production backup via Laravel (recommended)

The `emc:backup` Artisan command handles naming, storage path, and pruning:

```bash
# Database only (→ storage/app/backups/):
php artisan emc:backup --db

# Storage/app directory only (excludes the backups/ dir itself):
php artisan emc:backup --storage

# Both at once + prune files older than 30 days:
php artisan emc:backup --all --prune-days=30

# Custom disk / directory:
php artisan emc:backup --db --disk=s3 --dir=emc/backups
```

**Enable nightly automated database backup:**

```env
# .env
ENABLE_SCHEDULED_BACKUP=true
```

The scheduler runs `emc:backup --db --prune-days=30` daily at 01:00 AM.

---

## 2. Raw mysqldump (production — no Artisan required)

Use this approach if Artisan is unavailable (e.g., during disaster recovery from a fresh server):

```bash
# Basic dump with best practice flags:
mysqldump \
  --single-transaction \
  --no-tablespaces \
  --routines \
  --triggers \
  -h 127.0.0.1 \
  -u DB_USERNAME \
  -pDB_PASSWORD \
  emc_db \
  > /var/backups/emc_db_$(date +%Y-%m-%d_%H-%M-%S).sql

# Compress to save space:
mysqldump --single-transaction --no-tablespaces \
  -u DB_USERNAME -pDB_PASSWORD emc_db \
  | gzip > /var/backups/emc_db_$(date +%Y-%m-%d).sql.gz

# Restore from compressed backup:
gunzip -c /var/backups/emc_db_2026-05-31.sql.gz | mysql -u DB_USERNAME -pDB_PASSWORD emc_db
```

---

## 3. Storage Files Backup

```bash
# Archive storage/app/ to /var/backups/:
tar -czf /var/backups/emc_storage_$(date +%Y-%m-%d).tar.gz \
  --exclude='storage/app/backups' \
  -C /var/www/emc-backend storage/app

# Restore:
tar -xzf /var/backups/emc_storage_2026-05-31.tar.gz \
  -C /var/www/emc-backend
```

---

## 4. Restore Test Procedure

Run a restore test **monthly** to verify backups are valid:

```bash
# Step 1: Find the latest backup
ls -lh /var/www/emc-backend/storage/app/backups/

# Step 2: Create a temporary restore database
mysql -u root -p -e "CREATE DATABASE emc_restore_test;"

# Step 3: Restore into it
mysql -u root -p emc_restore_test < storage/app/backups/2026-05-31_01-00-00_db_emc_db.sql

# Step 4: Spot-check row counts (should match prod)
mysql -u root -p emc_restore_test -e "
  SELECT 'users'         AS tbl, COUNT(*) AS cnt FROM users
  UNION ALL SELECT 'registrations', COUNT(*) FROM registrations
  UNION ALL SELECT 'payments',       COUNT(*) FROM payments
  UNION ALL SELECT 'courses',        COUNT(*) FROM courses;
"

# Step 5: Clean up
mysql -u root -p -e "DROP DATABASE emc_restore_test;"

# Step 6: Record the result in your ops log
echo "Restore test passed: $(date)" >> /var/log/emc-restore-tests.log
```

---

## 5. Backup Frequency Recommendation

| Data | Frequency | Retention | Method |
|------|-----------|-----------|--------|
| Database | Daily at 01:00 AM | 30 days local | `emc:backup --db` (scheduler) |
| Storage files | Weekly | 4 weeks | `emc:backup --storage` |
| `.env` secrets | On every change | Indefinitely | Encrypted vault / password manager |
| Full server snapshot | Monthly | 3 months | Hetzner Snapshot / DigitalOcean backup |
| Restore test | Monthly | — | Manual, log the result |

---

## 6. Off-Server Backup (Critical)

Backups stored only on the production server are **lost in a full server failure**. Copy them off-server:

### Option A — Rclone to S3 / Cloudflare R2

```bash
# Install rclone and configure an S3-compatible remote named "backup":
rclone copy /var/www/emc-backend/storage/app/backups backup:emc-backups/ \
  --min-age 1h

# Add to cron to run after the nightly backup (e.g., 02:00 AM):
0 2 * * * rclone copy /var/www/emc-backend/storage/app/backups backup:emc-backups/ --min-age 1h >> /var/log/rclone.log 2>&1
```

### Option B — SCP to a remote server

```bash
scp storage/app/backups/*.sql backup-server:/remote/backups/emc/
```

### Option C — Laravel disk to S3

Set `FILESYSTEM_DISK=s3` or use `--disk=s3` flag:

```bash
php artisan emc:backup --db --disk=s3 --dir=backups/daily
```

---

## 7. Environment Secrets Backup

The `.env` file contains database passwords, Stripe secrets, and API keys. **Never commit it to git.**

Safe storage options:
- **Bitwarden** (recommended) — store as a secure note, access-controlled
- **1Password** — same approach
- **HashiCorp Vault** — for team environments
- **Hetzner Cloud → server snapshots** — entire server including `.env`, but encrypted at rest

---

## Quick Reference

```bash
# Manual database backup right now:
php artisan emc:backup --db --prune-days=30

# Verify backup was written:
ls -lh storage/app/backups/

# Check scheduled backups are active:
php artisan schedule:list | grep backup

# Enable automated backups:
# echo "ENABLE_SCHEDULED_BACKUP=true" >> .env && php artisan config:cache
```
