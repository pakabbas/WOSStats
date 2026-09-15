# Whiteout Survival State Alliance Tracker

## Goal

Build a very small PHP + HTML web application for tracking the alliances of **one Whiteout Survival state**.

This is an internal NAP/state-management tool.

The application should periodically collect alliance information and keep historical records so we can see how alliances are progressing.

## Important Constraints

- PHP 8+
- HTML/CSS/JavaScript only for frontend
- No PHP framework
- No database
- No Laravel
- No React
- No Vue
- No Node.js requirement
- No Docker
- No authentication system for the MVP
- No user accounts
- No multi-state support
- No unnecessary architecture

Use simple PHP files and JSON files.

The entire project should remain small and easy to understand.

---

# 1. State

The application tracks exactly **one state**.

Configure the state in:

```text
config.json

```

Example:

```json
{
    "state_id": "1234",
    "state_name": "State 1234"
}

```

Do not build functionality for multiple states.

---

# 2. Data Source

Before implementing the collector, research currently available Whiteout Survival APIs or publicly available data sources that can provide alliance information.

Look specifically for:

- Whiteout Survival alliance leaderboard
- Alliance ranking
- Alliance power
- Alliance member count
- State alliance data

Prefer an existing API if one provides the required data.

Do not invent API endpoints.

If an API requires an API key, store it in a configuration file that is not publicly accessible.

Example:

```json
{
    "state_id": "1234",
    "api_url": "",
    "api_key": ""
}

```

Document the required API configuration in `README.md`.

The data-source implementation should be isolated in:

```text
collector.php

```

Do not spread API-specific code throughout the application.

---

# 3. Data To Track

For every alliance, track:

- Alliance ID if available
- Alliance name
- Alliance tag
- Rank
- Total power
- Member count
- Last updated time

Example:

```json
{
    "id": "123456",
    "name": "Example Alliance",
    "tag": "ABC",
    "rank": 1,
    "power": 42300000000,
    "members": 98,
    "updated_at": "2026-09-15 09:00:00"
}

```

If a field is not available from the data source, use `null`.

Never fabricate data.

---

# 4. JSON Storage

Use JSON files instead of a database.

Recommended files:

```text
/config.json
/data/alliances.json
/data/history.json

```

### alliances.json

Contains the latest known alliance information.

Example:

```json
[
    {
        "id": "123",
        "name": "Alliance A",
        "tag": "ABC",
        "rank": 1,
        "power": 42300000000,
        "members": 98,
        "updated_at": "2026-09-15 09:00:00"
    }
]

```

### history.json

Contains historical snapshots.

Example:

```json
[
    {
        "timestamp": "2026-09-15 09:00:00",
        "alliances": [
            {
                "id": "123",
                "name": "Alliance A",
                "tag": "ABC",
                "rank": 1,
                "power": 42300000000,
                "members": 98
            }
        ]
    }
]

```

Create the files automatically if they don't exist.

---

# 5. Dashboard

Create a single main page:

```text
index.php

```

The dashboard should show:

```text
WHITEOUT SURVIVAL
STATE 1234

Last Updated: 15 Sep 2026 09:00

```

Then an alliance table:


| Rank | Alliance | Tag | Power | Members | 24h Change | 7d Change |
| ---- | -------- | --- | ----- | ------- | ---------- | --------- |


Sort by current rank.

Power should be displayed in a readable format:

```text
42.3B
850M
520K

```

but store the actual numeric value in JSON.

---

# 6. Alliance Progress

The main purpose of the application is to see alliance progress.

For each alliance calculate:

### Power change

- Since previous snapshot
- Last 24 hours
- Last 7 days

### Rank change

Example:

```text
↑ 2
↓ 1
—

```

Do not calculate a value if there isn't enough historical data.

Display:

```text
N/A

```

instead.

---

# 7. Alliance Details

Clicking an alliance should show a simple detail page:

```text
alliance.php?id=123

```

Display:

```text
Alliance Name
Tag
Current Rank
Current Power
Members
Last Updated

```

Then show:

```text
Power History

```

A simple line chart is enough.

Use a lightweight JavaScript chart library only if necessary.

No complex frontend framework.

---

# 8. NAP Alliances

We need to identify which alliances are part of NAP.

Add this to `config.json`:

```json
{
    "state_id": "1234",
    "state_name": "State 1234",

    "nap_alliances": [
        "ABC",
        "XYZ",
        "DEF"
    ]
}

```

The value should contain alliance tags.

On the dashboard:

- NAP alliances should be clearly identifiable.
- Add a simple filter:

```text
All
NAP
Non-NAP

```

Do not automatically determine NAP membership.

NAP membership is manually configured.

---

# 9. Updating Data

There should be a simple way to update the data.

Create:

```text
update.php

```

When accessed, it should:

1. Read the configured state ID.
2. Call the configured data source.
3. Retrieve the current alliance list.
4. Validate the response.
5. Save the latest data to `data/alliances.json`.
6. Add a snapshot to `data/history.json`.

Example:

```text
/update.php

```

should perform an update.

Display a simple result:

```text
Update successful.

Alliances found: 38
Updated: 15 Sep 2026 09:00

```

If the update fails:

```text
Update failed.

Previous data has NOT been modified.

```

---

# 10. Automatic Updates

The application should be designed so `update.php` can be called by a cron job.

Do not build an internal scheduler.

The server administrator can configure cron:

