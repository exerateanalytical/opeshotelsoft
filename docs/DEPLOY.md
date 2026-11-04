# Deploying OpesHotelSoft

Two supported layouts:

- **A. Docker Compose**: one host, everything in containers (`Dockerfile`, `docker-compose.yml`).
- **B. Bare-metal Linux** (Ubuntu 24.04 / Debian 12): nginx + php8.3-fpm + PostgreSQL 16 + Redis, with systemd or Supervisor units from `deploy/`.

Both use the same `.env` keys. Start from `.env.production.example`.

---

## 0. Before you start

- A DNS name pointing at the server (e.g. `hotel.example.com`).
- 2 vCPU / 4 GB RAM / 40 GB SSD minimum for one hotel group; add disk for backups.
- Outbound SMTP credentials.
- An off-server place for backups (S3-compatible bucket, or another machine that pulls them).
- Decide the secrets and store them in a password manager:
  - `APP_KEY` (`php artisan key:generate --show`)
  - `DB_PASSWORD`, `REDIS_PASSWORD` (`openssl rand -base64 32`)
  - `BACKUP_KEY` (`openssl rand -base64 48`). **Without it, backups cannot be decrypted.** Keep a copy off the server.
  - `OPS_HEALTH_TOKEN` (optional, for detailed health output)

Production rules: `APP_ENV=production`, `APP_DEBUG=false`, `DEMO_LOGINS=false`.

---

## A. Docker Compose

```sh
git clone <repo> /opt/opeshotelsoft && cd /opt/opeshotelsoft
cp .env.production.example .env
# edit .env: APP_KEY, APP_URL, DB_PASSWORD, REDIS_PASSWORD, BACKUP_KEY, MAIL_*
docker compose config -q            # validates the file and the .env substitutions
docker compose build
docker compose up -d
docker compose ps                   # all services "healthy"
docker compose exec app php artisan passport:keys        # once; keys land in the storage volume
docker compose exec app php artisan db:seed --force      # once, first install only
curl -s http://127.0.0.1:8080/health
```

Services: `app` (php-fpm, runs migrations at start when `RUN_MIGRATIONS=true`), `worker` (queue), `scheduler` (`schedule:work`: backups, relay, notifications...), `nginx` (bound to `127.0.0.1:8080`), `postgres` 16, `redis` 7. Volumes: `storage` (uploads, logs, backups, Passport keys), `pgdata`, `redisdata`.

Logs go to stderr as JSON (`LOG_CHANNEL=stderr_json`): `docker compose logs -f app worker scheduler`.

Uploaded files under `storage/app/public` are served by PHP, not by the nginx container (the nginx image only holds `public/`). If you serve many public files, mount the `storage` volume read-only into nginx at `/var/www/html/storage` and keep the `public/storage` symlink.

**TLS**: terminate it in front of the stack. Simplest is Caddy on the host:

```
hotel.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy gets and renews Let's Encrypt certificates on its own and sends `X-Forwarded-Proto`. Make sure Laravel trusts the proxy so URLs are generated as `https://` and the client IP is the real one: set `TRUSTED_PROXIES` (comma-separated IPs or CIDR ranges, read by `config/trustedproxy.php`). Default is none, so `X-Forwarded-For` is ignored. `docker-compose.yml` sets `127.0.0.1,172.16.0.0/12,10.0.0.0/8,192.168.0.0/16` for the app container (the nginx container is only published on `127.0.0.1:8080`); on bare metal with the proxy on the same host use `TRUSTED_PROXIES=127.0.0.1`. Never use `*` when the application port is reachable without the proxy.

`DEMO_LOGINS=true` is refused when `APP_ENV=production` (a warning is logged) unless `ALLOW_DEMO_LOGINS_IN_PRODUCTION=true` is also set.

---

## B. Bare-metal Linux

### B1. Packages

