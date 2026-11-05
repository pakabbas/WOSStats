#!/usr/bin/env bash
set -euo pipefail

# Deploy WOSTracker to Hostinger (wostracker.online only).
# Required environment variables:
#   HOSTINGER_SSH_HOST      e.g. 157.173.209.199
#   HOSTINGER_SSH_USER      e.g. u229715236
#   HOSTINGER_SSH_PORT      e.g. 65002
#   HOSTINGER_SSH_KEY            path to private key file
#   HOSTINGER_SSH_PRIVATE_KEY    alternative: private key contents
#   HOSTINGER_DEPLOY_PATH        e.g. domains/wostracker.online/public_html

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${HOSTINGER_SSH_HOST:?HOSTINGER_SSH_HOST is required}"
: "${HOSTINGER_SSH_USER:?HOSTINGER_SSH_USER is required}"
: "${HOSTINGER_SSH_PORT:=65002}"
: "${HOSTINGER_DEPLOY_PATH:?HOSTINGER_DEPLOY_PATH is required}"

if [[ -z "${HOSTINGER_SSH_KEY:-}" && -n "${HOSTINGER_SSH_PRIVATE_KEY:-}" ]]; then
    HOSTINGER_SSH_KEY="$(mktemp)"
    chmod 600 "${HOSTINGER_SSH_KEY}"
    printf '%s\n' "${HOSTINGER_SSH_PRIVATE_KEY}" > "${HOSTINGER_SSH_KEY}"
    trap 'rm -f "${HOSTINGER_SSH_KEY}"' EXIT
fi

: "${HOSTINGER_SSH_KEY:?HOSTINGER_SSH_KEY or HOSTINGER_SSH_PRIVATE_KEY is required}"

if [[ "${HOSTINGER_DEPLOY_PATH}" != *wostracker.online* ]]; then
    echo "Refusing to deploy: HOSTINGER_DEPLOY_PATH must contain 'wostracker.online'." >&2
    exit 1
fi

validate_passphrase_secret() {
    if [[ -n "${HOSTINGER_SSH_KEY_PASSPHRASE:-}" && "${HOSTINGER_SSH_KEY_PASSPHRASE}" == *BEGIN*OPENSSH* ]]; then
        echo "Error: HOSTINGER_SSH_KEY_PASSPHRASE contains a private key, not the key passphrase." >&2
        exit 1
    fi
}

setup_ssh_agent_if_needed() {
    if ! ssh-keygen -y -f "${HOSTINGER_SSH_KEY}" >/dev/null 2>&1; then
        validate_passphrase_secret
        : "${HOSTINGER_SSH_KEY_PASSPHRASE:?HOSTINGER_SSH_KEY_PASSPHRASE is required (private key is encrypted)}"
        eval "$(ssh-agent -s)" >/dev/null
        ASKPASS_SCRIPT="$(mktemp)"
        chmod 700 "${ASKPASS_SCRIPT}"
        cat > "${ASKPASS_SCRIPT}" <<'ASKPASS'
#!/bin/sh
printf '%s' "${HOSTINGER_SSH_KEY_PASSPHRASE}"
ASKPASS
        export DISPLAY="${DISPLAY:-:1}"
        export SSH_ASKPASS="${ASKPASS_SCRIPT}"
        export SSH_ASKPASS_REQUIRE=force
        ssh-add "${HOSTINGER_SSH_KEY}" </dev/null
        trap 'ssh-agent -k >/dev/null 2>&1; rm -f "${ASKPASS_SCRIPT}"' EXIT
        echo "Loaded encrypted SSH key via ssh-agent."
    fi
}

setup_ssh_agent_if_needed

SSH_OPTS=(
    -p "${HOSTINGER_SSH_PORT}"
    -o StrictHostKeyChecking=accept-new
    -o BatchMode=yes
)

if ssh-keygen -y -f "${HOSTINGER_SSH_KEY}" >/dev/null 2>&1; then
    SSH_OPTS+=(-i "${HOSTINGER_SSH_KEY}")
fi

REMOTE="${HOSTINGER_SSH_USER}@${HOSTINGER_SSH_HOST}"

echo "Deploying to ${REMOTE}:${HOSTINGER_DEPLOY_PATH}"

# No --delete: never remove server-only runtime files.
rsync -avz \
    -e "ssh ${SSH_OPTS[*]}" \
    --exclude '.git/' \
    --exclude '.github/' \
    --exclude 'config.json' \
    --exclude 'error_log' \
    --exclude 'ssh.txt' \
    --exclude '.env' \
    --exclude '.env.*' \
    --exclude 'data/*.json' \
    --exclude 'data/*.log' \
    --exclude 'data/*.lock' \
    --exclude 'data/*.tmp' \
    --exclude 'data/atlas-cookies.txt' \
    --exclude 'data/rosters/' \
    --exclude 'data/roster-imports/' \
    --exclude 'data/roster-powers/' \
    --exclude 'data/atlas-v2/' \
    --exclude 'data/svs-*' \
    --exclude 'data/site-visits.json' \
    --exclude 'uploads/video/*' \
    --exclude 'uploads/chat/*' \
    --exclude 'uploads/incidents/*' \
    --exclude 'uploads/cache/' \
    --exclude 'Requirements.md' \
    --exclude 'deploy.sh' \
    --exclude 'scripts/' \
    --exclude 'ssh.txt.example' \
    --include 'uploads/video/.htaccess' \
    --include 'uploads/chat/.htaccess' \
    --include 'uploads/incidents/.htaccess' \
    --include 'data/.htaccess' \
    "${ROOT_DIR}/" "${REMOTE}:${HOSTINGER_DEPLOY_PATH}/"

ssh "${SSH_OPTS[@]}" "${REMOTE}" "bash -s" <<REMOTE
set -euo pipefail
DEPLOY_PATH="${HOSTINGER_DEPLOY_PATH}"
cd "\${DEPLOY_PATH}"
mkdir -p data uploads/video uploads/chat uploads/incidents
chmod 755 data uploads
if [ ! -f config.json ]; then
    echo "WARNING: config.json missing — create it on the server before relying on NAP/admin features."
fi
echo "Deployment complete at \${DEPLOY_PATH}"
REMOTE

echo "Done. Visit https://wostracker.online/"