```text
0 * * * * php /path/to/update.php

```

This runs once every hour.

Document the cron setup in `README.md`.

---

# 11. Protect Update Endpoint

Because `update.php` changes files, add a simple secret token.

Configure:

```json
{
    "update_token": "CHANGE_ME"
}

```

The endpoint should require:

```text
/update.php?token=CHANGE_ME

```

If the token is missing or incorrect:

```text
403 Forbidden

```

Do not build a full authentication system.

---

# 12. JSON Safety

When writing JSON:

- Use `JSON_PRETTY_PRINT`
- Use UTF-8
- Do not corrupt existing files if an update fails.
- Write to a temporary file first.
- Replace the original only after the write succeeds.

Example:

```text
data/alliances.json.tmp

```

then replace:

```text
data/alliances.json

```

Do the same for `history.json`.

---

# 13. History Management

Do not create unlimited history.

Keep one snapshot per successful update.

For the MVP, retain the last:

```text
90 days

```

Older snapshots can be automatically removed.

This keeps `history.json` small.

If the update runs hourly, this is approximately:

```text
2160 snapshots

```

This is acceptable for a small JSON file.

---

# 14. Failed Updates

Never overwrite good data with bad data.

If the API:

- times out
- returns an error
- returns invalid JSON
- returns zero alliances unexpectedly
- returns incomplete data

then:

- Do not modify `alliances.json`
- Do not add a history snapshot
- Show/log the error

The existing data must remain intact.

---

# 15. Dashboard Statistics

At the top of the dashboard show only a few useful statistics:

```text
State Power
Total Alliances
NAP Alliances
Last Updated

```

Avoid unnecessary statistics.

---

# 16. Search

Add a simple search box to the alliance table.

Search by:

- Alliance name
- Alliance tag

Client-side JavaScript filtering is sufficient.

No database/search engine is needed.

---

# 17. Responsive Design

The application should work on:

- Desktop
- Mobile

Keep the design minimal.

Use plain CSS.

No Bootstrap is required unless it significantly simplifies the implementation.

Recommended style:

- White background
- Simple cards
- Simple table
- Small number of colors
- Clear typography
- No animations unless useful

The application should look like a small admin dashboard, not a marketing website.

---

# 18. File Structure

Keep the project very small.

Recommended structure:

```text
whiteout-tracker/
│
├── index.php
├── alliance.php
├── update.php
├── collector.php
├── functions.php
├── config.json
├── README.md
├── .gitignore
│
├── data/
│   ├── alliances.json
│   └── history.json
│
└── assets/
    ├── style.css
    └── app.js

```

Do not create additional layers unless genuinely necessary.

---

# 19. functions.php

Put reusable functions here.

Examples:

```php
loadConfig()
saveJson()
loadJson()
formatPower()
calculatePowerChange()
calculateRankChange()
getAllianceHistory()

```

Keep functions small and focused.

---

# 20. collector.php

This file should contain the Whiteout Survival data-source integration.

Example interface:

```php
function fetchAlliances(string $stateId): array
{
    // Retrieve alliance data
}

```

The rest of the application should call:

```php
fetchAlliances($stateId)

```

rather than knowing how the external API works.

---

# 21. Security

Do not expose:

```text
config.json
data/

```

directly through the web server if they contain secrets or private information.

Ideally place them outside the public web root.

If that is not possible, configure `.htaccess` to deny direct access.

The update token must never be displayed in the frontend.

Do not log the API key or update token.

---

# 22. README

Create a simple `README.md` containing:

### Installation

```text
1. Upload files
2. Set PHP 8+
3. Configure config.json
4. Make data/ writable
5. Open index.php

```

### Configuration

Explain:

```json
{
    "state_id": "1234",
    "state_name": "State 1234",
    "api_url": "",
    "api_key": "",
    "update_token": "CHANGE_ME",
    "nap_alliances": []
}

```

### Manual update

```text
/update.php?token=YOUR_TOKEN

```

### Cron

Provide the cron command required for hourly updates.

---

# 23. Development Approach

Before coding:

1. Research current Whiteout Survival alliance data APIs.
2. Determine the best available source.
3. Confirm what alliance fields are actually available.
4. Implement the collector.
5. Test the collector with the configured state.
6. Build the JSON storage.
7. Build the dashboard.
8. Build historical calculations.
9. Build the alliance detail page.
10. Test failed API responses.

Do not create fake API endpoints.

Do not create fake production data.

Sample data may only be used temporarily to build the UI and must be clearly identified as sample data.

---

# 24. Keep It Minimal

This is intentionally a tiny application.

Do NOT add:

- Database
- ORM
- Framework
- REST API for the tracker
- User registration
- User management
- Roles/permissions
- React
- Vue
- Node.js
- Docker
- Redis
- Queues
- Background workers
- Microservices
- Complex configuration system

The ideal implementation is a few PHP files, two JSON data files, CSS and a small amount of JavaScript.

---

# 25. Final Result

After installation I should be able to:

1. Set my state ID in `config.json`.
2. Set the data-source/API credentials.
3. Set my NAP alliance tags.
4. Open `index.php`.
5. See all alliances ranked by power.
6. See their current power and member count.
7. See how their power changed over 24 hours and 7 days.
8. Click an alliance to see its history.
9. Run `update.php` manually.
10. Configure a cron job to update it automatically.

Keep the implementation simple, reliable and easy to modify.