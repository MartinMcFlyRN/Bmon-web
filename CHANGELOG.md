# Changelog

## 0.21.1 — 2026-11-13

- *Add group checks* adds the whole group (all its checks, enabled or disabled as in the group) with one button, all or nothing: if any of its checks is already on the agent (same name or script), nothing is added and the clashes are listed. The button is always available, so a group can be added again after its checks were deleted from the agent

## 0.21.0 — 2026-11-13

**Run `bin/bmon-web migrate` after upgrading**: it turns the group assignments made with 0.20.0 into copies (so the agents keep their checks) and drops `check_group_hosts`.

- Group Checks are no longer linked to the agents: on the agent page, **Add group checks** (next to *Add check*) copies the chosen checks of a group into the agent at once, as independent checks (same names, scripts and service groups; checks whose name or script the agent already has are skipped). Changing or deleting a group no longer affects the agents
- Group pages: no agents section and no file prefix any more

## 0.20.0 — 2026-11-13

**Run `bin/bmon-web migrate` after upgrading** (tables `check_groups`, `check_group_hosts`, `custom_templates`; `agent_checks.host_id` becomes signed).

- AgentConf menu is a dropdown: **Manage** (the previous AgentConf page), **Group Checks**, **Custom Templates**
- **Custom Templates**: templates with fields (key, label, type, default, required, help), a configuration with `{{key}}` / `?key` lines and an optional script; preview with the default values checked by `monit -t`; they appear in *Add check* under “Custom templates”
- **Group Checks**: sets of checks assigned to several agents; checks added and edited like those of an agent (templates, custom templates, scripts, service groups); included in the zip and publications of their agents as `bmon-<prefix>.<check>.conf`, validated together with the agent's checks, and flagged as *Changes not published* on the agents when the group changes; assign them from the group or from the agent page; clashing script names refuse download and Publish
- The agent list counts the checks coming from groups; deleting a host removes its group assignments

## 0.19.1 — 2026-11-13

- AgentConf agent page: the Auto-update status (published version, version on the agent, last install and its output, refused requests) refreshes by itself every 5 seconds while the page is visible, so after Publish / Update now you see when the agent has finished without reloading

## 0.19.0 — 2026-11-13

No migrations.

- AgentConf required checks: **Reporting to Bmon**, **Bmon auto-update** and **OS information** must be present and enabled. They are added automatically to an agent without checks (Reporting to Bmon with the httpd user, password, port and HTTPS the agent reports); when one is missing or disabled the agent page says so, and the zip download, download links (new and existing) and Publish are refused until *Add the required checks* puts it back

## 0.18.0 — 2026-11-13

No migrations. **To delete hosts, grant:** `GRANT DELETE ON bmon_collector.hosts TO 'bmon_web'@'localhost';`

- *Delete host* on the page of an offline host (administrators, confirmed by typing the host name): removes the host from the collector database (services, events and service groups follow by cascade) and its Bmon-web data — name, notes, Monit address, inventory, AgentConf checks with their service groups, published versions, auto-update state, download links — in one transaction; refused if the host reports meanwhile. Logged in the audit log. Without the grant, the dialog and the error show the GRANT to run
- AgentConf list: offline agents are shown by default (marked *Offline*), so their zip can be downloaded and installed by hand; *Hide offline agents* hides them

## 0.17.0 — 2026-11-13

- Admin → Configuration → AgentConf: *Monit binary on the agents* (default `monit`, from the PATH). The install commands of the zip README and of the download links use it with the full monitrc path (`/usr/local/bin/monit -c /etc/monit/monitrc -t && … reload`, plus the restart commands for when `set httpd` changes), and the "Bmon auto-update" template passes it to `bmon-update.sh` (new `--monit` option) for `monit -t`, reload and restart. Existing auto-update checks are copies: add `--monit /path/to/monit` to both their commands, or create them again

## 0.16.2 — 2026-11-13

- Validation flags `every N cycles` (refused by newer Monit) in any check, with the file, the line and the cron-style replacement, even when the monit of the Bmon-web server is older or missing; publishing is refused until it is changed

## 0.16.1 — 2026-11-13

- Templates "Bmon auto-update", "OS information" and "Custom script": the interval is now in minutes and written as `every "0,5,10,… * * * *"` instead of `every N cycles`, which newer Monit versions reject as deprecated (the explicit list, rather than `*/N`, also works with Monit 5.33 and older). Checks already created are copies: change their `every` line by hand
- Validation: the stub of `check program` paths could swallow the quotes of the next line (e.g. `every "…"`), giving a false syntax error

## 0.16.0 — 2026-11-13

**Run `bin/bmon-web migrate` after upgrading** (new tables `agent_releases`, `agent_update_state`).

- AgentConf auto-update: **Publish** freezes the enabled checks of an agent as the version it installs; the new template **Bmon auto-update** (Monit settings) adds `bmon-update.sh`, which downloads the published version, installs it, checks it with `monit -t` (restoring the previous files on error) and reloads Monit, or restarts it when `set httpd` changed
- Requests are signed with HMAC-SHA256 keyed with the Monit httpd password read from the installed Bmon configuration (accepted if it matches the reported password or the one in the AgentConf checks), with a 5-minute timestamp window, replay guard and, by default, the address of the reports (*Auto-update only from the address of the reports* in Admin → Configuration); the zip is signed back and its SHA-256 checked
- **Update now**: runs the auto-update check at once through Monit httpd ("start")
- Agent page: published version, version on the agent, last install with its output, last refused request; agent list: Auto-update column (sortable)
- The include and script directories are now used only by Bmon: the manual install commands and the auto-update empty them; Admin → Configuration refuses system directories and the monitrc one for them
- Publishing is refused when `monit -t` fails or nothing changed, and warns when the auto-update check or the httpd password is missing

