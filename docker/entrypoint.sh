#!/bin/sh
set -e

# Wait for the database to accept connections.
#
# Parameters
#   DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD from the environment.
#   MAX_DB_WAIT caps the number of one-second attempts (default 60).
# What it does
#   Polls the database with PDO until it answers. Bounded, so an unreachable
#   database fails the boot loudly instead of hanging the machine forever in a
#   never-healthy state.
# Output
#   Returns once the database answers; exits 1 past the attempt cap.
MAX_DB_WAIT=${MAX_DB_WAIT:-60}
attempt=0

echo "Waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306}..."
while ! php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "$MAX_DB_WAIT" ]; then
        echo "ERROR: MySQL at ${DB_HOST}:${DB_PORT} is unreachable after ${MAX_DB_WAIT} attempts. Aborting boot." >&2
        exit 1
    fi
    sleep 1
done
echo "MySQL is ready."

# Resolve the application key.
#
# Parameters
#   APP_KEY and APP_ENV from the environment.
# What it does
#   In production a missing key is a hard error: generating a fresh random key
#   on every boot silently invalidates every encrypted payload and signed URL
#   written by the previous machine. Outside production it falls back to an
#   ephemeral key so local runs stay frictionless.
# Output
#   A populated APP_KEY, or exit 1 in production when none was supplied.
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:generated-key-placeholder" ]; then
    if [ "${APP_ENV:-production}" = "production" ]; then
        echo "ERROR: APP_KEY is not set. Generate one with 'php artisan key:generate --show'" >&2
        echo "       and store it as a secret (fly secrets set APP_KEY=...)." >&2
        exit 1
    fi
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    echo "WARNING: APP_KEY was missing; generated an ephemeral key for APP_ENV=${APP_ENV}."
fi

# Generate a runtime .env so Laravel and PHP-FPM have a stable configuration source.
cat > /var/www/html/.env <<EOF
APP_NAME=${APP_NAME:-F1dle}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost:8000}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=${LOG_LEVEL:-warning}

DB_CONNECTION=${DB_CONNECTION:-mysql}
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-f1dle}
DB_USERNAME=${DB_USERNAME:-f1dle}
DB_PASSWORD=${DB_PASSWORD:-f1dle_password}

SESSION_DRIVER=${SESSION_DRIVER:-file}
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=${QUEUE_CONNECTION:-sync}

CACHE_STORE=${CACHE_STORE:-file}
CACHE_PREFIX=

FRONTEND_URL=${FRONTEND_URL:-http://localhost:3000}
EOF

# Apply schema changes. Fast and idempotent, so it is safe on every boot.
php artisan migrate --force

# Data seeding is deliberately NOT run here: 'php artisan app:seed' talks to the
# Jolpica API for 10-15 minutes, which would stall the boot past any reasonable
# health-check deadline. Run it once, out of band, after the first deploy:
#   fly ssh console -a f1dle-api -C "php artisan app:seed"
# See DEPLOY.md.

# Cache config
php artisan config:cache

# Start PHP-FPM and Nginx
php-fpm -D
nginx -g "daemon off;"
