#!/usr/bin/env bash
set -euo pipefail

# Deploy Whiteout Survival tracker to Hostinger (state4627 only).
# Required environment variables:
#   HOSTINGER_SSH_HOST      e.g. 157.173.209.199
#   HOSTINGER_SSH_USER      e.g. u123456789
#   HOSTINGER_SSH_PORT      e.g. 65002
#   HOSTINGER_SSH_KEY            path to private key file (hostinger_ryan_ed25519)
#   HOSTINGER_SSH_PRIVATE_KEY    alternative: private key contents (GitHub Actions / Cursor secrets)
#   HOSTINGER_DEPLOY_PATH        e.g. /home/u123456789/domains/state4627.btkdeals.com/public_html

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

if [[ "${HOSTINGER_DEPLOY_PATH}" != *state4627* ]]; then
    echo "Refusing to deploy: HOSTINGER_DEPLOY_PATH must contain 'state4627'." >&2
    exit 1
fi

validate_passphrase_secret() {
    if [[ -n "${HOSTINGER_SSH_KEY_PASSPHRASE:-}" && "${HOSTINGER_SSH_KEY_PASSPHRASE}" == *BEGIN*OPENSSH* ]]; then
        echo "Error: HOSTINGER_SSH_KEY_PASSPHRASE contains a private key, not the key passphrase." >&2
        echo "Set HOSTINGER_SSH_KEY_PASSPHRASE to the short password for hostinger_ryan_ed25519." >&2
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

rsync -avz --delete \
    -e "ssh ${SSH_OPTS[*]}" \
    --exclude '.git/' \
    --exclude 'config.json' \
    --exclude 'data/alliances.json' \
    --exclude 'data/history.json' \
    --exclude 'data/*.tmp' \
    --exclude 'Requirements.md' \
    --exclude 'deploy.sh' \
    "${ROOT_DIR}/" "${REMOTE}:${HOSTINGER_DEPLOY_PATH}/"

ssh "${SSH_OPTS[@]}" "${REMOTE}" "bash -s" <<EOF
set -euo pipefail
DEPLOY_PATH="${HOSTINGER_DEPLOY_PATH}"
cd "\${DEPLOY_PATH}"

mkdir -p data
chmod 755 data

if [ ! -f config.json ]; then
    UPDATE_TOKEN=\$(php -r 'echo bin2hex(random_bytes(16));')
    cat > config.json <<CONFIG
{
    "state_id": "4627",
    "state_name": "State 4627",
    "api_url": "",
    "api_key": "",
    "update_token": "\${UPDATE_TOKEN}",
    "sample_mode": false,
    "nap_alliances": []
}
CONFIG
    chmod 600 config.json
    echo "Created config.json with a new update_token."
fi

if [ ! -f data/alliances.json ]; then
    echo '[]' > data/alliances.json
fi
if [ ! -f data/history.json ]; then
    echo '[]' > data/history.json
fi
chmod 664 data/alliances.json data/history.json 2>/dev/null || true

echo "Deployment complete at \${DEPLOY_PATH}"
EOF

echo "Done. Visit https://state4627.btkdeals.com/"
