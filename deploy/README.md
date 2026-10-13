# Deploying

Pushing to `main` runs `.github/workflows/deploy.yml`: it lints, installs
production Composer packages, builds the Vite assets, rsyncs the release to the
server over SSH and runs `deploy/post-deploy.sh` there (DB backup, migrate,
caches, queue restart).

## One-time setup

1. On the server, create the SSH key pair the workflow will use and add the
   public half to `~/.ssh/authorized_keys` of the deploy user.
2. In GitHub → repository **Settings → Secrets and variables → Actions**, add:

   | Secret           | Value                                                                 |
   |------------------|-----------------------------------------------------------------------|
   | `DEPLOY_HOST`    | `103.174.102.91` (or the cPanel hostname)                             |
   | `DEPLOY_USER`    | `root` (cPanel: the account username)                                 |
   | `DEPLOY_SSH_KEY` | the private key, full PEM text                                        |
   | `DEPLOY_PATH`    | `/var/www/ivfcrm` (cPanel: `/home/<account>/ivfcrm`)                  |
   | `DEPLOY_PORT`    | optional, default `22`                                                |
   | `DEPLOY_PHP`     | optional php binary, e.g. `/usr/local/bin/ea-php83` on cPanel         |

3. The server keeps its own `.env`; it is never overwritten. `storage/` and
   `bootstrap/cache/` are excluded from the sync as well.

## Moving to a fresh Ubuntu server

Three scripts, run in this order. Nothing is installed by hand on the box.

1. **Bootstrap the server** (as root, on the new server — only `deploy/ubuntu-setup.sh`
   needs to be copied there, e.g. with `scp`):

   ```bash
   DOMAIN=prevoir.panvelcity.in DB_PASSWORD='choose-one' \
   DEPLOY_PUBKEY='ssh-ed25519 AAAA... github-actions-ivfcrm' \
   bash ubuntu-setup.sh
   ```

   Installs nginx, PHP 8.3-FPM, MySQL 8, Redis, certbot; creates the `ivfcrm`
   database and user; writes the nginx site, `ivfcrm-queue.service` and
   `/etc/cron.d/ivfcrm`; opens ports 22/80/443; authorises the deploy key.

2. **Copy the live data** (from any machine with SSH to both servers, e.g. Git Bash):

   ```bash
   OLD=root@103.174.102.91 OLD_KEY=~/.ssh/crm_pbx \
   NEW=root@45.195.243.17  NEW_KEY=~/.ssh/crm_pbx \
   NEW_DB_PASSWORD='the same password as above' \
   NEW_APP_URL=https://prevoir.panvelcity.in \
   FREEZE=1 bash deploy/migrate-data-to-new-server.sh
   ```

   Streams the MySQL dump, `storage/app` (uploads, imports, recordings) and `.env`
   old → new and rewrites the DB lines in the new `.env`. `APP_KEY` is kept, so the
   encrypted integration tokens in the database keep working. `FREEZE=1` puts the
   old site into maintenance mode first; re-run without it for a dry copy.

3. **Deploy the code**: point the secrets at the new host (`DEPLOY_HOST`,
   `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`) and push to `main`, or run
   *Deploy to production* from the Actions tab. The workflow builds `vendor/` and
   the Vite assets and rsyncs them across; `post-deploy.sh` migrates, caches and
   starts the queue worker.

   Optional: `bash deploy/phpmyadmin-setup.sh` on the server installs phpMyAdmin at
   `/phpmyadmin` (standalone copy under `/var/www/phpmyadmin`, served by the same
   nginx site and PHP-FPM). Log in with the `DB_USERNAME` / `DB_PASSWORD` from `.env`.

4. **Go live**: move the domain's A record to the new IP, then on the server

   ```bash
   DOMAIN=prevoir.panvelcity.in EMAIL=you@example.com bash deploy/ubuntu-setup.sh tls
   ```

   gets the Let's Encrypt certificate, turns on the HTTPS redirect and sets
   `APP_URL`. Until DNS moves, the site answers on `http://<server-ip>/`.

## cPanel notes

Full walkthrough for running the app on cPanel against the VPS database:
[docs/CPANEL_HOSTING.md](../docs/CPANEL_HOSTING.md). Env template: `deploy/env.cpanel.example`;
VPS side: `deploy/vps-allow-remote-db.sh <cpanel-ip>`.

- Point the domain's document root at `<DEPLOY_PATH>/public`.
- Enable SSH access for the account (cPanel → SSH Access) and add the key there.
- Create a cron job `* * * * * <php> <DEPLOY_PATH>/artisan schedule:run` and a
  second one keeping a queue worker alive, e.g.
  `* * * * * <php> <DEPLOY_PATH>/artisan queue:work --stop-when-empty --max-time=55`,
  because cPanel has no systemd service for the worker.

## After the first deploy of the Meta changes

Open **Settings → Integrations → Ads**, click **Discover**, save, then
**Subscribe Page to Lead Ads**. **Test connection** must show `lead_ads` OK —
that is what turns on real-time lead capture; assignment then follows
**Settings → Lead Assignment**.
