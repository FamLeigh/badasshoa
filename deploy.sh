#!/usr/bin/env bash
# One-shot deploy for badasshoa.com — rsyncs ~/badasshoa to public_html.
# Idempotent. Safe to re-run.
#
# CRITICAL: rsync uses --delete, so anything in dst not in src gets removed
# unless explicitly excluded below. Production-mutable files (config.php,
# uploads, logs, admin-editable JSON) MUST be excluded or they will be wiped.
set -euo pipefail

REPO_DIR="${HOME}/badasshoa"
WEB_DIR="${HOME}/domains/badasshoa.com/public_html"

# Hostinger ships a placeholder default.php in fresh public_html dirs.
# Remove it once on first deploy so it doesn't shadow our index.php.
if [ -f "$WEB_DIR/default.php" ]; then
    echo '── Removing Hostinger default.php placeholder…'
    rm -f "$WEB_DIR/default.php"
fi

echo '── Syncing to public_html…'
rsync -a --delete \
  --exclude='.git' \
  --exclude='.gitignore' \
  --exclude='.gitattributes' \
  --exclude='deploy.sh' \
  --exclude='*.md' \
  --exclude='migrations/' \
  --exclude='config.php' \
  --exclude='config.local.php' \
  \
  --exclude='storage/uploads/' \
  --exclude='storage/logs/' \
  \
  --exclude='content/changelog.json' \
  \
  "$REPO_DIR/" "$WEB_DIR/"

# Make sure the runtime dirs exist + are writable (rsync doesn't recreate
# excluded paths, and a fresh public_html won't have them).
mkdir -p "$WEB_DIR/storage/uploads" "$WEB_DIR/storage/logs"
chmod 755 "$WEB_DIR/storage" "$WEB_DIR/storage/uploads" "$WEB_DIR/storage/logs"

# Same for the admin-editable changelog JSON: if absent, seed from repo copy
# so /changelog.php has something to render. Subsequent deploys leave it alone.
if [ ! -f "$WEB_DIR/content/changelog.json" ] && [ -f "$REPO_DIR/content/changelog.json" ]; then
    mkdir -p "$WEB_DIR/content"
    cp "$REPO_DIR/content/changelog.json" "$WEB_DIR/content/changelog.json"
fi

# Force PHP opcache to revalidate every .php file on the next request.
# rsync preserves mtimes from the source, which can match what opcache
# already has cached — leaving stale bytecode in memory while the on-disk
# file looks "new enough." Touching every .php bumps mtime to now.
echo '── Refreshing PHP opcache (touching all *.php)…'
find "$WEB_DIR" -type f -name '*.php' -exec touch {} +

echo '── Done.'
echo "   Repo:  $REPO_DIR"
echo "   Web:   $WEB_DIR"
