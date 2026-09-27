#!/usr/bin/env bash
#
# Pull-based deploy for dokumendiregistrid.karlerss.com. Run as root on the
# server:
#
#   /var/www/dokumendiregistrid/deploy/deploy.sh          # deploy origin/main if it moved
#   /var/www/dokumendiregistrid/deploy/deploy.sh --force  # run every step even if nothing changed
#
# Fast-forwards the checkout to origin/main as the app user, then runs only
# the steps the changed files call for: composer install when composer.lock
# changed, npm ci + vite build when package-lock.json changed, migrations
# when database/migrations changed. Caches are always cleared, php-fpm is
# reloaded and the re-check daemon restarted (it finishes its current probe
# on SIGTERM). The old and new commits are printed so a deploy is auditable
# from the terminal scrollback.
#
# Override with environment variables: APP_DIR, APP_USER, BRANCH, DAEMON, QUEUE, FPM.

set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/dokumendiregistrid}
APP_USER=${APP_USER:-www-data}
BRANCH=${BRANCH:-main}
DAEMON=${DAEMON:-docregistries-recheck}
QUEUE=${QUEUE:-docregistries-queue}
FPM=${FPM:-php8.2-fpm}

force=0
for arg in "$@"; do
    case "$arg" in
        --force) force=1 ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

cd "$APP_DIR"

# Every command that touches the checkout runs as the app user so file
# ownership stays consistent for php-fpm and the daemon.
as_app() { sudo -u "$APP_USER" -H "$@"; }

log() { printf '\033[1m==> %s\033[0m\n' "$*"; }

old=$(as_app git rev-parse HEAD)
as_app git fetch --quiet origin "$BRANCH"
new=$(as_app git rev-parse "origin/$BRANCH")

if [[ "$old" == "$new" && $force -eq 0 ]]; then
    echo "Already at $(git -c safe.directory="$APP_DIR" log --oneline -1 "$old"). Nothing to deploy (use --force to re-run the steps)."
    exit 0
fi

if [[ -n "$(as_app git status --porcelain --untracked-files=no)" ]]; then
    log "Tracked files modified in $APP_DIR; refusing to deploy on top of them:"
    as_app git status --short --untracked-files=no
    exit 1
fi

if [[ "$old" != "$new" ]]; then
    log "Updating $old -> $new"
    as_app git log --oneline "$old..$new"
    as_app git merge --ff-only --quiet "origin/$BRANCH"
    changed=$(as_app git diff --name-only "$old" "$new")
else
    log "Re-running every step at $new"
    changed=$(as_app git ls-files)
fi

if grep -qx 'composer.lock' <<<"$changed"; then
    log "composer.lock changed: installing PHP dependencies"
    as_app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi

if grep -qx 'package-lock.json' <<<"$changed"; then
    log "package-lock.json changed: building front-end assets"
    as_app npm ci --no-audit --no-fund
    as_app npm run build
fi

if grep -q '^database/migrations/' <<<"$changed"; then
    log "Migrations changed: migrating"
    as_app php artisan migrate --force
fi

log "Clearing caches"
as_app php artisan config:clear --quiet
as_app php artisan route:clear --quiet
as_app php artisan view:clear --quiet
as_app php artisan event:clear --quiet 2>/dev/null || true

log "Reloading $FPM"
systemctl reload "$FPM"

for svc in "$DAEMON" "$QUEUE"; do
    if ! systemctl cat "$svc" >/dev/null 2>&1; then
        log "$svc is not installed; skipping (see deploy/$svc.service)"
        continue
    fi
    log "Restarting $svc"
    systemctl restart "$svc"
    sleep 2
    if ! systemctl is-active --quiet "$svc"; then
        echo "$svc is not running after restart:" >&2
        systemctl status "$svc" --no-pager | head -15 >&2
        exit 1
    fi
done

log "Deployed $(as_app git log --oneline -1)"