```sh
sudo apt update
sudo apt install -y nginx redis-server supervisor unzip git curl openssl
# PHP 8.3 (Ubuntu 24.04 ships it; on Debian 12 add packages.sury.org)
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-intl php8.3-gd php8.3-zip \
  php8.3-bcmath php8.3-redis php8.3-mbstring php8.3-xml php8.3-curl php8.3-opcache
# PostgreSQL 16 (Ubuntu 24.04 ships it; otherwise use apt.postgresql.org)
sudo apt install -y postgresql-16 postgresql-client-16
# Composer + Node 22 for the asset build
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

### B2. Database and Redis

```sh
sudo -u postgres psql <<'SQL'
CREATE ROLE opes LOGIN PASSWORD 'CHANGE_ME' CREATEDB;   -- CREATEDB is needed by ops:backup:verify
CREATE DATABASE opeshotelsoft OWNER opes;
SQL
sudo sed -i 's/^# *requirepass .*/requirepass CHANGE_ME/' /etc/redis/redis.conf && sudo systemctl restart redis-server
```

### B3. Code

Release layout: `/var/www/opeshotelsoft/releases/<timestamp>`, `current` symlink, shared `storage` and `.env`.

```sh
sudo mkdir -p /var/www/opeshotelsoft/{releases,shared} /var/backups/opeshotelsoft /var/log/opeshotelsoft
sudo chown -R www-data:www-data /var/www/opeshotelsoft /var/backups/opeshotelsoft /var/log/opeshotelsoft
R=/var/www/opeshotelsoft/releases/$(date +%Y%m%d%H%M%S)
sudo -u www-data git clone <repo> $R && cd $R
sudo -u www-data cp .env.production.example ../../shared/.env      # first time only, then edit it
sudo -u www-data ln -sfn /var/www/opeshotelsoft/shared/.env .env
[ -d ../../shared/storage ] || sudo -u www-data mv storage ../../shared/storage
sudo -u www-data rm -rf storage && sudo -u www-data ln -sfn /var/www/opeshotelsoft/shared/storage storage
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build && rm -rf node_modules
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan passport:keys           # first install only
sudo -u www-data php artisan db:seed --force         # first install only
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan optimize                 # config, events, routes, views (see docs/PERFORMANCE.md)
sudo ln -sfn $R /var/www/opeshotelsoft/current
sudo systemctl reload php8.3-fpm
```

Set in `.env`: `BACKUP_PATH=/var/backups/opeshotelsoft`, `LOG_CHANNEL=json` (JSON lines in `storage/logs/laravel.json-YYYY-MM-DD.log`).

### B4. nginx + TLS

```sh
sudo cp deploy/nginx/opeshotelsoft.conf /etc/nginx/sites-available/opeshotelsoft
sudo sed -i 's/hotel.example.com/YOUR.DOMAIN/g' /etc/nginx/sites-available/opeshotelsoft
sudo ln -s /etc/nginx/sites-available/opeshotelsoft /etc/nginx/sites-enabled/
sudo apt install -y certbot python3-certbot-nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d YOUR.DOMAIN   # or: certbot --nginx
sudo nginx -t && sudo systemctl reload nginx
```

Certbot installs a renewal timer; check with `systemctl list-timers | grep certbot`.

### B5. Queue worker and scheduler

Pick **one** of:

systemd:
```sh
sudo cp deploy/systemd/opeshotelsoft-* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now opeshotelsoft-queue.service opeshotelsoft-scheduler.timer
```

Supervisor:
```sh
sudo cp deploy/supervisor/opeshotelsoft.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

After every deploy: `php artisan queue:restart` (workers finish their job and restart on the new code).

---

## Backups

`php artisan ops:backup` does: `pg_dump` (plain SQL, no owner/privileges) → gzip → AES-256-CBC encryption (PBKDF2-SHA256, 100 000 iterations; key = `BACKUP_KEY`, or `APP_KEY` if empty) → `BACKUP_PATH/<db>-<UTC timestamp>.sql.gz.enc` (mode 0600) → optional copy to the `BACKUP_DISK` filesystem disk → deletes local and remote copies older than `BACKUP_RETENTION_DAYS`.

Scheduled in `routes/console.php`: backup daily at `BACKUP_SCHEDULE_AT` (default 01:30), restore drill weekly on Sunday 03:30. The scheduler must be running.

The DB password goes to `pg_dump` through `PGPASSWORD`, never on the command line. `pg_dump` must be version 16 or newer (`PG_BIN_PATH` if it is not on `PATH`).

**Off-site copy (S3, Backblaze B2, Wasabi, MinIO, Cloudflare R2)**: `composer require league/flysystem-aws-s3-v3`, fill `AWS_*` (`AWS_ENDPOINT` for non-AWS), and set `BACKUP_DISK=s3`. Use a bucket with versioning/object lock and a key that can only write to that prefix. Alternatively pull `/var/backups/opeshotelsoft` from another host with `rsync`.

