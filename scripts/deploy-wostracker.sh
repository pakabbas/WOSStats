#!/usr/bin/env bash
# Thin wrapper: load ssh.txt env then run repo-root deploy.sh for wostracker.online
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=load-hostinger-env.sh
source "${ROOT_DIR}/scripts/load-hostinger-env.sh"
export HOSTINGER_DEPLOY_PATH="${HOSTINGER_DEPLOY_PATH:-domains/wostracker.online/public_html}"
exec "${ROOT_DIR}/deploy.sh"
