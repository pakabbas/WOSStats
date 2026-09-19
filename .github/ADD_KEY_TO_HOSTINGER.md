# One-time: add deploy key to Hostinger

The `hostinger_ryan_ed25519` key is **encrypted** (needs a passphrase GitHub cannot use).

Use this **new deploy key** instead (no passphrase):

## Step 1 — Hostinger hPanel

1. Log in to **Hostinger hPanel**
2. Go to **Advanced → SSH Access** (or **Remote access**)
3. Click **Add SSH key** / **Manage SSH keys**
4. Paste this **public key** (one line):

```
PASTE_PUBLIC_KEY_FROM_AGENT
```

5. Save

## Step 2 — GitHub secret

1. Open https://github.com/pakabbas/WOSStats/settings/secrets/actions
2. **Update** `HOSTINGER_SSH_PRIVATE_KEY` — paste the **private key** the agent gave you (multiline, BEGIN/END lines)
3. **Delete** `HOSTINGER_SSH_KEY_PASSPHRASE` if it exists (not needed)

## Step 3 — Deploy

Actions → **Deploy state4627 to Hostinger** → **Run workflow**
