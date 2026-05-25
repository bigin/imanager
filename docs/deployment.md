# Deployment guide

iManager is a library, not an application: so this guide isn't
about "deploying iManager". It's about what your application needs
when it ships **with iManager embedded**: host requirements,
filesystem layout, webserver config, backups, and scheduled
maintenance.

If you're building locally, you don't need any of this: the
[README quickstart](../README.md#quickstart) is enough. Come back
when you're ready to put a host on the public internet.

---

## 1. Host requirements

| Requirement | Why |
|---|---|
| **PHP 8.2+** (8.3 recommended) | Library uses readonly classes, enums, first-class callables, `mixed` parameters. |
| **PHP extensions:** `pdo_sqlite`, `mbstring`, `gd`, `dom`, `json` | `pdo_sqlite` for the storage layer; `gd` for image thumbnails; `mbstring` and `dom` for the Sanitizer / HTMLPurifier; `json` for SQLite JSON1 round-tripping. |
| **SQLite ≥ 3.38** | JSON1 + FTS5 + generated columns. Bundled in every supported PHP 8.x build, but check `php -r 'echo SQLite3::version()["versionString"];'` on exotic hosts. |
| **OPcache enabled** | Library has dozens of small classes that get loaded on every request. OPcache turns startup from "noticeable" into "imperceptible". |

A quick sanity check on the target host:

```bash
php -r 'foreach (["pdo_sqlite","mbstring","gd","dom","json","opcache"] as $e) {
  printf("%-10s %s\n", $e, extension_loaded($e) ? "ok" : "MISSING");
}'
```

---

## 2. Filesystem layout

`DefaultBootstrap::boot()` takes four paths. **The three directory
paths (everything except `uploadsUrl`) must be writable by the
PHP-FPM user**: the library creates files and subdirectories under
them at runtime.

```php
use Imanager\DefaultBootstrap;

$container = DefaultBootstrap::boot(
    databasePath: __DIR__ . '/var/data/imanager.db',
    uploadsPath:  __DIR__ . '/var/uploads',
    uploadsUrl:   '/uploads',
    cachePath:    __DIR__ . '/var/cache',
);
```

| Path | Used for | Writable? |
|---|---|---|
| `databasePath` | The SQLite file itself. The library also creates `<databasePath>-wal` and `<databasePath>-shm` sidecar files (WAL mode is on by default, see [§5](#5-sqlite-at-runtime)). | **The parent directory** must be writable so SQLite can create the sidecars. |
| `uploadsPath` | Item file attachments (`Fileupload` / `Imageupload` fields). Subdirectories are created lazily per upload. | **Yes.** |
| `uploadsUrl` | The public URL prefix used to build asset URLs. **No filesystem use**: the webserver maps it to `uploadsPath`. | n/a |
| `cachePath` | The PSR-16 filesystem cache. Two-level sha256-hashed directory fanout. | **Yes.** |

### Recommended layout

```
app/
├── public/                       # webroot, DocumentRoot points here
│   ├── index.php                 # front controller
│   └── uploads/  -> ../var/uploads  (symlink, or alias in webserver)
├── src/                          # your application code
├── vendor/                       # composer
├── bin/
│   └── imanager  -> ../vendor/bin/imanager  (convenience symlink)
└── var/
    ├── data/
    │   ├── imanager.db
    │   ├── imanager.db-wal       # auto-created (WAL mode)
    │   └── imanager.db-shm       # auto-created (WAL mode)
    ├── uploads/                  # served at /uploads
    └── cache/                    # never served
```

Three rules:

1. **`var/data/` MUST NOT be webroot-accessible.** Don't put it
   under `public/`. The webserver should never see `.db`, `-wal`,
   or `-shm` files.
2. **`var/cache/` MUST NOT be webroot-accessible** either:
   cached fragments are application internals.
3. **`var/uploads/` IS public.** Either symlink it into `public/`
   or use a webserver alias (see [§3](#3-webserver-configuration)).

### Permissions

Default modes when the library creates files / dirs are `0644` /
`0755` (configurable via `Config::$chmodFile` and
`Config::$chmodDir`). On a production host with PHP-FPM running as
its own user (`www-data` or similar), the typical setup is:

```bash
chown -R www-data:www-data var/
chmod -R u=rwX,go= var/data var/cache    # FPM-only
chmod -R u=rwX,go=rX var/uploads         # FPM-writable, world-readable
```

The exact mode depends on whether `www-data` is also the user that
runs deployments. Adjust the group bits accordingly.

---

## 3. Webserver configuration

iManager doesn't ship a router or a front controller: those are
your application's concern. What the webserver needs to know:

- Route everything **except** `/uploads/*` and static assets to
  `index.php` (front controller pattern).
- Map `/uploads/*` to `var/uploads/` directly (no PHP).
- Refuse all access to `var/data/` and `var/cache/` (no PHP, no
  static).

### Caddy

```caddyfile
example.com {
    root * /srv/app/public
    encode zstd gzip

    # Public uploads served straight off disk.
    @uploads path /uploads/*
    handle @uploads {
        root * /srv/app/var
        file_server
    }

    # Everything else: front controller.
    php_fastcgi unix//run/php/php-fpm.sock
    file_server

    # Defence-in-depth: never serve from var/data or var/cache,
    # even if a symlink or misconfiguration leaks them in.
    @internal path /var/data/* /var/cache/* /.env
    respond @internal 404
}
```

Caddy's `php_fastcgi` directive already routes anything that
doesn't match a static file through `index.php`, you usually
don't need a separate try_files clause.

### nginx + PHP-FPM

```nginx
server {
    server_name example.com;
    root /srv/app/public;
    index index.php;

    # Public uploads, served by nginx, no PHP.
    # `must-revalidate` (not `immutable`): iManager's FileStorage
    # writes to `var/uploads/<itemId>/<fieldId>/<original-filename>`,
    # so re-uploading a file under the same name leaves the URL
    # stable while the bytes change. `must-revalidate` makes the
    # browser send an If-None-Match for every hit; nginx's built-in
    # ETag answers 304 when the file on disk is unchanged (cheap,
    # no transfer) or 200 with the new bytes when it changed.
    # `immutable` would skip that revalidation and serve a stale
    # asset for up to 30 days.
    location /uploads/ {
        alias /srv/app/var/uploads/;
        try_files $uri =404;
        expires 30d;
        add_header Cache-Control "public, must-revalidate";
    }

    # Defence-in-depth: never serve data/ or cache/.
    location ~ ^/(var/data|var/cache|\.env)(/|$) {
        deny all;
        return 404;
    }

    # Front controller.
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $realpath_root;
        internal;
    }

    # Block direct execution of any other .php file.
    location ~ \.php$ {
        return 404;
    }
}
```

The `internal` directive on the PHP location matters: without it,
clients can hit `/index.php/anything` directly and bypass the front
controller's URL rewriting.

### PHP-FPM tuning

A pool config that works well for a small-to-medium iManager-backed
site:

```ini
; /etc/php/8.3/fpm/pool.d/imanager.conf
[imanager]
user  = www-data
group = www-data
listen = /run/php/php-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children      = 20
pm.start_servers     = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests      = 500

; Allow long migration scripts when invoked over the CLI is not relevant here,
; but the front controller should never need more than a few seconds.
request_terminate_timeout = 30s

php_admin_value[opcache.enable]                  = 1
php_admin_value[opcache.memory_consumption]      = 128
php_admin_value[opcache.max_accelerated_files]   = 10000
php_admin_value[opcache.validate_timestamps]     = 0     ; production: invalidate on deploy
php_admin_value[opcache.revalidate_freq]         = 0
php_admin_value[opcache.fast_shutdown]           = 1

php_admin_value[realpath_cache_size] = 4M
php_admin_value[realpath_cache_ttl]  = 600
```

`opcache.validate_timestamps = 0` is the big production switch: it
turns off per-request file-modification checks. The tradeoff is
that you have to clear opcache on deploy (`cachetool` or a small
PHP-FPM reload). For staging or hosts that deploy via plain
`git pull`, `validate_timestamps = 1` and `revalidate_freq = 2` is
the safer default.

---

## 4. Production Dockerfile

The Dockerfile bundled in this repo is dev-only (CLI image,
interactive, no Composer install). Below is a starter for
**production**: PHP 8.3 FPM + Composer install + the right
extensions, single-stage so it's easy to follow. Adapt as needed.

```dockerfile
# syntax=docker/dockerfile:1.6
FROM php:8.3-fpm-alpine AS runtime

# Build deps stripped from the final image after composer install
# would shrink it further; one-stage is fine for a starter.
RUN set -eux; \
    apk add --no-cache \
        bash sqlite sqlite-dev \
        oniguruma-dev libzip-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
        libxml2-dev icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_sqlite mbstring gd dom zip opcache

# Production opcache settings.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'realpath_cache_size=4M'; \
        echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/imanager-production.ini

WORKDIR /app

# Bring in composer just for the build step.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install dependencies first (better layer caching).
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev --no-interaction --no-progress \
        --prefer-dist --optimize-autoloader --classmap-authoritative

# Copy the rest of the application.
COPY . .

# Apply schema migrations during build. SQLite file goes into the
# image so the container boots ready. If your host persists the DB
# in a volume, drop this line and run `vendor/bin/imanager schema:migrate`
# on first boot instead.
RUN mkdir -p var/data var/uploads var/cache \
    && vendor/bin/imanager schema:migrate --db=var/data/imanager.db

# The web layer is whatever you bolt on (nginx in a separate
# service, or a Caddy sidecar). FPM listens on the standard 9000.
EXPOSE 9000
CMD ["php-fpm", "--nodaemonize"]
```

Pair it with a `docker-compose.yml` that has nginx (or Caddy) as a
sibling service, sharing `/app/public` and `/app/var/uploads` as
read-only volumes for the webserver. The persistent volumes are
`/app/var/data` (the SQLite file) and `/app/var/uploads` (the
attachments). Those are the only two things your backup strategy
needs to know about.

---

## 5. SQLite at runtime

The library opens the database with these PRAGMAs already set, on
every connect:

| PRAGMA | Value | Why |
|---|---|---|
| `foreign_keys` | `ON` | FK constraints on `categories`, `fields`, `items`, `files`. |
| `journal_mode` | `WAL` | Multiple readers + one writer concurrently; survives `kill -9` cleanly. |
| `synchronous` | `NORMAL` | WAL-safe; gives up only a small durability window on power loss. Most production setups want this; switch to `FULL` only if you're running on hardware that lies about fsync. |
| `temp_store` | `MEMORY` | Temp tables / sort buffers live in RAM. Modest win for query-heavy workloads. |

You don't need to set these yourself: the library does it on
connect.

### WAL files

WAL mode produces two sidecar files: `<db>-wal` and `<db>-shm`.
**Both must live in the same directory as the database file, and
the directory must be writable by FPM.** Containers / volumes /
NFS mounts that mark the database file writable but the directory
read-only are a common cause of "database is locked" errors at
runtime: check the directory mode first.

The WAL file can grow during traffic spikes; SQLite checkpoints it
back into the main DB automatically (every 1000 pages by default).
A persistent large `-wal` usually means your VACUUM cadence is too
aggressive and is racing with active writes: back off.

### Concurrency model

WAL gives you N readers + 1 writer concurrently. For an iManager
workload (CMS-shaped: lots of reads, occasional writes from editor
saves), that's plenty. If you genuinely outgrow single-writer
SQLite, the answer isn't a different SQLite tuning: it's a
different storage backend, and the `Storage` interface is where to
swap it.

---

## 6. Backups

You back up **two things**:

1. The SQLite database (`var/data/imanager.db` + its `-wal` + `-shm`).
2. The uploads directory (`var/uploads/`).

The cache directory is not backed up: it regenerates on demand.

### Online SQLite backup (recommended)

`sqlite3 .backup` is WAL-safe: it produces a consistent snapshot
without locking out writers. Schedule it as a cron job:

```bash
# /etc/cron.daily/imanager-backup
set -euo pipefail

DB=/srv/app/var/data/imanager.db
DEST=/srv/backups/$(date +%F)
mkdir -p "$DEST"

# Atomic .backup: writers can keep going while this runs.
sqlite3 "$DB" ".backup '$DEST/imanager.db'"

# Uploads: incremental rsync.
rsync -a --delete /srv/app/var/uploads/ "$DEST/uploads/"

# Compress yesterday's backup; keep last 30 days.
find /srv/backups -mindepth 1 -maxdepth 1 -type d -mtime +0 \
    -exec tar -czf '{}.tar.gz' '{}' \; -exec rm -rf '{}' \;
find /srv/backups -name '*.tar.gz' -mtime +30 -delete
```

What this gives you:
- **Daily snapshots.** Each is a full, consistent copy.
- **No write downtime.** `.backup` doesn't lock the writer.
- **30 days of history**, compressed after the day rolls over.

For point-in-time recovery beyond 24 h, look into [Litestream](https://litestream.io/):
it streams WAL changes to S3 (or any S3-compatible bucket)
continuously. The trade-off is one more daemon on the box; the
upside is recovery to any point within the retention window.

### What NOT to do

- **Do not `cp imanager.db backup.db`** with the database under
  active writes. You'll capture the main file but not the WAL;
  the backup is corrupt as far as SQLite is concerned.
- **Do not back up `-wal` and `-shm` separately** and try to
  reassemble. The triple is consistent only as captured by a
  proper `.backup` or `VACUUM INTO`.
- **Do not back up `var/cache/`.** It's regeneratable and the
  hash-keyed fanout makes incremental backups expensive.

### Restore drill

Test restore at least once before you need it:

```bash
# Stop the app.
systemctl stop php-fpm

# Restore.
rm -f /srv/app/var/data/imanager.db*
cp /srv/backups/2026-05-13/imanager.db /srv/app/var/data/imanager.db
rsync -a --delete /srv/backups/2026-05-13/uploads/ /srv/app/var/uploads/
chown -R www-data:www-data /srv/app/var

# Bring it back up.
systemctl start php-fpm

# Sanity check.
sudo -u www-data /srv/app/vendor/bin/imanager schema:status \
    --db=/srv/app/var/data/imanager.db
```

If `schema:status` reports the expected version with no pending
migrations, the restore worked.

---

## 7. Scheduled maintenance

iManager ships a CLI (`vendor/bin/imanager`) with operational
commands. The ones you schedule:

| Command | What it does | Cadence |
|---|---|---|
| `optimize --db=<db>` | Runs `PRAGMA optimize`. Updates SQLite's query planner stats and prunes redundant index information. Cheap; safe on a live DB. | **Weekly.** |
| `optimize --db=<db> --vacuum` | The above, plus `VACUUM`. Rewrites the database file to reclaim space and reduce fragmentation. **Locks the DB while it runs** (minutes for a ~1 GB file). | **Quarterly,** during a maintenance window. Not weekly. |
| `repair --db=<db>` | Runs `PRAGMA integrity_check` and `PRAGMA foreign_key_check`. Reports rows that don't satisfy constraints. **Read-only.** | **Monthly.** Run as part of your backup verification. |
| `fts:rebuild --db=<db>` | Drops and rebuilds the FTS5 index from `items`. Required after bulk inserts that bypassed the repository, or after changing the tokenizer config. | **On demand.** Not scheduled: the FTS index is kept in sync incrementally on every save. |

A reasonable systemd-timer-driven setup:

```ini
; /etc/systemd/system/imanager-maintenance.service
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/srv/app
ExecStart=/srv/app/vendor/bin/imanager optimize --db=var/data/imanager.db
```

```ini
; /etc/systemd/system/imanager-maintenance.timer
[Timer]
OnCalendar=weekly
Persistent=true

[Install]
WantedBy=timers.target
```

The `Persistent=true` matters: if the host is off when the timer
fires, it runs at next boot rather than skipping.

### Why no `repair --fix`

`repair` reports issues but never mutates. The fixes for
constraint violations are application-specific (which orphan
row gets reattached? deleted? merged?). That's a human call, not
a CLI call. If `repair` ever shows non-empty output on your
production DB, treat it like any other monitoring alert.

---

## 8. Logging

The library wires a PSR-3 `Psr\Log\LoggerInterface` into the
container by default, pointed at `Psr\Log\NullLogger`. **The
library itself does not emit log calls** today; this is purely a
surface for host code that wants logging facilities.

To plug in a real logger (Monolog, here as an example), register
your own factory **after** `DefaultBootstrap::boot()` returns:

```php
use Imanager\DefaultBootstrap;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

$container = DefaultBootstrap::boot(/* … */);

$container->addShared(LoggerInterface::class, static function (): LoggerInterface {
    $logger = new Logger('app');
    $logger->pushHandler(new StreamHandler('/srv/app/var/log/app.log', Logger::INFO));
    return $logger;
});
```

`league/container` lets the later registration win, so this
replaces the `NullLogger` for everything that resolves
`LoggerInterface` afterwards. Inject it into your application code
the same way you'd inject any other service.

### Should I log domain events?

If you want a permanent audit trail of "who edited what", subscribe
to the domain events (`ItemUpdated`, etc.) and log from there. The
events are dispatched **after** the SQLite transaction commits: a
log call that throws does not roll back the write, so it's safe to
log without a `try`/`catch`. See
[API > Domain > Domain events](api/domain.md#domain-events) for the
subscription shape.

---

## 9. Production checklist

Before flipping DNS:

- [ ] `php -r 'foreach (...) { extension_loaded() }'` confirms
  `pdo_sqlite`, `mbstring`, `gd`, `dom`, `json`, `opcache` are
  loaded.
- [ ] `var/data/`, `var/uploads/`, `var/cache/` exist, owned by
  the FPM user, and **none of them** is reachable from the
  webroot.
- [ ] Webserver config blocks direct access to `.db`, `-wal`,
  `-shm`, `var/data/*`, `var/cache/*`, and `.env`.
- [ ] `vendor/bin/imanager schema:status --db=…` shows no pending
  migrations.
- [ ] `vendor/bin/imanager repair --db=…` shows clean output.
- [ ] OPcache is enabled and `validate_timestamps = 0` (and you
  have a deploy step that clears opcache).
- [ ] The daily backup cron job is installed and you've done at
  least one successful restore drill on a non-prod host.
- [ ] The `optimize` timer is installed and has fired at least
  once.
- [ ] PSR-3 logger is wired to a real backend; you've confirmed
  logs reach it from application code.
- [ ] HTTPS is on, HSTS is set, and the redirect from
  `http://` is unconditional.

---

## 10. Where to look in the source

- `src/Storage/Sqlite/ConnectionFactory.php`, the four PRAGMAs
  the library issues on every connect.
- `src/Cache/FilesystemCache.php`, the two-level hash fanout
  layout used under `cachePath`.
- `src/Files/LocalFileStorage.php`, atomic-write pattern for
  uploads (tmp + rename), and URL construction from
  `uploadsUrl`.
- `src/Search/FullTextSearch.php`, FTS rebuild SQL.
- `src/Cli/Application.php`, every CLI command and its options.
- `config/schema/*.sql`, the migration files applied on first
  PDO resolve.
- `Dockerfile`, `docker-compose.yml`, the bundled **dev**
  environment; production examples in §4 above.
