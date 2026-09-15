#!/usr/bin/env bash
# Deploy WOSStats to state4627.btkdeals.com only.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

export HOSTINGER_SSH_HOST="${HOSTINGER_SSH_HOST:-157.173.209.199}"
export HOSTINGER_SSH_USER="${HOSTINGER_SSH_USER:-u229715236}"
export HOSTINGER_SSH_PORT="${HOSTINGER_SSH_PORT:-65002}"
export HOSTINGER_DEPLOY_PATH="${HOSTINGER_DEPLOY_PATH:-domains/btkdeals.com/public_html/state4627}"

if [[ -z "${HOSTINGER_SSH_KEY:-}" && -z "${HOSTINGER_SSH_PRIVATE_KEY:-}" ]]; then
    for candidate in \
        "${HOME}/.ssh/hostinger_ryan_ed25519" \
        "${HOME}/.cursor/projects/workspace/uploads/hostinger_ryan_ed25519_396d"; do
        if [[ -f "${candidate}" ]]; then
            export HOSTINGER_SSH_KEY="${candidate}"
            break
        fi
    done
fi

exec "${ROOT_DIR}/deploy.sh"
