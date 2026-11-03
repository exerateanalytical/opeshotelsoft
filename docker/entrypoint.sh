#!/bin/sh
# Container entrypoint. ROLE selects what this container does:
#   app        php-fpm (default; runs migrations when RUN_MIGRATIONS=true)
#   worker     queue worker
#   scheduler  Laravel scheduler (schedule:work)
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set; refusing to start." >&2
  exit 1
fi

# Ensure the (volume-mounted) storage tree exists.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/backups

ROLE="${ROLE:-app}"

if [ "$ROLE" = "app" ]; then
  if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
  fi
  php artisan storage:link >/dev/null 2>&1 || true
  # Passport keys come from env (PASSPORT_PRIVATE_KEY/PASSPORT_PUBLIC_KEY) or storage/oauth-*.key.
  php artisan optimize
fi

case "$ROLE" in
  app)       exec "$@" ;;
  worker)    exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=256 ;;
  scheduler) exec php artisan schedule:work ;;
  *)         exec "$@" ;;
esac
