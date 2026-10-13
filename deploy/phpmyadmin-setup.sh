#!/usr/bin/env bash
# Installs phpMyAdmin next to the app on a server bootstrapped by ubuntu-setup.sh
# and serves it from the same nginx site at /phpmyadmin, through PHP-FPM.
# The Ubuntu "phpmyadmin" package is not used: it drags in apache2 and the
# distro's PHP version. Run as root ON THE SERVER:
#
#   bash deploy/phpmyadmin-setup.sh            # installs/updates to the latest 5.x
#   PMA_VERSION=5.2.2 bash deploy/phpmyadmin-setup.sh
#
# Log in with the MySQL user from /var/www/ivfcrm/.env (DB_USERNAME / DB_PASSWORD).
set -euo pipefail

PHP_VERSION=${PHP_VERSION:-8.3}
PMA_DIR=/var/www/phpmyadmin
SITE=/etc/nginx/sites-available/ivfcrm
PMA_VERSION=${PMA_VERSION:-}

[ "$(id -u)" = "0" ] || { echo "run as root"; exit 1; }

echo "==> download"
if [ -z "$PMA_VERSION" ]; then
  URL=https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz
else
  URL="https://files.phpmyadmin.net/phpMyAdmin/$PMA_VERSION/phpMyAdmin-$PMA_VERSION-all-languages.tar.gz"
fi
TMP=$(mktemp -d)
curl -sSL -o "$TMP/pma.tgz" "$URL"
mkdir -p "$TMP/x" && tar -xzf "$TMP/pma.tgz" -C "$TMP/x" --strip-components=1

echo "==> install into $PMA_DIR"
SECRET=""
[ -f "$PMA_DIR/config.inc.php" ] && SECRET=$(grep -oP "blowfish_secret'\] = '\K[^']+" "$PMA_DIR/config.inc.php" || true)
rm -rf "$PMA_DIR.new" && mv "$TMP/x" "$PMA_DIR.new"
rm -rf "$PMA_DIR.old"; [ -d "$PMA_DIR" ] && mv "$PMA_DIR" "$PMA_DIR.old"
mv "$PMA_DIR.new" "$PMA_DIR"; rm -rf "$PMA_DIR.old" "$TMP"
[ -n "$SECRET" ] || SECRET=$(openssl rand -base64 32 | tr -d '=+/' | cut -c1-32)
mkdir -p "$PMA_DIR/tmp"
cat > "$PMA_DIR/config.inc.php" <<CFG
<?php
\$cfg['blowfish_secret'] = '$SECRET';
\$cfg['TempDir'] = '$PMA_DIR/tmp';
\$i = 1;
\$cfg['Servers'][\$i]['auth_type'] = 'cookie';
\$cfg['Servers'][\$i]['host'] = '127.0.0.1';
\$cfg['Servers'][\$i]['compress'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['LoginCookieValidity'] = 3600;
\$cfg['UploadDir'] = '';
\$cfg['SaveDir'] = '';
CFG
chown -R www-data:www-data "$PMA_DIR"
find "$PMA_DIR" -type d -exec chmod 750 {} +
find "$PMA_DIR" -type f -exec chmod 640 {} +

echo "==> nginx"
if ! grep -q 'location \^~ /phpmyadmin' "$SITE"; then
  BLOCK=$(cat <<NGINX
    location ^~ /phpmyadmin {
        root /var/www;
        index index.php;
        # Regex locations are tried in order: deny internals before the PHP handler.
        location ~ ^/phpmyadmin/(libraries|templates|tmp|setup|vendor)/ { deny all; }
        location ~ ^/phpmyadmin/(.+\\.php)\$ {
            root /var/www;
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            include fastcgi_params;
            fastcgi_read_timeout 300;
        }
    }
NGINX
)
  # Insert before the app's PHP handler so the prefix match wins. The block is
  # passed through the environment: awk -v would interpret its backslashes.
  BLOCK="$BLOCK" awk 'index($0, "location ~ \\.php$ {") && !done { print ENVIRON["BLOCK"]; done=1 } { print }' "$SITE" > "$SITE.tmp" \
    && mv "$SITE.tmp" "$SITE"
fi
nginx -t && systemctl reload nginx
echo "==> phpMyAdmin $(grep -oP "VERSION = '\K[^']+" "$PMA_DIR/libraries/classes/Version.php" 2>/dev/null || grep -oP '"version": "\K[^"]+' "$PMA_DIR/package.json") at /phpmyadmin"
