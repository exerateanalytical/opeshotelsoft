#!/usr/bin/env bash
# Fast local server (docs/PERFORMANCE.md): PHP built-in server with opcache + JIT for this
# process only (no php.ini edits), cached config/routes/events/views and a persistent
# PostgreSQL connection. Usage: bash scripts/serve-fast.sh [port] [--no-cache]
#   PHP=/path/to/php bash scripts/serve-fast.sh 8190
# Code edits are picked up (opcache revalidates every 2 s). After editing routes or config,
# re-run the script (or `php artisan optimize`); `--no-cache` skips the artisan caches.
set -euo pipefail
cd "$(dirname "$0")/.."
PORT="${1:-8190}"
HOST="${HOST:-127.0.0.1}"   # use HOST=0.0.0.0 to reach it from phones on the same network
PHP="${PHP:-php}"

# The caches go to their own files, used only by this server process: a shared
# bootstrap/cache/config.php would make `php artisan test` ignore phpunit.xml's database.
export DB_PERSISTENT="${DB_PERSISTENT:-true}"   # read when the config is cached below
mkdir -p storage/framework/fast
# Relative to the app root (Laravel does not treat C:/... as absolute here).
export APP_CONFIG_CACHE=storage/framework/fast/config.php
export APP_ROUTES_CACHE=storage/framework/fast/routes.php
export APP_EVENTS_CACHE=storage/framework/fast/events.php
if [[ "${2:-}" == "--no-cache" ]]; then
  rm -f storage/framework/fast/*.php
else
  "$PHP" artisan optimize
fi

echo "Serving on http://$HOST:$PORT (opcache + JIT, cached config/routes/events, DB_PERSISTENT=$DB_PERSISTENT)"
# Laravel's router script resolves files from the working directory, so serve from public/.
cd public
exec "$PHP" \
  -d opcache.enable=1 -d opcache.enable_cli=1 \
  -d opcache.memory_consumption=256 -d opcache.interned_strings_buffer=32 -d opcache.max_accelerated_files=40000 \
  -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=2 \
  -d opcache.jit=tracing -d opcache.jit_buffer_size=64M \
  -d realpath_cache_size=16M -d realpath_cache_ttl=600 \
  -S "$HOST:$PORT" -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
