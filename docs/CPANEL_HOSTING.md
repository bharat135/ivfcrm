# Hosting the CRM on cPanel with the database on 103.174.102.91

The application runs on the cPanel account; MySQL stays on the VPS. Both
servers therefore talk to one `ivfcrm` database, so nothing is migrated or
copied and every lead, token and setting is already there.

```
 browser ──HTTPS──▶ cPanel (PHP 8.3, app code, cron: scheduler + queue worker)
                          │
                          └──MySQL 3306, firewalled to the cPanel IP──▶ VPS 103.174.102.91 (MySQL)
```

Only ONE server may run the scheduler and queue worker, otherwise Meta polls,
reminders and reassignments run twice. Once cPanel is live, stop them on the VPS
(step 6).

## 1. On the VPS: allow the cPanel server to reach MySQL

Needs the cPanel **server** IP (cPanel → right sidebar "Shared IP Address" /
"Server IP", or `curl ifconfig.me` from cPanel Terminal).

```
ssh -i ~/.ssh/crm_pbx root@103.174.102.91
bash /var/www/ivfcrm/deploy/vps-allow-remote-db.sh <CPANEL_SERVER_IP>
```

It prints the `DB_*` lines for the cPanel `.env` (user `ivfcrm_cpanel`, a new
password) and opens port 3306 in ufw for that one IP only.

## 2. On cPanel: prepare the account

1. **MultiPHP Manager** → PHP 8.3 for the domain. **Select PHP Version /
   Extensions** → enable `bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring,
   openssl, pdo_mysql, tokenizer, xml, zip`.
2. **SSH Access** → enable, and import the public half of the deploy key that
   the GitHub workflow will use (`~/.ssh/authorized_keys`).
3. **Terminal** (or SSH) → `mkdir -p ~/ivfcrm` — the app lives OUTSIDE
   `public_html`.
4. **Domains** → set the domain's document root to `/home/<account>/ivfcrm/public`.
   (If the panel refuses, point it at `public_html` and put this in
   `public_html/.htaccess`: `RewriteEngine On`, `RewriteRule ^(.*)$ /ivfcrm/public/$1 [L]`
   after symlinking `ln -s ~/ivfcrm/public ~/public_html/ivfcrm/public`.)
5. **Cron Jobs** — add both, every minute (`* * * * *`):
   ```
   /usr/local/bin/ea-php83 /home/<account>/ivfcrm/artisan schedule:run >> /dev/null 2>&1
   /usr/local/bin/ea-php83 /home/<account>/ivfcrm/artisan queue:work database --stop-when-empty --max-time=55 --tries=1 >> /home/<account>/ivfcrm/storage/logs/queue.log 2>&1
   ```
   The second one is the queue worker (webhook lead imports, Meta syncs,
   WhatsApp sends). `--stop-when-empty` makes it exit so cron can restart it.

## 3. The `.env` on cPanel

Copy `deploy/env.cpanel.example` to `/home/<account>/ivfcrm/.env` and fill in:

- `APP_KEY` — **exactly the value from the VPS** (`grep APP_KEY /var/www/ivfcrm/.env`).
  All saved API tokens and passwords in the database are encrypted with it;
  a different key makes every integration setting unreadable.
- `APP_URL` — the cPanel domain, with https.
- the `DB_*` block printed by step 1.

Cache, sessions and queue use database tables (no Redis on cPanel).

## 4. GitHub → cPanel deploys

Repository **Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | cPanel server hostname or IP |
| `DEPLOY_USER` | cPanel account username |
| `DEPLOY_SSH_KEY` | private half of the key from step 2 |
| `DEPLOY_PATH` | `/home/<account>/ivfcrm` |
| `DEPLOY_PORT` | usually `22` (some hosts use 2222) |
| `DEPLOY_PHP` | `/usr/local/bin/ea-php83` |

Then push to `main` (or Actions → *Deploy to production* → *Run workflow*).
The workflow builds `vendor/` and the assets on GitHub's runner, rsyncs the
release over SSH and runs `deploy/post-deploy.sh` on cPanel (backup of the
remote DB, `migrate --force`, caches, `queue:restart`).

If the host has no SSH at all, use cPanel → **Git Version Control** with the
repository URL and `.cpanel.yml` (edit `CPANEL_ACCOUNT` in it first); in that
case run `composer install --no-dev` and `npm run build` once by hand.

## 5. Verify

- `https://<domain>/login` → sign in → Enquiries list shows the same leads as
  prevoir.panvelcity.in.
- Settings → Integrations → *Test connection* → all rows OK (proves APP_KEY
  matches: tokens decrypt).
- After a minute, `storage/logs/queue.log` on cPanel shows the worker running.

## 6. Cut over

1. Meta App Dashboard → Webhooks → callback URL → `https://<domain>/api/webhooks/meta`
   (verify token unchanged). Smartflo portal → API Connect → Webhook → `https://<domain>/api/webhooks/tata/...`.
2. On the VPS, stop the duplicate background jobs — the DB stays:
   ```
   systemctl disable --now ivfcrm-queue
   rm /etc/cron.d/ivfcrm
   ```
   Optionally keep the nginx site as a read-only mirror, or `systemctl stop nginx`
   only for that site (do not touch the `crm` site or Asterisk).
3. DNS: point `prevoir.panvelcity.in` (or the new domain) at the cPanel server if
   the URL should stay the same; issue the certificate there (AutoSSL).

## Notes

- Uploaded files (`storage/app`) are not shared between servers; copy
  `/var/www/ivfcrm/storage/app` to cPanel once with `scp -r` before cut-over.
- Sessions live in the database, so logins on both hosts are visible to the
  single-device rule; log out on the VPS site before testing the cPanel site.
- Backups: `deploy/post-deploy.sh` dumps the remote database into
  `storage/backups` on cPanel before every migration.
