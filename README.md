# Bmon-web

Version **0.2.0**. Web interface of Bmon, a replacement for M/Monit. It reads
the data that [Bmon collector](https://github.com/MartinMcFlyRN/Bmon-collector)
stores in MySQL/MariaDB and presents it with a layout inspired by M/Monit.

Stack: **nginx + PHP-FPM (PHP ≥ 8.2) + MariaDB/MySQL**. No framework and no
Composer dependencies: copy the files, configure, run the migrations.

## What it does

- **Sign-in** with username and password
  - passwords hashed with Argon2id (bcrypt if PHP lacks Argon2), rehashed transparently when the algorithm changes
  - login throttling: per IP (20 failures / 15 min) and per username+IP (5 / 15 min), so an attacker cannot lock a known account out from everywhere
  - same response time for existing and non-existing usernames
  - session id regenerated at sign-in, idle timeout (1 h) and absolute timeout (12 h)
  - `HttpOnly`, `SameSite=Lax`, `Secure` session cookie
- **Users** (Admin → Users): create, edit, disable, delete, reset password
  - two roles as in M/Monit: **Administrator** and **User**
  - changing a password, disabling an account or changing its role ends all of that user's sessions immediately
  - an admin cannot delete/disable/demote their own account, and the last active admin cannot be removed
- **My account**: full name, e-mail, password change (ends the other sessions)
- **Audit log** (Admin → Audit log): sign-ins, failures, throttling, user changes
- **Dashboard**: host and service totals read from the collector database, with the lists of offline hosts, hosts with failing services, and failing services (host, service, failing checks, last error message, failing since). Services of offline hosts are counted apart, since their state is stale.
- CSRF token on every form, strict Content-Security-Policy (no inline scripts or styles), only `public/` exposed by nginx
- `bin/bmon-web` CLI: migrations and user management (first admin, password reset)

Coming next: Status (hosts and services), Events, Reports.

## Layout

```
public/          web root: index.php (front controller) and assets/
src/             application code (namespace Bmon\Web)
templates/       plain-PHP templates
migrations/      SQL migrations, applied by bin/bmon-web migrate
config/          config.example.php
deploy/          nginx and PHP-FPM pool examples
bin/bmon-web     command line tool
```

## Database

Bmon-web keeps its own tables (`users`, `login_attempts`, `audit_log`) in a
separate database and only **reads** the collector database. Both must be on the
same MySQL/MariaDB server.

```sql
CREATE DATABASE bmon_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bmon_web'@'localhost' IDENTIFIED BY '...';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON bmon_web.* TO 'bmon_web'@'localhost';
GRANT SELECT ON bmon_collector.* TO 'bmon_web'@'localhost';
```

## Installation (Debian 12/13)

```sh
apt install nginx php-fpm php-mysql php-mbstring

git clone https://github.com/MartinMcFlyRN/Bmon-web /opt/bmon-web
useradd --system --no-create-home --shell /usr/sbin/nologin bmon-web
install -d -o bmon-web -g bmon-web -m 700 /var/lib/bmon-web/sessions
install -d -o bmon-web -g bmon-web -m 750 /var/log/bmon-web

install -d -m 750 -g bmon-web /etc/bmon-web
install -m 640 -g bmon-web /opt/bmon-web/config/config.example.php /etc/bmon-web/config.php
editor /etc/bmon-web/config.php        # database password, timezone

# PHP-FPM pool (adjust the PHP version in the path)
cp /opt/bmon-web/deploy/php-fpm-pool.conf /etc/php/8.4/fpm/pool.d/bmon-web.conf
systemctl reload php8.4-fpm

# nginx vhost (set server_name and certificates)
cp /opt/bmon-web/deploy/nginx.conf /etc/nginx/sites-available/bmon-web
ln -s ../sites-available/bmon-web /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# tables and first administrator
cd /opt/bmon-web
BMON_WEB_CONFIG=/etc/bmon-web/config.php php bin/bmon-web migrate
BMON_WEB_CONFIG=/etc/bmon-web/config.php php bin/bmon-web user:create admin --admin --name="Your Name"
```

The pool runs as `bmon-web`, which only needs read access to `/opt/bmon-web`
and `/etc/bmon-web/config.php`. Publish the interface **over HTTPS only**; to try
it over plain HTTP set `'session' => ['secure' => false]`, otherwise the browser
will not send the session cookie back.

### Upgrading

```sh
cd /opt/bmon-web && git pull
BMON_WEB_CONFIG=/etc/bmon-web/config.php php bin/bmon-web migrate
```

## Configuration

`/etc/bmon-web/config.php` returns a PHP array; keys not set keep their default
(see `src/Config.php`). Lookup order: `$BMON_WEB_CONFIG`, `/etc/bmon-web/config.php`,
`config/config.php`.

| Key | Default | |
|---|---|---|
| `app.name` | `Bmon` | shown in the header and page titles |
| `app.base_path` | `''` | e.g. `/bmon` when published in a subdirectory |
| `app.timezone` | `Europe/Rome` | display timezone; the database stores UTC |
| `app.debug` | `false` | shows stack traces: never in production |
| `db.dsn` / `db.user` / `db.password` | `bmon_web` on localhost | PDO DSN |
| `collector_db` | `bmon_collector` | database of bmon-collector |
| `session.secure` | `true` | cookie over HTTPS only |
| `session.idle_timeout` | `3600` | seconds |
| `session.absolute_timeout` | `43200` | seconds |
| `security.password_min_length` | `10` | |
| `security.login_window` | `900` | seconds |
| `security.login_max_per_ip` | `20` | failed logins per IP in the window |
| `security.login_max_per_user` | `5` | failed logins per username+IP in the window |
| `security.trusted_proxies` | `[]` | CIDRs allowed to set `X-Forwarded-For` / `X-Real-IP` |

## Command line

```
bin/bmon-web migrate                          create or update the tables
bin/bmon-web user:create <username> [--admin] [--name=NAME] [--email=EMAIL]
bin/bmon-web user:passwd <username>           new password, ends the user's sessions
bin/bmon-web user:enable <username>           re-enable a disabled account
bin/bmon-web user:list
bin/bmon-web version
```

The password is asked on the terminal (twice, not echoed) or read from the
first line of stdin, e.g. for provisioning scripts.

If every administrator is locked out: `bin/bmon-web user:passwd admin` and
`bin/bmon-web user:enable admin`. Throttling clears by itself after
`login_window` seconds, or with `DELETE FROM login_attempts`.

## License

To be defined.
