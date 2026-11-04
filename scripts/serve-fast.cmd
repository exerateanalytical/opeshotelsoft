@echo off
rem Fast local server (docs\PERFORMANCE.md): PHP built-in server with opcache + JIT for this
rem process only (no php.ini edits), cached config/routes/events and a persistent
rem PostgreSQL connection.  Usage: scripts\serve-fast.cmd [port] [--no-cache]
rem Set PHP to the php.exe to use, e.g. set PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
rem Code edits are picked up (opcache revalidates every 2 s). After editing routes or config, re-run this script.
setlocal
cd /d "%~dp0.."
set PORT=%1
if "%PORT%"=="" set PORT=8190
if "%PHP%"=="" set PHP=php
rem The caches go to their own files, used only by this server process: a shared
rem bootstrap\cache\config.php would make "php artisan test" ignore phpunit.xml's database.
if "%DB_PERSISTENT%"=="" set DB_PERSISTENT=true
if not exist storage\framework\fast mkdir storage\framework\fast
rem Relative to the app root (Laravel does not treat C:\... as absolute here).
set APP_CONFIG_CACHE=storage/framework/fast/config.php
set APP_ROUTES_CACHE=storage/framework/fast/routes.php
set APP_EVENTS_CACHE=storage/framework/fast/events.php
if /I "%2"=="--no-cache" (
  del /q storage\framework\fast\*.php 2>nul
) else (
  "%PHP%" artisan optimize
)
rem Laravel's router script resolves files from the working directory, so serve from public\.
cd public
echo Serving on http://127.0.0.1:%PORT% (opcache + JIT, cached config/routes/events, DB_PERSISTENT=%DB_PERSISTENT%)
"%PHP%" -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=256 -d opcache.interned_strings_buffer=32 -d opcache.max_accelerated_files=40000 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=2 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M -d realpath_cache_size=16M -d realpath_cache_ttl=600 -S 127.0.0.1:%PORT% -t . ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
