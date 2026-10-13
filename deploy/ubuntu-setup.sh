#!/usr/bin/env bash
# One-shot bootstrap of a FRESH Ubuntu 22.04/24.04/26.04 server for IVF CRM.
# Run as root ON THE NEW SERVER. Idempotent: safe to re-run.
#
#   DOMAIN=crm.example.com DB_PASSWORD='secret' DEPLOY_PUBKEY='ssh-ed25519 AAAA...' \
#     bash deploy/ubuntu-setup.sh
#
# What it does: installs nginx + PHP 8.3-FPM + MySQL 8 + Redis + certbot,
# creates the ivfcrm database/user, writes the nginx site, the queue worker
# systemd unit and the scheduler cron, opens the firewall and authorises the
# GitHub Actions deploy key. The application code itself arrives afterwards
# via the GitHub Actions workflow (.github/workflows/deploy.yml) and the data
# via deploy/migrate-data-to-new-server.sh — this script installs no app files.
#
# Second mode, after DNS points at this server:
#   DOMAIN=crm.example.com [EMAIL=you@example.com] bash deploy/ubuntu-setup.sh tls
set -euo pipefail

APP_DIR=/var/www/ivfcrm
PHP_VERSION=${PHP_VERSION:-8.3}
DB_NAME=${DB_NAME:-ivfcrm}
DB_USER=${DB_USER:-ivfcrm}
DOMAIN=${DOMAIN:-_}
MODE=${1:-install}

if [ "$(id -u)" != "0" ]; then echo "run as root"; exit 1; fi

tls_mode() {
  [ "$DOMAIN" != "_" ] || { echo "DOMAIN is required for tls"; exit 1; }
  apt-get install -y -q python3-certbot-nginx >/dev/null
  if [ -n "${EMAIL:-}" ]; then CONTACT=(-m "$EMAIL"); else CONTACT=(--register-unsafely-without-email); fi
  certbot --nginx --non-interactive --agree-tos --redirect "${CONTACT[@]}" -d "$DOMAIN"
  nginx -t && systemctl reload nginx
  if [ -f "$APP_DIR/.env" ]; then
    sed -i "s#^APP_URL=.*#APP_URL=https://$DOMAIN#" "$APP_DIR/.env"
    (cd "$APP_DIR" && sudo -u www-data php artisan config:cache) || true
  fi
  echo "==> TLS ready: https://$DOMAIN"
  exit 0
}
[ "$MODE" = "tls" ] && tls_mode

: "${DB_PASSWORD:?DB_PASSWORD is required}"

export DEBIAN_FRONTEND=noninteractive
echo "==> packages"
apt-get update -q
apt-get install -y -q software-properties-common ca-certificates curl gnupg lsb-release >/dev/null
# Only 24.04 ships 8.3; 22.04 (8.1) and 26.04 (8.5) get it from packages.sury.org
# (the ondrej PPA has no builds for 26.04). PHP_VERSION=8.5 on 26.04 uses the distro build.
if ! apt-cache show php${PHP_VERSION}-fpm >/dev/null 2>&1; then
  curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
  dpkg -i /tmp/debsuryorg-archive-keyring.deb >/dev/null
  echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
  apt-get update -q
fi
apt-get install -y -q \
  nginx mysql-server redis-server certbot rsync unzip git ufw \
  php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-curl \
  php${PHP_VERSION}-zip php${PHP_VERSION}-gd php${PHP_VERSION}-intl php${PHP_VERSION}-bcmath php${PHP_VERSION}-redis php${PHP_VERSION}-gmp \
  php${PHP_VERSION}-opcache php${PHP_VERSION}-readline >/dev/null

echo "==> php.ini"
for ini in /etc/php/${PHP_VERSION}/fpm/php.ini /etc/php/${PHP_VERSION}/cli/php.ini; do
  sed -i -E 's/^;?upload_max_filesize = .*/upload_max_filesize = 32M/; s/^;?post_max_size = .*/post_max_size = 32M/; s/^;?memory_limit = .*/memory_limit = 512M/; s/^;?max_execution_time = .*/max_execution_time = 300/; s/^;?date.timezone =.*/date.timezone = Asia\/Kolkata/' "$ini"
done
systemctl enable --now php${PHP_VERSION}-fpm redis-server mysql nginx >/dev/null
systemctl restart php${PHP_VERSION}-fpm

echo "==> mysql database $DB_NAME / user $DB_USER"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> app directory $APP_DIR"
mkdir -p "$APP_DIR"/public "$APP_DIR"/storage/app/public \
  "$APP_DIR"/storage/framework/cache "$APP_DIR"/storage/framework/sessions \
  "$APP_DIR"/storage/framework/views "$APP_DIR"/storage/logs "$APP_DIR"/bootstrap/cache
chown -R www-data:www-data "$APP_DIR"

echo "==> nginx site"
SERVER_IP=$(hostname -I | awk '{print $1}')
cat > /etc/nginx/sites-available/ivfcrm <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN $SERVER_IP;
    root $APP_DIR/public;
    index index.php;
    charset utf-8;
    client_max_body_size 32m;

    access_log /var/log/nginx/ivfcrm.access.log;
    error_log  /var/log/nginx/ivfcrm.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/ivfcrm /etc/nginx/sites-enabled/ivfcrm
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> queue worker service"
cat > /etc/systemd/system/ivfcrm-queue.service <<UNIT
[Unit]
Description=IVF CRM queue worker
After=network.target redis-server.service mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=$APP_DIR
ExecStart=/usr/bin/php $APP_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --timeout=600
Restart=always
RestartSec=5
StandardOutput=append:$APP_DIR/storage/logs/queue.log
StandardError=inherit

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable ivfcrm-queue >/dev/null
# Not started yet: there is no code until the first deploy. post-deploy.sh restarts it.

echo "==> scheduler cron"
cat > /etc/cron.d/ivfcrm <<CRON
* * * * * www-data cd $APP_DIR && /usr/bin/php artisan schedule:run >> $APP_DIR/storage/logs/schedule.log 2>&1
CRON
chmod 644 /etc/cron.d/ivfcrm

echo "==> deploy key"
mkdir -p /root/.ssh && chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys && chmod 600 /root/.ssh/authorized_keys
if [ -n "${DEPLOY_PUBKEY:-}" ] && ! grep -qF "$DEPLOY_PUBKEY" /root/.ssh/authorized_keys; then
  echo "$DEPLOY_PUBKEY" >> /root/.ssh/authorized_keys
fi

echo "==> firewall"
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

echo "==> done. Next:"
echo "  1. deploy/migrate-data-to-new-server.sh   (database, uploads, .env from the old server)"
echo "  2. push to main / run the 'Deploy to production' workflow"
echo "  3. point DNS here, then: DOMAIN=$DOMAIN EMAIL=you@example.com bash deploy/ubuntu-setup.sh tls"
