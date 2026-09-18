#!/usr/bin/env bash
# Assemble the local XAMPP environment, mirroring the server layout:
#
#   ~/olisa-local/olisa-app/     <- private: src, config.php, schema, storage
#   ~/olisa-local/public_html/   <- docroot: the exported site + api/ + admin/
#
#   ./scripts/local.sh            # sync backend only (fast)
#   ./scripts/local.sh --build    # rebuild the Next.js export first
#
# Why not serve straight from the repo? The repo lives under ~/Desktop, which
# macOS protects with TCC. Apache is not in the Desktop allow-list, so it gets
# EPERM on every file there no matter what the permission bits say. A plain
# home subdirectory is outside TCC, so this sidesteps it without granting a web
# server Full Disk Access.

set -euo pipefail
cd "$(dirname "$0")/.."

ROOT="$PWD"
BASE="$HOME/olisa-local"
WWW="$BASE/public_html"
APP="$BASE/olisa-app"

if [[ "${1:-}" == "--build" ]]; then
    npm run build
fi

mkdir -p "$WWW" "$APP"

# ---- private application directory -------------------------------------
# storage/ holds sessions, logs and the sqlite file: it is generated at run
# time, so it is never overwritten from the repo.
rsync -a --exclude 'storage/' "$ROOT/backend/app/" "$APP/"
rsync -a "$ROOT/shared/" "$APP/shared/"
mkdir -p "$APP/storage"

# ---- docroot ------------------------------------------------------------
if [[ -d "$ROOT/out" ]]; then
    rsync -a --exclude 'api/' --exclude 'admin/' \
        --exclude '_init.php' --exclude '.htaccess' "$ROOT/out/" "$WWW/"
else
    echo "note: ./out does not exist yet — run with --build to include the site."
fi

rsync -a "$ROOT/backend/public/" "$WWW/"

# The shipped .htaccess redirects every request to https. There is no
# certificate on localhost, so that turns the whole site into a redirect loop.
# Strip just those three lines from the served copy; the repo file is untouched.
perl -0pi -e 's{^\s*RewriteCond %\{HTTPS\} !=on\n\s*RewriteCond %\{HTTP:X-Forwarded-Proto\} !https\n\s*RewriteRule \^\(\.\*\)\$ https://%\{HTTP_HOST\}/\$1 \[R=301,L\]\n}{    # (HTTPS redirect removed by scripts/local.sh for local testing)\n}m' "$WWW/.htaccess"

echo "docroot: $WWW"
echo "app:     $APP"
