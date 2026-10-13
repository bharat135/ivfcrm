#!/usr/bin/env bash
# Copies the LIVE DATA of IVF CRM from the current server to a new one:
# the MySQL database, storage/app (uploads, imports, recordings) and .env.
# Run from a machine that can SSH to both servers (Git Bash on Windows works).
# The data is streamed server-to-server through this machine; nothing is
# written locally.
#
#   OLD=root@103.174.102.91 OLD_KEY=~/.ssh/crm_pbx \
#   NEW=root@45.195.243.17  NEW_KEY=~/.ssh/crm_pbx \
#   NEW_DB_PASSWORD='the password given to ubuntu-setup.sh' \
#   NEW_APP_URL=https://prevoir.panvelcity.in \
#   bash deploy/migrate-data-to-new-server.sh
#
# FREEZE=1 puts the old site in maintenance mode first so no lead or message
# lands on the old server while the copy runs (use for the final cut-over).
set -euo pipefail

OLD=${OLD:?OLD=user@old-host}
NEW=${NEW:?NEW=user@new-host}
OLD_KEY=${OLD_KEY:-$HOME/.ssh/id_ed25519}
NEW_KEY=${NEW_KEY:-$HOME/.ssh/id_ed25519}
OLD_DIR=${OLD_DIR:-/var/www/ivfcrm}
NEW_DIR=${NEW_DIR:-/var/www/ivfcrm}
NEW_DB_NAME=${NEW_DB_NAME:-ivfcrm}
NEW_DB_USER=${NEW_DB_USER:-ivfcrm}
NEW_DB_PASSWORD=${NEW_DB_PASSWORD:?NEW_DB_PASSWORD}
NEW_APP_URL=${NEW_APP_URL:-}
FREEZE=${FREEZE:-0}

OPTS=(-o ServerAliveInterval=15 -o ServerAliveCountMax=6 -o ConnectTimeout=20)
old() { ssh -i "$OLD_KEY" "${OPTS[@]}" "$OLD" "$@"; }
new() { ssh -i "$NEW_KEY" "${OPTS[@]}" "$NEW" "$@"; }

echo "==> checking both ends"
old "test -f $OLD_DIR/.env" || { echo "no $OLD_DIR/.env on $OLD"; exit 1; }
new "test -d $NEW_DIR && command -v mysql >/dev/null" || { echo "$NEW is not bootstrapped (run deploy/ubuntu-setup.sh)"; exit 1; }

if [ "$FREEZE" = "1" ]; then
  echo "==> maintenance mode on the old server"
  old "cd $OLD_DIR && php artisan down --retry=30" || true
fi

echo "==> database: dump on $OLD -> import on $NEW"
# The dump script reads credentials from the old server's own .env; they never leave it.
DUMP_CMD='cd '"$OLD_DIR"' && eval "$(grep -E "^DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=" .env | sed "s/^/export /")" && MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" | gzip -1'
old "$DUMP_CMD" | new "gunzip | MYSQL_PWD='$NEW_DB_PASSWORD' mysql -u '$NEW_DB_USER' '$NEW_DB_NAME'"
TABLES=$(new "MYSQL_PWD='$NEW_DB_PASSWORD' mysql -N -u '$NEW_DB_USER' -e \"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$NEW_DB_NAME'\"")
echo "    $TABLES tables imported"

echo "==> files: .env + storage/app"
old "cd $OLD_DIR && tar -czf - .env storage/app" | new "cd $NEW_DIR && tar -xzf -"

echo "==> adjusting the new .env"
new "cd $NEW_DIR \
  && cp .env .env.from-old-server \
  && sed -i -E 's#^DB_HOST=.*#DB_HOST=127.0.0.1#; s#^DB_PORT=.*#DB_PORT=3306#; s#^DB_DATABASE=.*#DB_DATABASE=$NEW_DB_NAME#; s#^DB_USERNAME=.*#DB_USERNAME=$NEW_DB_USER#' .env \
  && sed -i -E \"s#^DB_PASSWORD=.*#DB_PASSWORD=\\\"$NEW_DB_PASSWORD\\\"#\" .env \
  && { [ -z '$NEW_APP_URL' ] || sed -i -E 's#^APP_URL=.*#APP_URL=$NEW_APP_URL#' .env; } \
  && chown -R www-data:www-data $NEW_DIR \
  && chmod 640 .env .env.from-old-server \
  && grep -E '^(APP_URL|APP_ENV|DB_HOST|DB_DATABASE|DB_USERNAME|QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER)=' .env"

if [ "$FREEZE" = "1" ]; then
  echo "==> old server stays in maintenance mode (php artisan up on $OLD to revert)"
fi
echo "==> data copied. APP_KEY is unchanged, so every encrypted token in the database still decrypts."
echo "    Now deploy the code: push to main, or run the 'Deploy to production' workflow."
