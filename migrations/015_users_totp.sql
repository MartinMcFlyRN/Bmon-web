-- 0.25.0: two-factor authentication (TOTP) for every user.
ALTER TABLE users
  ADD COLUMN totp_secret     VARCHAR(255) NULL AFTER password_hash,          -- base32 seed, sealed (Secrets)
  ADD COLUMN totp_enabled_at DATETIME(3)  NULL AFTER totp_secret,            -- NULL: set up at the next sign-in
  ADD COLUMN totp_last_step  BIGINT       NOT NULL DEFAULT 0 AFTER totp_enabled_at;  -- last accepted 30 s step (no reuse)
