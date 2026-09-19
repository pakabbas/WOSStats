# Deployment setup — state4627.btkdeals.com

Deploy **only** the WOSStats tracker to `https://state4627.btkdeals.com`. Do not use these credentials or paths for other Hostinger sites.

## 1. Local files (Windows)

From your machine:

```text
D:\ssh\ssh keys\
├── ssh.txt
├── hostinger_ryan_ed25519       ← private key (never commit)
└── hostinger_ryan_ed25519.pub   ← optional
```

Copy `ssh.txt.example` from this repo to `ssh.txt` and fill in values from **hPanel → SSH Access**.

Expected `ssh.txt` format:

```text
Host=157.173.209.199
User=u229715236
Port=65002
Path=domains/btkdeals.com/public_html/state4627
Key=hostinger_ryan_ed25519
```

If the private key is passphrase-protected, also set secret `HOSTINGER_SSH_KEY_PASSPHRASE`.

Confirm the **Path** points at the `state4627` subdomain document root only.

## 2. GitHub repository secrets

Repo: https://github.com/pakabbas/WOSStats

Go to **Settings → Secrets and variables → Actions → New repository secret** and add:

| Secret name | Value source |
|-------------|--------------|
| `HOSTINGER_SSH_HOST` | `Host=` line in `ssh.txt` |
| `HOSTINGER_SSH_USER` | `User=` line in `ssh.txt` |
| `HOSTINGER_SSH_PORT` | `Port=` line in `ssh.txt` (usually `65002`) |
| `HOSTINGER_DEPLOY_PATH` | `Path=` line in `ssh.txt` (must contain `state4627`) |
| `HOSTINGER_SSH_PRIVATE_KEY` | Full contents of `hostinger_ryan_ed25519` |
| `HOSTINGER_SSH_KEY_PASSPHRASE` | Passphrase for the encrypted private key (if applicable) |

### GitHub Environment (recommended)

Create environment **`production-state4627`** under **Settings → Environments** and attach the same secrets there. The workflow uses this environment so production deploy credentials are scoped to state4627.

## 3. Cursor Cloud secrets (for Cloud Agents)

In your Cursor **Cloud Environment** for this repo, add the same five secrets:

- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_USER`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_DEPLOY_PATH`
- `HOSTINGER_SSH_PRIVATE_KEY`

Cloud agents can then run `./deploy.sh` without local `D:\` paths.

## 4. Deploy methods

### GitHub Actions (after secrets are set)

- **Automatic:** push/merge to `main` (excludes markdown-only changes)
- **Manual:** Actions → **Deploy state4627 to Hostinger** → **Run workflow**

### Local / Cloud Agent shell

```bash
# Option A — from ssh.txt + key file
export SSH_DIR="/path/to/ssh keys"
source scripts/load-hostinger-env.sh
./deploy.sh

# Option B — from environment secrets
export HOSTINGER_SSH_HOST=...
export HOSTINGER_SSH_USER=...
export HOSTINGER_SSH_PORT=65002
export HOSTINGER_DEPLOY_PATH=/home/.../state4627.../public_html
export HOSTINGER_SSH_PRIVATE_KEY="$(cat hostinger_ryan_ed25519)"
./deploy.sh
```

## 5. After first deploy

1. SSH to the server or use File Manager to read `config.json` → copy `update_token`
2. Run first update: `https://state4627.btkdeals.com/update.php?token=YOUR_TOKEN`
3. Configure `nap_alliances` and `api_url` in server `config.json`
4. Optional cron (hourly): `0 * * * * curl -fsS "https://state4627.btkdeals.com/update.php?token=YOUR_TOKEN"`

## 6. Security checklist

- Never commit `ssh.txt`, `hostinger_ryan_ed25519`, or `config.json`
- Private key stays in GitHub/Cursor **secrets** only
- Deploy path must include `state4627` (enforced in `deploy.sh` and the workflow)
