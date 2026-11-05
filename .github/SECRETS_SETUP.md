# GitHub Actions secrets (one-time)

Deploy fails until this secret exists:

👉 https://github.com/pakabbas/WOSStats/settings/secrets/actions/new

## Required

| Secret | Value |
|--------|--------|
| `HOSTINGER_SSH_PRIVATE_KEY` | Paste the **full** private key file (multiline). Prefer `hostinger_btkdeals_ed25519`. |

## If your key is encrypted

| Secret | Value |
|--------|--------|
| `HOSTINGER_SSH_KEY_PASSPHRASE` | The short passphrase for the key — **not** the key file contents. |

## Already configured in the workflow

- Host: `157.173.209.199`
- User: `u229715236`
- Port: `65002`
- Path: `domains/wostracker.online/public_html`

## After saving secrets

Push to `main` or run: **Actions → Deploy wostracker.online to Hostinger → Run workflow**

**Before first deploy:** add `nap_banned_password` to live `config.json` (see DEPLOYMENT.md).

Site: https://wostracker.online
