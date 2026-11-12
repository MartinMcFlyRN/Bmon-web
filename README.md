# Bmon-web

Version **0.13.0**. License: [AGPL-3.0](LICENSE). Web interface of Bmon, a replacement for M/Monit. It reads
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

- **Status**: list of all hosts with state (OK / N failing / offline), CPU, memory, load, failing/total services and last report
  - filters All / OK / Failing / Offline with counts, search by host name or address, sortable columns, 100 hosts per page
- **Host page** (click a host): host and Monit details, every service with its state (failing checks decoded from the Monit status, not monitored, initializing, pending action) and a summary per type: load/CPU/memory/swap for the system, space and inodes for filesystems, PID/uptime/CPU/memory for processes, response time for remote host ports and ping, size and modification time for files, exit status and output for programs, link and traffic for network interfaces; "All values" shows every metric Monit sent. Plus service groups and the last 30 events. The services table can be sorted by status, name, type, "since" and last check.
- **Auto-refresh** (off, 5 s, 10 s, 30 s, 1 min) on Dashboard, Status and host pages, remembered per browser; it pauses while the tab is hidden, you are typing or an "All values" panel is open. The sort order is kept across refreshes.

- **Host name and notes** (host page, administrators): the name sent by Monit can be replaced by a name set in Bmon, used everywhere in Bmon-web (lists, search, sorting, dashboard, AgentConf, Slack report) while the page still shows the name in Monit; empty it to go back to Monit's name. Monit's reports never overwrite it. A **Notes** box keeps free text about the host (customer, contacts, procedures…), visible to every user.
- **Inventory**: define inventory items (name and description) in the Inventory menu, edit or delete them; each item page lists the hosts that carry it. On a host's status page, the Inventory box under the Host box shows its items and lets administrators add (from the defined items) or remove them. Deleting an item removes it from every host. Everyone can see the inventory; only administrators can change it.
- **Service actions** (administrators): on the host page every service has Start, Stop, Restart and Monitor/Unmonitor, sent to the Monit agent of the host like M/Monit does. See [Service actions](#service-actions).
- **Slack report** from the command line: `bin/bmon-web report:slack` posts the failing services of this moment to a Slack channel. See [Slack report](#slack-report).
- **AgentConf** (administrators): prepare the Monit checks of each agent from 27 templates, edit them, check them with `monit -t` and download them as a zip for `/etc/monit/conf.d/`. See [AgentConf](#agentconf).

Coming next: Reports.

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

Bmon-web keeps its own tables (`users`, `login_attempts`, `audit_log`, `inventory`, `host_inventory`, `agent_checks`, `download_links`, `host_meta`, `settings`, `service_group_defs`, `agent_check_groups`) in a
separate database and only **reads** the collector database. Both must be on the
same MySQL/MariaDB server.

```sql
CREATE DATABASE bmon_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bmon_web'@'localhost' IDENTIFIED BY '...';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
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

`migrate` applies only what is missing and can be re-run safely. Read the
CHANGELOG for version-specific notes (e.g. 0.6.0 needs the `DROP` privilege).

## Service actions

Bmon-web sends the action to Monit's httpd on the host
(`POST /_doaction`, the same request as `monit restart nginx`), using the address,
port, SSL flag and credentials that the host puts in its reports and that
**bmon-collector ≥ 0.5.0** stores. Monit accepts the action at once and runs it in
the background; the result shows up with the next report (the service shows
"pending: …" meanwhile, and the action appears in Recent events).

Requirements on each host (details in the Bmon-collector README):

- `set mmonit` without `and register without credentials`;
- `set httpd` listening on an address the Bmon-web server can reach, with
  `allow <Bmon-web server address>`;
- the host must be online in Bmon (actions are disabled for offline hosts).

Bmon-web connects to the httpd address Monit reports, or to the address the
reports come from when httpd listens on all interfaces or on loopback. When that
is not reachable — typically a private IP while Bmon-web must use the public one
— set the address (and, if a NAT changes it, the port) with **Edit address**
under "Service monit" in the Monit box of the host page. It is used for the
actions and for the reachability dot; the reported address stays visible as
"httpd (reported)". Empty = automatic again.

If bmon-collector encrypts the passwords (`BMON_CREDENTIALS_KEY`), put the same
key in `monit.credentials_key`. Every action, successful or not, is written to the
audit log. When something is missing, the host page says what (no httpd, no
credentials, key mismatch, Monit unreachable, credentials refused, address not
in the `allow` list).

The Monit box of the host page has a **Service monit** row: green dot when
Monit httpd answers `GET /_ping` and accepts the stored credentials, red when it
is unreachable, refuses the credentials or does not allow this server (the
reason is in the tooltip). The check runs in the background after the page has
loaded, with a 4-second timeout, so an unreachable host does not slow the page.

## Slack report

`bin/bmon-web report:slack` posts to a Slack channel the active alerts of that
moment, one numbered line each, like M/Monit's summary:

```
Active alerts summary:
1) db1.example.it: failed protocol test [MYSQL] at [localhost]:3306 [TCP/IP] -- MYSQL: Access denied
2) mail.example.it: process is not running
3) old.example.it: No report from Monit: the network, the host or Monit is down. Last report was 10 Nov 2026 14:49:59 +0100
```

A line is a failing service of an online host (the message of its last failure
event, as Monit wrote it) or an offline host. Lines are sorted by host name;
host names link to their page in Bmon when `app.url` is set. The title and the
"no alerts" text are configurable (`slack.title`, `slack.no_alerts`), e.g.
`'title' => 'Riepilogo Allarmi Attivi:'`.

1. In Slack, create an app with an **Incoming Webhook** for the channel
   (<https://api.slack.com/messaging/webhooks>) and copy its URL.
2. In the config:

   ```php
   'app'   => ['url' => 'https://bmon.example.com'],   // host names become links
   'slack' => [
       'webhook_url' => 'https://hooks.slack.com/services/T…/B…/…',
       'title'       => 'Active alerts summary:',
   ],
   ```
3. Try it: `bin/bmon-web report:slack --dry-run` prints the message without
   sending it; without `--dry-run` it is posted.

Options:

| | |
|---|---|
| `--webhook=URL` | use this webhook instead of `slack.webhook_url` (e.g. another channel) |
| `--only-if-failing` | send nothing when no service is failing and no host is offline |
| `--no-offline` | leave out the offline hosts |
| `--dry-run` | print the Slack payload (JSON) instead of sending it |

Exit status 0 when sent (or nothing to send with `--only-if-failing`), 1 on
error, with the reason on stderr; the webhook URL is never printed. For
periodic reports use cron, as the user that can read the config:

```
# every morning at 8:00, and every 15 minutes only if something is failing
0 8 * * *     bmon-web  BMON_WEB_CONFIG=/etc/bmon-web/config.php php /opt/bmon-web/bin/bmon-web report:slack
*/15 * * * *  bmon-web  BMON_WEB_CONFIG=/etc/bmon-web/config.php php /opt/bmon-web/bin/bmon-web report:slack --only-if-failing
```

Very long lists: Slack truncates messages over 40000 characters, so the list
stops before that with "…and N more alerts" (a few hundred lines).

## AgentConf

The **AgentConf** menu (administrators only) prepares Monit configurations for
the agents, to install on each node with all its checks ready.

- **Agents**: the list shows the active agents (those sending reports; a button
  shows the offline ones too), with the services they report and the checks
  prepared in Bmon.
- **Agent page**: the checks prepared in Bmon (one file each), and the checks
  currently on the agent as Monit reports them, marked *managed* when a Bmon
  file defines them. For those not managed, **Create check** opens the closest
  template with the same name.
- **Add check**: a gallery of templates based on the Monit manual, with the
  paths of Debian/Ubuntu packages and systemd units: system resources,
  OS information, filesystem; nginx, Apache, MariaDB/MySQL, PHP-FPM, OpenSSH, Postfix, Dovecot,
  Redis, Memcached, cron, any service by PID file or command line; HTTPS website
  with certificate expiry, HTTP page, TCP port, ping; file integrity, freshness,
  size and content, directory freshness; custom script, network interface;
  "Reporting to Bmon" (`set mmonit` + `set httpd` for actions); custom. A
  template asks for a few values (checked, so they cannot break the syntax) and
  produces the configuration. The check is a **copy**: from then on it is plain
  text, edited freely for that service only — templates are never linked to the
  checks made from them, so no change elsewhere ever alters an existing check.
  Checks can be renamed, disabled or deleted.
- **OS information**: Monit reports only the kernel ("Linux 6.12…"). The
  "OS information" template (System category) adds a `check program` with an
  attached script, `bmon-osinfo.sh`, that reads `/etc/os-release` (parsed, never
  executed; fallbacks for lsb_release, redhat/debian/alpine release files,
  macOS and BSD) and prints one line, `BMON_OS pretty="Debian GNU/Linux 12
  (bookworm)" id="debian" version="12" …`. It always exits 0, so it never
  alerts. Bmon-web reads that line from the reports and shows the OS on the
  host page and in the Status list instead of the kernel. The value appears
  after about two runs of the check (Monit reads a program's result at the
  following run).
