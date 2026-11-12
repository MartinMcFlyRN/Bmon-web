# Changelog

## 0.8.2 — 2026-11-12

No migrations.

- AgentConf: the directory of the attached scripts on the agents is configurable, `agentconf.script_dir` (default `<monit_dir>/bmon-scripts`); invalid values (relative path, `..`, the Monit include directory itself) are reported on the AgentConf pages and the default is used
- The zip lists the scripts it installs (`bmon-scripts/.bmon-files`) and its README removes only those files before installing the new ones, so the script directory can be shared with other files; scripts and checks are installed by separate `unzip` commands
- Warning (agent page and on save) when an enabled check uses a file of the script directory that no enabled check attaches: Monit on the agent would refuse it

Changing `script_dir`: existing checks keep the path they were written with (they are copies), so update the path in their configuration. Scripts installed by 0.8.1 in `<monit_dir>/bmon-scripts/` can be removed from the agents once.

## 0.8.1 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new columns `agent_checks.script_name`, `agent_checks.script`).

- AgentConf: each check can have an attached script (file name + text), installed by the zip in `<monit_dir>/bmon-scripts/` with mode 0755; shell scripts checked with `sh -n` / `bash -n`, CRLF converted, file names unique per agent
- Zip layout changed: files at the root (`bmon-<name>.conf`, `bmon-scripts/…`, `README.txt`), installed with `unzip -o … -x README.txt -d /etc/monit/conf.d/` (see the README in the zip)
- Validation: `check program` paths are replaced by a stub for `monit -t` (the programs exist on the agents, not on the Bmon-web server), and "Program does not exist" warnings are ignored — before, a "Custom script" check whose script was not on the Bmon-web server could not be saved
- The UI states that a check is a copy of its template ("Created from"): editing a check never affects other checks
- New config key `agentconf.monit_dir`

## 0.8.0 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new table `agent_checks`). Optional: install `monit` on the Bmon-web server (not running) to validate the checks.

- New **AgentConf** menu (administrators), between Reports and Admin
- Agent list (active agents, optionally offline ones) with reported services and prepared checks
- Agent page: checks prepared in Bmon, and the checks currently on the agent from the reports, with "Create check" for those not managed yet
- 27 check templates based on the Monit manual (system, filesystem, common services, remote checks, files, programs, network, Monit settings for Bmon, custom); each one verified with `monit -t` 5.33
- Checks editable after creation (file name, enabled, configuration), validated with `monit -t` together with the agent's other enabled checks; `include` not allowed
- Download of a zip with `conf.d/bmon-<name>.conf` files and a README with the install commands; no php-zip extension needed
- Config section `agentconf` (`monit_binary`, `collector_url`, `web_address`); audit log entries for every change and download

## 0.7.1 — 2026-11-12

- `report:slack`: new compact format, one numbered line per alert (`N) host: message`), like M/Monit's summary; offline hosts are lines of the same list ("No report from Monit … Last report was …"), everything sorted by host name
- New config keys `slack.title` (default `Active alerts summary:`) and `slack.no_alerts`

## 0.7.0 — 2026-11-12

No migrations.

- New CLI command `report:slack`: posts the failing services of this moment to a Slack channel through an Incoming Webhook (header with totals, offline hosts, one section per host with failing checks, duration and last error message); options `--webhook`, `--only-if-failing`, `--no-offline`, `--dry-run`
- New config keys `app.url` (links in the report) and `slack.webhook_url`, `slack.proxy`, `slack.timeout`
- Dashboard and Slack report share the same queries (`Overview`)

## 0.6.0 — 2026-11-12

**Upgrade:** the database user needs the `DROP` privilege (RENAME TABLE requires it), then run the migration:

```sql
GRANT DROP ON bmon_web.* TO 'bmon_web'@'localhost';
```
```sh
BMON_WEB_CONFIG=/etc/bmon-web/config.php php bin/bmon-web migrate
```

- Renamed: the Labels menu is now **Inventory**; its entries are "items". URLs `/labels…` → `/inventory…` (old URLs redirect)
- Renamed: tables `labels` → `inventory`, `host_labels` → `host_inventory` (column `label_id` → `item_id`); existing items and host assignments are kept
- Renamed: on the host page the Labels box is now the Inventory box
- Audit log actions `label.*` / `host.label_*` are now `inventory.*` / `host.inventory_*` (older entries keep their names)
- Migrations can now be PHP files; 003 checks the current schema at each step, so it resumes if interrupted
- `bin/bmon-web migrate` explains which GRANT is needed when a privilege is missing

## 0.5.1 — 2026-11-12

- Host page, Monit box: "Service monit" row with a green/red dot showing whether Monit httpd (port 2812) answers and accepts the credentials; checked in the background (`GET /status/{id}/monit-check`, 4 s timeout), reason in the tooltip

## 0.5.0 — 2026-11-12

Requires **bmon-collector 0.5.0** (it stores the Monit httpd credentials). No migrations.
If the collector has `BMON_CREDENTIALS_KEY`, set the same value in `monit.credentials_key`.

- Host page: Action column (administrators) with Start, Stop, Restart, Monitor / Unmonitor for every service, sent to the host's Monit httpd like M/Monit
- Clear messages when an action cannot be sent (httpd disabled, no credentials, wrong key, unreachable, 401, 403, Monit errors) and a note on the page when actions are not available
- Actions disabled for offline hosts; every action is written to the audit log
- New config section `monit` (`credentials_key`, `tls_verify`, `timeout`)

## 0.4.0 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new tables `labels`, `host_labels`).

- Labels menu (replaces the Events placeholder): list with description and host count, create, edit, delete
- Label page: lists the hosts that carry the label
- Host page: Labels box under the Host box, to add labels chosen from the list and remove them
- Label changes and assignments are written to the audit log; only administrators can change labels
- Router: routes can have several numeric parameters

## 0.3.1 — 2026-11-11

- Host page: services table sortable by Status, Service, Type, Since and Checked (click the column header; click again to reverse)
- Auto-refresh: selectable interval (off, 5 s, 10 s, 30 s, 1 min) instead of the fixed 30 s; the 0.3.0 setting carries over as 30 s

## 0.3.0 — 2026-11-11

- Status page: all hosts with state, CPU, memory, load, services and last report; filters with counts, search, sortable columns, pagination (100 per page)
- Host page: host and Monit details, services with decoded state and a per-type summary, all raw values, service groups, last 30 events
- Auto-refresh toggle (30 s) on Dashboard, Status and host pages
- Dashboard: host names link to the host page

## 0.2.0 — 2026-11-11

- Dashboard: list of offline hosts (offline for, last report) under the Hosts box
- Dashboard: list of hosts with at least one failing service (failing / total)
- Dashboard: list of failing services under the Services box, with host name, service type, failing checks decoded from the Monit status bitmask, last error message and time since the status changed
- Service totals now cover online hosts only; services of offline hosts are shown apart
- Lists are capped at 100 rows ("and N more")

## 0.1.0 — 2026-11-11

First release: authentication and user management.

- Sign-in with Argon2id password hashes, login throttling per IP and per username+IP, constant-time response for unknown users
- Sessions with idle and absolute timeout; changing password, role or disabling an account ends that user's sessions
- Admin → Users: create, edit, disable, delete, reset password; roles Administrator and User; safeguards for self-edit and the last admin
- My account: details and password change
- Admin → Audit log
- Dashboard with host/service totals from the bmon-collector database
- CSRF protection, Content-Security-Policy and security headers
- `bin/bmon-web` CLI (migrate, user:create, user:passwd, user:enable, user:list)
- nginx and PHP-FPM pool examples
