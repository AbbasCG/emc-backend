#!/usr/bin/env bash
# EMC Backend — Production Deployment Script
#
# Usage:
#   sudo bash scripts/deploy.sh [--branch=main] [--skip-backup]
#
# Requirements:
#   - Run as root or a user with sudo supervisorctl access
#   - APP_DIR must be the absolute path to the Laravel root
#   - HEALTH_URL must resolve from the server (loopback is fine)
# ─────────────────────────────────────────────────────────────────────────────

set -euo pipefail

# ── Config ────────────────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/emc-backend}"
BRANCH="${BRANCH:-main}"
HEALTH_URL="${HEALTH_URL:-https://api.emc.sa/api/health}"
PHP="${PHP:-php}"
SKIP_BACKUP=false

for arg in "$@"; do
    case "$arg" in
        --branch=*) BRANCH="${arg#*=}" ;;
        --skip-backup) SKIP_BACKUP=true ;;
    esac
done

# ── Helpers ───────────────────────────────────────────────────────────────────
step() { echo ""; echo "=== [$1] $2 ==="; }
pass() { echo "  ✔  $1"; }
warn() { echo "  ⚠  $1"; }
fail() { echo "  ✘  $1"; exit 1; }

cd "$APP_DIR" || fail "Cannot cd to APP_DIR=$APP_DIR"

# ── 1. Pre-deploy backup ──────────────────────────────────────────────────────
step "1/9" "Pre-deploy database backup"
if [ "$SKIP_BACKUP" = true ]; then
    warn "Backup skipped (--skip-backup)"
else
    "$PHP" artisan emc:backup --db --prune-days=30 \
        && pass "Backup complete" \
        || warn "Backup failed — continuing deploy (check storage/app/backups/)"
fi

# ── 2. Pull latest code ───────────────────────────────────────────────────────
step "2/9" "Pulling $BRANCH"
git fetch --quiet origin
git checkout "$BRANCH"
git pull origin "$BRANCH"
pass "Code updated to $(git rev-parse --short HEAD)"

# ── 3. Install dependencies ───────────────────────────────────────────────────
step "3/9" "Installing dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --quiet
pass "Composer install complete"

# ── 4. Production readiness gate ─────────────────────────────────────────────
step "4/9" "Production readiness check"
"$PHP" artisan emc:production-check || fail "Production check failed — deploy aborted. Fix issues then retry."
pass "All critical checks passed"

# ── 5. Maintenance mode ───────────────────────────────────────────────────────
step "5/9" "Entering maintenance mode"
"$PHP" artisan down --refresh=15
pass "Maintenance mode ON"

# Ensure we bring the app back up even if something below fails
trap '"$PHP" artisan up && echo "  ✔  Maintenance mode OFF (trap)"' EXIT

# ── 6. Database migrations ────────────────────────────────────────────────────
step "6/9" "Running migrations"
"$PHP" artisan migrate --force
pass "Migrations complete"

# ── 7. Cache rebuild ──────────────────────────────────────────────────────────
step "7/9" "Rebuilding caches"
"$PHP" artisan optimize:clear
"$PHP" artisan optimize
pass "Config / route / view caches rebuilt"

# ── 8. Restart queue workers ──────────────────────────────────────────────────
step "8/9" "Restarting queue workers"
"$PHP" artisan queue:restart
if command -v supervisorctl &>/dev/null; then
    supervisorctl restart emc:* || warn "supervisorctl restart failed — restart workers manually"
    pass "Supervisor workers restarted"
else
    warn "supervisorctl not found — restart queue workers manually"
fi

# ── 9. Bring app back up + verify ─────────────────────────────────────────────
# Disable the EXIT trap before doing it manually so we don't double-up
trap - EXIT

step "9/9" "Bringing app online"
"$PHP" artisan up
pass "Maintenance mode OFF"

echo ""
echo "=== Verifying health ==="
sleep 2
if curl -sf "$HEALTH_URL" | grep -q '"status":"ok"'; then
    pass "Health check: OK ($HEALTH_URL)"
elif curl -sf "$HEALTH_URL" | grep -q '"status":"warn"'; then
    warn "Health check: WARN — review degraded checks at $HEALTH_URL"
else
    warn "Health check: FAIL or unreachable — inspect $HEALTH_URL manually"
fi

echo ""
echo "──────────────────────────────────────────────────────────"
echo "  Deployment complete  |  $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "  Commit: $(git rev-parse HEAD)"
echo "──────────────────────────────────────────────────────────"
