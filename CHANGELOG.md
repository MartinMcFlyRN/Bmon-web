# Changelog

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
