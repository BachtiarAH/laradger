#!/bin/sh
# Single entrypoint for the app, queue, scheduler and one-off commands.
#
# Reads its configuration from the container environment first and falls back
# to the Laravel .env file, so the same script works for:
#   - development (source bind-mounted, dependencies in a volume)
#   - production  (image built with --no-dev, .env mounted read-only)
set -eu

APP_HOME="${APP_HOME:-/var/www/html}"
cd "$APP_HOME"

log() {
    printf '[entrypoint] %s\n' "$*"
}

# Value of KEY: container environment wins, then .env.
env_get() {
    value=$(printenv "$1" 2>/dev/null || true)
    if [ -z "$value" ] && [ -f .env ]; then
        value=$(sed -n "s/^[[:space:]]*$1=//p" .env | tail -n 1 | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")
    fi
    printf '%s' "$value"
}

APP_ENV="${APP_ENV:-$(env_get APP_ENV)}"
APP_ENV="${APP_ENV:-local}"
is_production() {
    [ "$APP_ENV" = "production" ]
}

if [ ! -f artisan ]; then
    log "FATAL: $APP_HOME/artisan not found; the source tree is missing"
    exit 1
fi

# --- 1. environment file ----------------------------------------------------
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        log "no .env found, copying .env.example"
        cp .env.example .env
    else
        log "no .env and no .env.example available"
    fi
fi

# --- 2. writable directories ------------------------------------------------
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# --- 3. composer dependencies (development only) ----------------------------
if ! is_production; then
    lock_hash=$(md5sum composer.lock | cut -d' ' -f1)
    installed_hash=$(cat vendor/.composer-lock-hash 2>/dev/null || true)
    if [ ! -f vendor/autoload.php ] || [ "$lock_hash" != "$installed_hash" ]; then
        log "installing composer dependencies"
        composer install --no-interaction --no-progress --prefer-dist
        printf '%s\n' "$lock_hash" > vendor/.composer-lock-hash
    fi
fi

# --- 4. application key -----------------------------------------------------
if [ -z "$(env_get APP_KEY)" ]; then
    if is_production; then
        log "FATAL: APP_KEY is empty. Set it in .env before starting the container."
        exit 1
    fi
    log "APP_KEY is empty, generating one"
    php artisan key:generate --force --no-interaction
fi

# --- 5. database ------------------------------------------------------------
DB_CONNECTION="${DB_CONNECTION:-$(env_get DB_CONNECTION)}"
DB_CONNECTION="${DB_CONNECTION:-sqlite}"

wait_for_database() {
    [ "$DB_CONNECTION" = "sqlite" ] && return 0

    DB_HOST="${DB_HOST:-$(env_get DB_HOST)}"
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-$(env_get DB_PORT)}"
    DB_PORT="${DB_PORT:-$([ "$DB_CONNECTION" = "pgsql" ] && echo 5432 || echo 3306)}"

    attempt=1
    while [ "$attempt" -le 40 ]; do
        if DB_CONNECTION="$DB_CONNECTION" DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" \
           DB_USERNAME="${DB_USERNAME:-$(env_get DB_USERNAME)}" \
           DB_PASSWORD="${DB_PASSWORD:-$(env_get DB_PASSWORD)}" \
           php -r '
               $driver = getenv("DB_CONNECTION") === "pgsql" ? "pgsql" : "mysql";
               $dsn = sprintf("%s:host=%s;port=%s", $driver, getenv("DB_HOST"), getenv("DB_PORT"));
               try {
                   new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 3]);
               } catch (Throwable $e) {
                   fwrite(STDERR, $e->getMessage().PHP_EOL);
                   exit(1);
               }
           ' 2>/dev/null; then
            log "$DB_CONNECTION is reachable at $DB_HOST:$DB_PORT"
            return 0
        fi
        log "waiting for $DB_CONNECTION at $DB_HOST:$DB_PORT ($attempt/40)"
        attempt=$((attempt + 1))
        sleep 2
    done

    log "database never became reachable"
    return 1
}

if [ "$DB_CONNECTION" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-$(env_get DB_DATABASE)}"
    DB_FILE="${DB_FILE:-$APP_HOME/database/database.sqlite}"
    case "$DB_FILE" in
        :memory:*)
            ;;
        *)
            mkdir -p "$(dirname "$DB_FILE")"
            if [ ! -f "$DB_FILE" ]; then
                log "creating sqlite database at $DB_FILE"
                touch "$DB_FILE"
            fi
            DB_FILE="$DB_FILE" php -r '
                $pdo = new PDO("sqlite:".getenv("DB_FILE"));
                $pdo->exec("PRAGMA journal_mode=WAL");
                $pdo->exec("PRAGMA busy_timeout=5000");
            ' >/dev/null 2>&1 || log "could not enable WAL on $DB_FILE (continuing)"
            ;;
    esac
else
    wait_for_database
fi

# --- 6. migrations ----------------------------------------------------------
RUN_MIGRATIONS="${RUN_MIGRATIONS:-$(env_get RUN_MIGRATIONS)}"
if [ -z "$RUN_MIGRATIONS" ]; then
    if is_production; then RUN_MIGRATIONS=false; else RUN_MIGRATIONS=true; fi
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    log "running migrations"
    php artisan migrate --force --no-interaction
fi

# --- 7. public storage symlink ---------------------------------------------
if [ ! -L public/storage ] && php artisan storage:link --no-interaction >/dev/null 2>&1; then
    log "linked public/storage"
fi

# --- 8. production caches ---------------------------------------------------
if is_production; then
    log "caching configuration and views"
    php artisan config:cache --no-interaction
    php artisan view:cache --no-interaction
    # routes/web.php contains closures, which route caching cannot serialize.
    php artisan route:cache --no-interaction >/dev/null 2>&1 \
        || log "route cache skipped (closure routes)"
fi

log "starting: $*"

# Development bind mounts are written by the root entrypoint but served by the
# www-data workers, so hand every writable path over right before we exec.
if [ "$(id -u)" = "0" ] && ! is_production; then
    chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true
fi

exec "$@"