- **Service groups**: Monit groups (`group <name>`) are defined in Admin →
  Service groups and chosen with checkboxes when adding or editing a check. They
  are not written in the editable text: the zip (and `monit -t`) adds a
  `group <name>` line after the check statement, so renaming or deleting a group
  updates every check that uses it. The Admin page also lists the groups the
  agents already report but that are not defined in Bmon, with an "Add" button.
- **Attached script**: each check can carry one script (file name + text), for
  example the program run by a "Custom script" check. The zip installs it in
  the script directory, `agentconf.script_dir` (default
  `<monit_dir>/bmon-scripts/`), with mode 0755. It must not be the Monit include
  directory itself: Monit would read the scripts as configuration. Shell
  scripts are checked with `sh -n` / `bash -n` when saved, Windows line endings
  are converted, and Bmon warns when a check uses a file of the script
  directory that no check attaches. The zip carries the list of the scripts it
  installs (`.bmon-files`), so the next install removes exactly those files and
  never anything else in that directory.
- **Validation**: when `monit` is installed on the Bmon-web server, every save
  runs `monit -t` on the agent's enabled checks together, so syntax errors and
  duplicate service names are caught before the download (with "Save anyway"
  for syntax that only a newer Monit on the agents understands). Programs and
  scripts exist on the agents, not on the Bmon-web server, so for the check the
  path of a `check program` is replaced by a stub, and "Program does not exist"
  warnings are ignored. `include` is not allowed in a check.
