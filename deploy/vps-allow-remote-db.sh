#!/usr/bin/env bash
# Run ON THE VPS (103.174.102.91) as root, once, with the cPanel server's IP:
#   bash /var/www/ivfcrm/deploy/vps-allow-remote-db.sh <CPANEL_SERVER_IP>
#
# Lets the cPanel-hosted CRM use this server's MySQL "ivfcrm" database:
#   - MySQL listens on all interfaces (still firewalled),
#   - a dedicated user ivfcrm_cpanel@<ip> with a fresh password,
#   - ufw opens 3306 ONLY from that IP.
# Idempotent: re-run with the same IP to rotate the password.
set -euo pipefail

IP="${1:-}"
[ -n "$IP" ] || { echo "usage: $0 <CPANEL_SERVER_IP>"; exit 1; }
[[ "$IP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "not an IPv4 address: $IP"; exit 1; }

DB=ivfcrm
USER=ivfcrm_cpanel
PASS="$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)"
CNF=/etc/mysql/mysql.conf.d/mysqld.cnf

echo "==> MySQL: listen on all interfaces (mysqlx stays local)"
if grep -qE '^\s*bind-address' "$CNF"; then
  sed -i -E 's/^\s*bind-address\s*=.*/bind-address\t\t= 0.0.0.0/' "$CNF"
else
  printf '\n[mysqld]\nbind-address = 0.0.0.0\n' >> "$CNF"
fi
systemctl restart mysql
sleep 2
ss -ltn | grep -q ':3306' && echo "    mysqld listening: $(ss -ltn | grep ':3306' | awk '{print $4}')"

echo "==> MySQL user ${USER}@${IP}"
mysql -e "CREATE USER IF NOT EXISTS '${USER}'@'${IP}' IDENTIFIED BY '${PASS}';
ALTER USER '${USER}'@'${IP}' IDENTIFIED BY '${PASS}';
GRANT ALL PRIVILEGES ON \`${DB}\`.* TO '${USER}'@'${IP}';
FLUSH PRIVILEGES;"

echo "==> Firewall: 3306 from ${IP} only"
ufw allow from "$IP" to any port 3306 proto tcp comment 'mysql for cpanel crm' >/dev/null
ufw status | grep 3306 || true

cat <<EOT

==> Done. Put these in the cPanel .env:
DB_CONNECTION=mysql
DB_HOST=$(curl -s -4 ifconfig.me || echo 103.174.102.91)
DB_PORT=3306
DB_DATABASE=${DB}
DB_USERNAME=${USER}
DB_PASSWORD=${PASS}

Test from the cPanel server:
  mysql -h $(curl -s -4 ifconfig.me || echo 103.174.102.91) -u ${USER} -p'${PASS}' ${DB} -e 'select count(*) from leads;'
EOT
