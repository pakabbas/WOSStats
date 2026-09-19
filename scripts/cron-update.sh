#!/bin/sh
# Hostinger cron helper for WOSTracker (wostracker.online)
set -eu

ROOT="/home/u229715236/domains/wostracker.online/public_html"
CONFIG="$ROOT/config.json"
LOG="$ROOT/data/cron-update.log"

if [ ! -f "$CONFIG" ]; then
  echo "$(date -u '+%Y-%m-%d %H:%M:%S') UTC  missing config.json" >> "$LOG"
  exit 1
fi

TOKEN=$(php -r '$c=json_decode(file_get_contents($argv[1]), true); echo $c["update_token"] ?? "";' "$CONFIG")
if [ -z "$TOKEN" ] || [ "$TOKEN" = "CHANGE_ME" ]; then
  echo "$(date -u '+%Y-%m-%d %H:%M:%S') UTC  missing update_token" >> "$LOG"
  exit 1
fi

URL="https://wostracker.online/update.php?token=${TOKEN}"
echo "$(date -u '+%Y-%m-%d %H:%M:%S') UTC  starting update" >> "$LOG"
BODY=$(curl -fsS --max-time 120 "$URL" 2>&1) || {
  echo "$(date -u '+%Y-%m-%d %H:%M:%S') UTC  FAILED: $BODY" >> "$LOG"
  exit 1
}
echo "$(date -u '+%Y-%m-%d %H:%M:%S') UTC  OK: $BODY" >> "$LOG"
