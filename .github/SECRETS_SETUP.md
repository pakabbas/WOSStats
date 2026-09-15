# One-time GitHub Secrets setup

Go to: **https://github.com/pakabbas/WOSStats/settings/secrets/actions**

Click **New repository secret** for each:

| Name | Value |
|------|--------|
| `HOSTINGER_SSH_PRIVATE_KEY` | Paste full contents of `hostinger_ryan_ed25519` (multiline, includes BEGIN/END lines) |
| `HOSTINGER_SSH_KEY_PASSPHRASE` | Your key unlock password (if the key is encrypted; leave empty if not) |

Connection details are already in the workflow file (not secrets):

- Host: `157.173.209.199`
- User: `u229715236`
- Port: `65002`
- Path: `domains/btkdeals.com/public_html/state4627`

After secrets are saved, push to `main` or run **Actions → Deploy state4627 to Hostinger → Run workflow**.
