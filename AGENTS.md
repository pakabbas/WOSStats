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

Hostinger credentials come from the user's local `ssh keys` folder (`ssh.txt` + `hostinger_ryan_ed25519`). **Do not commit those files.**

### Cursor Cloud secrets (same names as GitHub Actions)

- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_USER`
- `HOSTINGER_SSH_PORT` (usually `65002`)
- `HOSTINGER_DEPLOY_PATH` (must contain `state4627`)
- `HOSTINGER_SSH_PRIVATE_KEY` (contents of `hostinger_ryan_ed25519`)

Or load from files if mounted in the VM:

```bash
export SSH_DIR="/path/to/ssh keys"
source scripts/load-hostinger-env.sh
./deploy.sh
```

Or pass secrets directly:

```bash
export HOSTINGER_SSH_PRIVATE_KEY="..."
./deploy.sh
```

Full setup: [.github/DEPLOYMENT.md](.github/DEPLOYMENT.md)

After deploy, run the first update via cron or:

```text
https://state4627.btkdeals.com/update.php?token=YOUR_TOKEN
```

The deploy script creates `config.json` on the server (state **4627**) if missing and preserves existing `data/*.json`.