## 0.15.0 — 2026-11-13

No migrations.

- Admin → Configuration → AgentConf: *Collector user* and *Collector password* (secret), put into the collector URL of the "Reporting to Bmon" template (replacing any user:password already in the URL), so new checks come with the right `set mmonit`
- *Monit directory on the agents* (default `/etc/monit`): where monitrc and monit.pem are; the template's HTTPS certificate defaults to `<directory>/monit.pem` (was `/etc/monit/ssl/monit.pem`) and the zip README names `<directory>/monitrc`
- AgentConf agent list: search (name, address, OS, Monit version) and sortable columns

## 0.14.0 — 2026-11-13

No migrations.

- HTTPS to Monit httpd with your own CA: in Admin → Configuration → Monit actions, *CA certificate* (PEM, shown with subject, expiry and fingerprint) — with *Verify the certificate of Monit httpd* on, only certificates issued by that CA are accepted, not the public CAs of the server
- *Check the name in the certificate*: the certificate must contain the address Bmon-web connects to (on by default)
- *Require HTTPS to Monit httpd*: no actions or reachability checks toward hosts whose Monit httpd is still plain HTTP
- Clear reasons when the connection fails: certificate not from the CA or expired, address not in the certificate, port not speaking HTTPS, host not on HTTPS
- AgentConf "Reporting to Bmon": optional *HTTPS certificate (pemfile)* adds `with ssl { pemfile: … }` to `set httpd`; `monit -t` validation uses a stub for SSL files, which exist only on the agents
- README: how to create the CA and the host certificates

## 0.13.1 — 2026-11-12

- Host page layout: Host, Monit and Inventory + Notes side by side on wide screens (three columns), Host and Monit with Inventory and Notes below on medium screens, one column on phones; tighter card padding, smaller empty Notes field, so the services table starts much higher
- Wider page container (1440 px)
- Phones: event and compact tables keep a readable message column and scroll horizontally instead of growing very tall

## 0.13.0 — 2026-11-12

No migrations.

- AgentConf: new template **OS information** (System), a `check program` with an attached script `bmon-osinfo.sh` (shipped in `resources/agentconf/`) that reports the distribution and version from `/etc/os-release` as a `BMON_OS …` line; it never alerts
- Templates can now ship a script, attached automatically when the check is created
- The OS reported by that check is shown on the host page ("OS", with codename) and in the Status list instead of the kernel; the service summary shows "OS" and "Arch"
- OS icon next to it: the distribution's logo from `public/assets/os/<id>.svg|png|webp` when the administrator provides it (no logos shipped: they are trademarks), otherwise a neutral badge with the initials

## 0.12.0 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new tables `service_group_defs`, `agent_check_groups`).

- New **Admin → Service groups**: create, edit (rename, description) and delete Monit service groups; for each group the AgentConf checks using it and the services Monit reports in it; groups reported by the agents but not defined yet can be added with one click
- AgentConf: service groups chosen with checkboxes when adding a check and on its edit page, shown in the agent's check list
- The `group <name>` lines are added to the files of the zip (and to the `monit -t` check) after the check statement, not stored in the text: renaming or deleting a group updates every check using it
- Audit log entries for groups and group changes of checks

## 0.11.0 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new table `settings`). Nothing to change in `config.php`: its values become the initial values of the new page.

- The Admin menu is now a drop-down: **Users**, **Configuration**, **Audit log** (the Admin sub-navigation bar is gone)
- New **Admin → Configuration** page with the settings of `config.php` except the database connection and the deployment/secret ones (`collector_db`, `app.base_path`, `app.debug`, `monit.credentials_key`, `agentconf.monit_binary`): general, sign-in and sessions, Monit actions, Slack report, AgentConf. Each field is validated and shows its source (set here / config.php / default) with a reset; the Slack webhook is write-only. Changes in the audit log
- Precedence: saved setting > `config.php` > default; the CLI applies the same settings
- `config/config.example.php` reduced to what belongs in the file

## 0.10.1 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new columns in `host_meta`).

- Host page, Monit box: "Edit address" under Service monit sets the address (IP or host name) and optionally the port used to reach Monit httpd, e.g. the public IP instead of the private one Monit reports; used for actions and the reachability check. The reported address stays visible ("httpd (reported)", "Reports from"); empty = automatic
- Audit log entry for each change; administrators only

## 0.10.0 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new table `host_meta`).

- Host page: the host name can be edited (administrators). The name set in Bmon replaces the one sent by Monit everywhere in Bmon-web — Status list, search (both names), sorting, dashboard, Inventory, AgentConf, Slack report — and is never overwritten by the reports; the page also shows the name in Monit. Empty restores Monit's name
- Host page: **Notes** box under Inventory, free text (max 10000 characters), editable by administrators and visible to every user, with who/when
- Renames and notes changes in the audit log
- AgentConf keeps matching `$HOST` with the name Monit uses

## 0.9.0 — 2026-11-12

**Run `bin/bmon-web migrate` after upgrading** (new table `download_links`). Set `app.url` to the address the agents use to reach Bmon-web.

- AgentConf: the Download button opens a dialog with "Download zip now" and a **download link for the servers**: `/dl/<token>`, usable without signing in (e.g. curl on the agent), shown with ready-to-paste download + install commands
- Tokens: 256 random bits, only the SHA-256 stored (the link is shown once), valid `agentconf.link_ttl_hours` (default 24), a new link revokes the previous one, "Revoke link" button; the dialog shows when the active link was created, when it expires and when it was last used
- `/dl/<token>` runs without session or cookies, answers plain text for expired/revoked/unknown links (410/404), is not cached nor indexed; every download is in the audit log with the IP address
- Router: `{token}` placeholder; routes with access `public`

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
