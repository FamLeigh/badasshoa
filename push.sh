#!/usr/bin/env bash
# Laptop-side deploy: rsync local repo → server, then run server-side deploy.sh.
# Idempotent. Safe to re-run.
#
# Pairs with deploy.sh on the server, which rsyncs ~/badasshoa/ → public_html/
# and busts opcache. Together they replace the git-pull-on-server pattern that
# sellinglane uses, since BadassHOA doesn't have a GitHub remote yet.
#
# Run from the repo root:   bash push.sh
set -euo pipefail
cd "$(dirname "$0")"

SSH_HOST="u535581001@77.37.59.82"
SSH_PORT=65002
REMOTE_REPO="~/badasshoa/"

echo "── Syncing $(pwd) → ${SSH_HOST}:${REMOTE_REPO}…"
# --delete keeps server in sync. Server-side config.php, uploads, logs are
# protected here AND again in deploy.sh's exclude list.
rsync -az --delete -e "ssh -p ${SSH_PORT}" \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='storage/uploads/' \
  --exclude='storage/logs/' \
  --exclude='config.php' \
  ./ "${SSH_HOST}:${REMOTE_REPO}"

echo "── Running server-side deploy.sh…"
ssh -p ${SSH_PORT} "${SSH_HOST}" 'bash ~/badasshoa/deploy.sh'

echo "── Live: https://badasshoa.com/"
