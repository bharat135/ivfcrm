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
