# AGENTS.md

## Project

Whiteout Survival State Alliance Tracker — small PHP app (no framework, no database). JSON file storage.

## Local development

```bash
php -S localhost:8080 -t .
```

Open `http://localhost:8080/index.php`. For local sample data, set `"sample_mode": true` in `config.json`.

Manual update (CLI):

```bash
php -r '$_GET["token"]=json_decode(file_get_contents("config.json"), true)["update_token"]; include "update.php";'
```

## Cursor Cloud specific instructions

- PHP 8.3+ with `curl` extension is required (`php-cli`, `php-curl` on Ubuntu).
- `config.json` is gitignored; copy from `config.example.json`.
- There is **no public Whiteout Survival alliance HTTP API**. Production needs a custom `api_url` bridge, or temporary `sample_mode` for UI testing only.
- The built-in PHP server does not enforce `.htaccess`; Apache on Hostinger does.

## Deployment (Hostinger — state4627 only)

Deploy **only** to the `state4627.btkdeals.com` document root. Do not modify other domains or projects on the Hostinger account.

Required env vars for `deploy.sh`:

- `HOSTINGER_SSH_HOST` — FTP/SSH IP from hPanel (often port **65002**)
- `HOSTINGER_SSH_USER` — SSH username (e.g. `u123456789`)
- `HOSTINGER_SSH_PORT` — usually `65002`
- `HOSTINGER_SSH_KEY` — path to Hostinger private key file in the VM
- `HOSTINGER_DEPLOY_PATH` — must contain `state4627`, e.g. `/home/u…/domains/state4627.btkdeals.com/public_html`

```bash
chmod +x deploy.sh
./deploy.sh
```

After deploy, run the first update via cron or:

```text
https://state4627.btkdeals.com/update.php?token=YOUR_TOKEN
```

The deploy script creates `config.json` on the server (state **4627**) if missing and preserves existing `data/*.json`.
