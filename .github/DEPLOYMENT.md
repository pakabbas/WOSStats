# Deployment setup — wostracker.online

Deploy the WOSTracker / WOSStats site to **https://wostracker.online**
(`domains/wostracker.online/public_html` on Hostinger).

## 1. Local files (optional manual deploy)

```text
ssh keys/
├── ssh.txt
├── hostinger_btkdeals_ed25519       ← private key (never commit)
└── hostinger_btkdeals_ed25519.pub   ← optional
```

Copy `ssh.txt.example` to `ssh.txt` and fill in values from **hPanel → SSH Access**.

Expected `ssh.txt` format:

```text
Host=157.173.209.199
User=u229715236
Port=65002
Path=domains/wostracker.online/public_html
Key=hostinger_btkdeals_ed25519
```

## 2. GitHub repository secrets

Repo: https://github.com/pakabbas/WOSStats

| Secret name | Value source |
|-------------|--------------|
| `HOSTINGER_SSH_PRIVATE_KEY` | Full contents of `hostinger_btkdeals_ed25519` (or the key authorized on this Hostinger account) |
| `HOSTINGER_SSH_KEY_PASSPHRASE` | Only if the private key is encrypted |

Hard-coded in the workflow (no secrets needed for these):

- Host: `157.173.209.199`
- User: `u229715236`
- Port: `65002`
- Path: `domains/wostracker.online/public_html`

## 3. Server-only config (do not commit)

On the live server, `config.json` must exist and stay excluded from rsync.

Before the **first** auto-deploy after switching NAP password to config:

1. Add key `nap_banned_password` to live `config.json` (same value the old hardcoded constant used).
2. Leave `update_token`, DB, OAuth, and API keys only on the server.

If `nap_banned_password` is missing/empty, NAP banned admin actions **fail closed** (deny).

## 4. Deploy methods

### GitHub Actions

- **Automatic:** push/merge to `main`
- **Manual:** Actions → **Deploy wostracker.online to Hostinger** → **Run workflow**

Workflow file: `.github/workflows/deploy-wostracker.yml`

Rsync **does not use `--delete`** and excludes server-only paths (`config.json`, `data/*` runtime, uploads contents, `.git`, `.github`, logs).

### Local / Cloud Agent shell

```bash
export SSH_DIR="/path/to/ssh keys"
source scripts/load-hostinger-env.sh
./deploy.sh
```

Or set `HOSTINGER_SSH_HOST`, `HOSTINGER_SSH_USER`, `HOSTINGER_SSH_PORT`,
`HOSTINGER_DEPLOY_PATH=domains/wostracker.online/public_html`, and
`HOSTINGER_SSH_PRIVATE_KEY` / `HOSTINGER_SSH_KEY`.

## 5. Smoke checks

After deploy the workflow curls:

- `https://wostracker.online/` → expect HTTP 200
- `https://wostracker.online/players.php` → expect HTTP 200

## 6. Security checklist

- Never commit `ssh.txt`, private keys, or `config.json`
- Private key stays in GitHub/Cursor **secrets** only
- Deploy path must include `wostracker.online` (enforced in `deploy.sh` and the workflow)