The format is plain OpenSSL, so a backup can be decrypted on any machine without the app:

```sh
openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -iter 100000 -pass env:BACKUP_KEY \
  -in opeshotelsoft-20261103-013000.sql.gz.enc | gunzip > restore.sql
```

### Restore drill (weekly, automated)

`php artisan ops:backup:verify [--file=...] [--keep]` decrypts the latest backup, restores it into `<db>_restore_check`, checks there are tables and migration rows, then drops it. The DB user needs `CREATEDB`. Failures are logged and the scheduled run exits non-zero; watch for it (see Monitoring).

### Full restore (disaster recovery)

1. Stop writes: `php artisan down` (Docker: `docker compose stop worker scheduler`).
2. Get the backup file and `BACKUP_KEY`.
3. Decrypt (command above) to `restore.sql`.
4. Recreate the database:
   ```sh
   sudo -u postgres psql -c "DROP DATABASE opeshotelsoft" -c "CREATE DATABASE opeshotelsoft OWNER opes"
   psql -h 127.0.0.1 -U opes -d opeshotelsoft -v ON_ERROR_STOP=1 -f restore.sql
   ```
   Docker: `docker compose exec -T postgres psql -U opes -d opeshotelsoft -v ON_ERROR_STOP=1 < restore.sql`.
5. `php artisan migrate --force` (if the code is newer than the backup), `php artisan optimize:clear && php artisan optimize`.
6. `php artisan up`, restart worker/scheduler, check `/health`.
7. Delete `restore.sql` (it is unencrypted).

Also back up, separately: the `.env` file (in the password manager), `storage/oauth-*.key`, and `storage/app` (uploads), e.g. nightly `restic`/`rsync`.

---

## Monitoring

- **`GET /health`** (unauthenticated, rate-limited 60/min/IP). Returns `{"status":"ok|warn|fail","checks":{"database","queue","disk","redis"?},"time"}`. HTTP 503 only when status is `fail` (database or Redis down); `warn` (queue backlog above `OPS_HEALTH_QUEUE_BACKLOG_MAX`, failed jobs above `OPS_HEALTH_FAILED_JOBS_MAX`, free disk below `OPS_HEALTH_DISK_MIN_FREE_MB`) still returns 200. No numbers, hostnames or versions unless the request carries `X-Health-Token: $OPS_HEALTH_TOKEN`.
- `/up` is Laravel's liveness probe (app boots).
- Point an uptime checker (UptimeRobot, Uptime Kuma, Better Stack) at `https://YOUR.DOMAIN/health` with keyword `"status":"ok"`; alert on `warn` too.
- Logs: JSON lines (`LOG_CHANNEL=json` or `stderr_json`). Ship with Vector/Promtail/Filebeat. Alert on `level_name` ERROR/CRITICAL and on messages `ops.backup.completed` missing for > 26 h.
- `php artisan queue:failed` lists failed jobs; `php artisan queue:retry all` after fixing the cause.
- Disk: backups and logs grow; retention handles backups, the `json` channel keeps 14 days.

## Rate limits (public booking API)

Defined in `app/Modules/Platform/Ops/OpsBootstrap.php`:
- `public-read` (hotel list, availability): 300/min and 20 000/day per IP.
- `public-write` (book, lookup, cancel, online check-in): 20/min per IP per route, 200/hour per IP, 300/min per hotel group.
- Exceeding returns HTTP 429 with `Retry-After`. Behind a proxy, configure trusted proxies so the client IP is the real one.

## Upgrades

1. Read the release notes; take a manual backup: `php artisan ops:backup`.
2. Docker: `git pull && docker compose build && docker compose up -d` (migrations run on `app` start), then `docker compose exec app php artisan queue:restart`.
3. Bare metal: create a new release directory (B3 steps without the first-install lines), `php artisan migrate --force`, switch the `current` symlink, `systemctl reload php8.3-fpm`, `php artisan queue:restart`.
4. Check `/health` and the logs.
5. Rollback: point `current` back at the previous release (or the previous image tag via `APP_IMAGE_TAG`). Migrations are forward-only; if a migration must be undone, restore the backup from step 1.

PostgreSQL major upgrades (e.g. 16 → 17): take a backup, start the new server, restore the dump into it (Docker: new volume + `postgres:17-alpine`), switch over.
