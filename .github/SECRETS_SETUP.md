# GitHub Actions secrets (one-time, ~2 minutes)

**CI/CD is live on `main`.** Deploy fails until this secret exists:

👉 https://github.com/pakabbas/WOSStats/settings/secrets/actions/new

## Required

| Secret | Value |
|--------|--------|
| `HOSTINGER_SSH_PRIVATE_KEY` | Paste the **full** private key file (multiline). Use `hostinger_btkdeals_ed25519` from your `D:\ssh keys\` folder if available (recommended in `ssh.txt`), or `hostinger_ryan_ed25519` if that is the key on the server. |

## If your key is encrypted (passphrase prompt on Windows)

Add a second secret:

| Secret | Value |
|--------|--------|
| `HOSTINGER_SSH_KEY_PASSPHRASE` | The **short password** you enter when SSH asks for a passphrase — **not** the key file contents. |

## Already configured in the workflow (no secrets needed)

- Host: `157.173.209.199`
- User: `u229715236`
- Port: `65002`
- Path: `domains/btkdeals.com/public_html/state4627` (**state4627 only**, not `rcv`)

## After saving secrets

Push to `main` or run: **Actions → Deploy state4627 to Hostinger → Run workflow**

Site: https://state4627.btkdeals.com
