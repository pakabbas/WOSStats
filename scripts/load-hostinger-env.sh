#!/usr/bin/env bash
# Load Hostinger SSH settings from ssh.txt + hostinger_ryan_ed25519 key file.
# Usage (from repo root):
#   export SSH_DIR="/path/to/ssh keys"
#   source scripts/load-hostinger-env.sh
#   ./deploy.sh

set -euo pipefail

SSH_DIR="${SSH_DIR:-${HOME}/.ssh/hostinger}"
SSH_TXT="${SSH_TXT:-${SSH_DIR}/ssh.txt}"
KEY_NAME="${KEY_NAME:-hostinger_ryan_ed25519}"
KEY_FILE="${KEY_FILE:-${SSH_DIR}/${KEY_NAME}}"

if [[ ! -f "${SSH_TXT}" ]]; then
    echo "ssh.txt not found at ${SSH_TXT}" >&2
    echo "Set SSH_DIR or SSH_TXT to your local 'ssh keys' folder (e.g. D:\\ssh\\ssh keys on Windows)." >&2
    return 1 2>/dev/null || exit 1
fi

if [[ ! -f "${KEY_FILE}" ]]; then
    echo "Private key not found at ${KEY_FILE}" >&2
    return 1 2>/dev/null || exit 1
fi

trim() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

while IFS= read -r line || [[ -n "$line" ]]; do
    line="$(trim "$line")"
    [[ -z "$line" || "$line" == \#* ]] && continue

    key="${line%%=*}"
    val="${line#*=}"
    key="$(trim "$key")"
    val="$(trim "$val")"
    key_lower="$(echo "$key" | tr '[:upper:]' '[:lower:]' | tr -d ' ')"

    case "$key_lower" in
        host|hostname|ssh_host|hostinger_host)
            export HOSTINGER_SSH_HOST="$val"
            ;;
        user|username|ssh_user|hostinger_user)
            export HOSTINGER_SSH_USER="$val"
            ;;
        port|ssh_port|hostinger_port)
            export HOSTINGER_SSH_PORT="$val"
            ;;
        path|deploy_path|remote_path|hostinger_deploy_path|document_root)
            export HOSTINGER_DEPLOY_PATH="$val"
            ;;
        key|keyfile|private_key|ssh_key)
            if [[ -f "${SSH_DIR}/${val}" ]]; then
                KEY_FILE="${SSH_DIR}/${val}"
            elif [[ -f "$val" ]]; then
                KEY_FILE="$val"
            fi
            ;;
    esac
done < "${SSH_TXT}"

export HOSTINGER_SSH_PORT="${HOSTINGER_SSH_PORT:-65002}"
export HOSTINGER_SSH_KEY="${KEY_FILE}"
chmod 600 "${HOSTINGER_SSH_KEY}" 2>/dev/null || true

: "${HOSTINGER_SSH_HOST:?HOSTINGER_SSH_HOST missing in ssh.txt}"
: "${HOSTINGER_SSH_USER:?HOSTINGER_SSH_USER missing in ssh.txt}"
: "${HOSTINGER_DEPLOY_PATH:?HOSTINGER_DEPLOY_PATH missing in ssh.txt}"

if [[ "${HOSTINGER_DEPLOY_PATH}" != *state4627* ]]; then
    echo "Warning: deploy path does not contain state4627: ${HOSTINGER_DEPLOY_PATH}" >&2
fi

echo "Loaded Hostinger env for ${HOSTINGER_SSH_USER}@${HOSTINGER_SSH_HOST}:${HOSTINGER_SSH_PORT}"
