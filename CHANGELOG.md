# Changelog

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
