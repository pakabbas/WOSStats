# One-time: add deploy key to Hostinger

If the existing Hostinger authorized key is passphrase-protected and GitHub Actions cannot use it, add an unencrypted deploy key.

## Step 1 — Hostinger hPanel

1. Log in to **Hostinger hPanel**
2. Go to **Advanced → SSH Access**
3. Click **Add SSH key** / **Manage SSH keys**
4. Paste the **public** deploy key (one line)
5. Save

## Step 2 — GitHub secret

1. Open https://github.com/pakabbas/WOSStats/settings/secrets/actions
2. **Update** `HOSTINGER_SSH_PRIVATE_KEY` — paste the matching **private** key (BEGIN/END lines)
3. **Delete** `HOSTINGER_SSH_KEY_PASSPHRASE` if it exists (not needed for an unencrypted key)

## Step 3 — Deploy

Actions → **Deploy wostracker.online to Hostinger** → **Run workflow**

Target: `domains/wostracker.online/public_html` → https://wostracker.online
