# Changelog

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
