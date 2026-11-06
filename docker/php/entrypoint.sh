#!/bin/sh
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is empty. Generate one: docker compose run --rm app php artisan key:generate --show" >&2
  exit 1
fi

# Wait for the database, then bring the schema up to date.
until php artisan migrate --force --no-interaction 2>/tmp/migrate.log; do
  echo "Waiting for the database..."; tail -n 2 /tmp/migrate.log; sleep 3
done

if [ "$SEED_ON_START" = "true" ]; then
  php artisan db:seed --force --no-interaction
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction

exec "$@"