- **Download zip**: `bmon-<name>.conf` for each enabled check, the attached
  scripts in `bmon-scripts/`, and a `README.txt` with the commands to install it:

  ```sh
  # checks (deleted checks go away)
  rm -f /etc/monit/conf.d/bmon-*.conf
  unzip -o FILE.zip -x README.txt 'bmon-scripts/*' -d /etc/monit/conf.d/
  chmod 600 /etc/monit/conf.d/bmon-*.conf
  # scripts (only those a previous zip installed are removed)
  mkdir -p SCRIPT_DIR
  [ -f SCRIPT_DIR/.bmon-files ] && (cd SCRIPT_DIR && xargs -r rm -f < .bmon-files)
  unzip -o -j FILE.zip 'bmon-scripts/*' -d SCRIPT_DIR/
  monit -t && monit reload
  ```

  The README inside the zip has these commands with the real directories.
- **Download link for the servers**: the Download button opens a dialog with
  "Download zip now" (to your computer) and **Create link**: a URL that works
  without signing in, to download the zip directly on the agent, shown together
  with the commands to paste there (curl + install):

  ```
  https://bmon.example.com/dl/<43 random characters>
  ```

  The token has 256 random bits and only its SHA-256 is stored, so the link is
  shown once, when it is created. It expires after `agentconf.link_ttl_hours`
  (24); creating a new link disables the previous one, and it can be revoked.
  The zip is built at download time, with the current checks. Every download
  is in the audit log with its IP address. Expired, revoked or unknown links
  answer `410`/`404` with a plain-text message. Links are built on `app.url`,
  which must be reachable from the agents; note that the token is also written
  in the web server's access log.

  If a check has the same name as a service defined in another file on the
  host, Monit refuses the configuration ("Service name conflict"): remove the
  old definition when you move a check to Bmon.

