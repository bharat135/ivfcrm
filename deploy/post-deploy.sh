#!/usr/bin/env bash
# Runs ON THE SERVER after GitHub Actions has rsynced a release.
#   bash deploy/post-deploy.sh /var/www/ivfcrm
# Idempotent and short: SSH sessions to this host drop on long commands.
set -euo pipefail

APP_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
PHP="${DEPLOY_PHP:-php}"
cd "$APP_DIR"

echo "==> $(date '+%F %T') deploying into $APP_DIR with $PHP"

# .env is never shipped; refuse to continue without one rather than boot a
# half-configured app.
[ -f .env ] || { echo "!! No .env in $APP_DIR — create it first"; exit 1; }

mkdir -p storage/app/public storage/framework/{cache,sessions,views} storage/logs bootstrap/cache

# Never trust cached container/package manifests from another machine.
rm -f bootstrap/cache/{packages,services,config,routes-v7,events}.php

# Database backup before schema changes, when mysqldump and DB creds exist.
if command -v mysqldump >/dev/null 2>&1; then
  DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"' || true)
  DB_USERNAME=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"' || true)
  DB_PASSWORD=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"' || true)
  DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d= -f2- | tr -d '"' || true)
  DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d= -f2- | tr -d '"' || true)
  if [ -n "$DB_DATABASE" ]; then
    mkdir -p storage/backups
    BACKUP="storage/backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"
    MYSQL_PWD="$DB_PASSWORD" mysqldump --no-tablespaces --single-transaction --quick -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" 2>/dev/null | gzip > "$BACKUP" \
      && echo "==> DB backup: $BACKUP" || echo "!! DB backup skipped (mysqldump failed)"
    ls -1t storage/backups/pre-deploy-*.sql.gz 2>/dev/null | tail -n +8 | xargs -r rm -f
  fi
fi

"$PHP" artisan down --retry=15 || true

"$PHP" artisan migrate --force
"$PHP" artisan storage:link >/dev/null 2>&1 || true
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan event:cache

# Queue workers keep the old code in memory until told to stop.
"$PHP" artisan queue:restart || true
if command -v systemctl >/dev/null 2>&1 && systemctl list-units --type=service --all 2>/dev/null | grep -q 'ivfcrm-queue'; then
  systemctl restart ivfcrm-queue || sudo -n systemctl restart ivfcrm-queue || true
fi

# Ownership: the web server user on a VPS; on cPanel the account already owns it.
if [ "$(id -u)" = "0" ] && id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$APP_DIR"
fi

"$PHP" artisan up
echo "==> $(date '+%F %T') deploy complete"
