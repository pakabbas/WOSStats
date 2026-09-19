# Whiteout Survival State Alliance Tracker

A small PHP application for tracking alliance rankings and power progress for **one** Whiteout Survival state. Designed for internal NAP/state management.

## Features

- Dashboard with alliance rankings, power, members, and 24h/7d power changes
- NAP alliance highlighting and filtering
- Alliance detail page with power history chart
- JSON file storage (no database)
- Token-protected manual/cron updates
- Safe atomic JSON writes

## Requirements

- PHP 8.0 or newer
- PHP extensions: `json`, `curl`
- Web server (Apache/Nginx) or PHP built-in server for development
- Writable `data/` directory

## Installation

1. Upload/copy project files to your server
2. Ensure PHP 8+ is installed with `curl` enabled
3. Copy `config.example.json` to `config.json` and configure it
4. Make `data/` writable: `chmod 755 data` (or `775` if needed)
5. Run an initial update (see below)
6. Open `index.php` in your browser

### Local development

```bash
php -S localhost:8080 -t .
```

Then visit `http://localhost:8080/index.php`

## Configuration

Edit `config.json`:

```json
{
    "state_id": "1234",
    "state_name": "State 1234",
    "api_url": "",
    "api_key": "",
    "update_token": "CHANGE_ME",
    "sample_mode": false,
    "nap_alliances": ["ABC", "XYZ"]
}
```

| Field | Description |
|-------|-------------|
| `state_id` | Your kingdom/state ID |
| `state_name` | Display name on the dashboard |
| `api_url` | URL of your HTTP data bridge (see Data Source below) |
| `api_key` | Optional API key sent as `Authorization: Bearer` and `X-API-Key` headers |
| `update_token` | Secret token required to run updates |
| `sample_mode` | **Development only.** Loads data from `data/sample-alliances.json` |
| `nap_alliances` | List of alliance tags manually marked as NAP |

## Data Source

Default production sources for this project: **[WOS Atlas](https://api.wosatlas.com)**

Alliances (top 20):

```text
https://api.wosatlas.com/v1/alliances/leaderboard?limit=20&kid={state_id}
```

Players (top 200 power ranking):

```text
https://api.wosatlas.com/v1/power/leaderboard?limit=200&kid={state_id}
```

Stored as JSON under `data/alliances.json`, `data/players.json`, plus history snapshots.

```json
{
    "alliances": [
        {
            "id": "123456",
            "name": "Example Alliance",
            "tag": "ABC",
            "rank": 1,
            "power": 42300000000,
            "members": 98
        }
    ]
}
```

WOS Atlas uses `entries` with aliases `aid` → id, `abbr` → tag, `totalPower` → power (mapped automatically).

URL placeholders:

- Use `{state_id}` in the URL (recommended): `...?kid={state_id}`
- Or omit it and the collector appends `kid=` automatically
- If the URL already has `kid=` / `state_id=`, it is left unchanged

Unavailable fields should be omitted or set to `null`. The collector never fabricates data.

### Development sample data

Set `"sample_mode": true` to load alliances from `data/sample-alliances.json`. This is clearly marked on the dashboard and must not be used in production.

## Manual Update

```
/update.php?token=YOUR_TOKEN
```

Success response:

```
Update successful.

Alliances found: 38
Updated: 15 Sep 2026 09:00
```

On failure, existing data is **not** modified.

## Cron (daily monitoring)

Recommended: one snapshot per day. Each run saves current alliances and appends a history snapshot (kept for 90 days).

```cron
0 6 * * * curl -fsS "https://state4627.btkdeals.com/update.php?token=YOUR_TOKEN" >/dev/null
```

That runs daily at 06:00 server time. For more frequent charts you can also use hourly:

```cron
0 * * * * curl -fsS "https://state4627.btkdeals.com/update.php?token=YOUR_TOKEN" >/dev/null
```

## Comparing alliances

Open **Compare alliances** from the dashboard, or from an alliance detail page. Pick two alliances to see side-by-side power growth.

## Security

- Do not expose `config.json` or `data/` via the web server (`.htaccess` included for Apache)
- Never commit `config.json` with real tokens/keys
- The update token is never shown in the frontend
- API keys and tokens are not logged

## File Structure

```
├── index.php          Dashboard
├── alliance.php       Alliance detail + growth chart
├── compare.php        Compare two alliances
├── update.php         Data update endpoint
├── collector.php      External data source integration
├── functions.php      Shared helpers
├── config.json        Your configuration (not in git)
├── config.example.json
├── data/
│   ├── alliances.json Current alliance data
│   ├── history.json   Historical snapshots (90 days retained)
│   └── sample-alliances.json  Dev sample data
└── assets/
    ├── style.css
    └── app.js
```

## Deployment (Hostinger — State 4627)

Production URL: https://state4627.btkdeals.com

See **[.github/DEPLOYMENT.md](.github/DEPLOYMENT.md)** for:

- Mapping `D:\ssh\ssh keys\ssh.txt` + `hostinger_ryan_ed25519` to GitHub/Cursor secrets
- GitHub Actions deploy workflow (`production-state4627` environment)
- Manual `./deploy.sh` from Cloud Agents

## License

Internal tool — modify freely for your state.