To enable the validation, install Monit on the Bmon-web server without running
it (`apt install monit && systemctl disable --now monit`); PHP-FPM must allow
`proc_open` (it is enabled by default). Set `agentconf.monit_binary` if it is
not in the PATH, or to `'none'` to disable the checks.

## Configuration

Most settings are edited in **Admin → Configuration** (administrators): name,
public URL, time zone, sessions and sign-in limits, trusted proxies, Monit
actions, Slack report, AgentConf. Each field shows where its value comes from —
*set here*, *from config.php* or *default* — and can be reset. Precedence:
value saved in Admin → Configuration > `config.php` > default; a value equal to
the `config.php` one is not stored, so it keeps following the file. The Slack
webhook is a secret: it is never shown again, only whether it is set.

`/etc/bmon-web/config.php` (PHP array; lookup: `$BMON_WEB_CONFIG`,
`/etc/bmon-web/config.php`, `config/config.php`) keeps what must not be changed
from the browser:

| Key | Default | |
|---|---|---|
| `db.dsn` / `db.user` / `db.password` | `bmon_web` on localhost | PDO DSN of the Bmon-web database |
| `collector_db` | `bmon_collector` | database of bmon-collector |
| `app.base_path` | `''` | e.g. `/bmon` when published in a subdirectory |
| `app.debug` | `false` | shows stack traces: never in production |
| `monit.credentials_key` | `''` | same value as `BMON_CREDENTIALS_KEY` of bmon-collector (a key must not be stored next to what it protects) |
| `agentconf.monit_binary` | `''` | monit used for `monit -t` (`''` = search PATH, `'none'` = no validation) — a program run by the server |

The keys of Admin → Configuration can also be set in `config.php`, as initial
values (see `config/config.example.php`); the CLI (`report:slack`) uses the same
values as the web interface.

## Command line

```
bin/bmon-web migrate                          create or update the tables
bin/bmon-web user:create <username> [--admin] [--name=NAME] [--email=EMAIL]
bin/bmon-web user:passwd <username>           new password, ends the user's sessions
bin/bmon-web user:enable <username>           re-enable a disabled account
bin/bmon-web user:list
bin/bmon-web report:slack [--webhook=URL] [--only-if-failing] [--no-offline] [--dry-run]
bin/bmon-web version
```

The password is asked on the terminal (twice, not echoed) or read from the
first line of stdin, e.g. for provisioning scripts.

If every administrator is locked out: `bin/bmon-web user:passwd admin` and
`bin/bmon-web user:enable admin`. Throttling clears by itself after
`login_window` seconds, or with `DELETE FROM login_attempts`.

## License

Bmon-web is free software, released under the **GNU Affero General Public
License, version 3** (AGPL-3.0). The full text is in [LICENSE](LICENSE), from
<https://www.gnu.org/licenses/agpl-3.0.txt>.

In short: you may use, study, modify and redistribute it. If you distribute it,
or let other people use a modified version over a network, you must make the
corresponding source code available to them under the same license.
