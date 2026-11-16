-- 0.26.0: "Remember this computer" after the two-factor code.
CREATE TABLE IF NOT EXISTS trusted_devices (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64)     NOT NULL,            -- sha256 of the cookie value (the value itself is never stored)
  user_sv      INT UNSIGNED NOT NULL,            -- users.session_version when remembered: a password change, a
                                                 -- two-factor reset or disabling the account forgets the device
  created_at   DATETIME(3)  NOT NULL,
  last_used_at DATETIME(3)  NULL,
  expires_at   DATETIME(3)  NOT NULL,
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  last_ip      VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',
  UNIQUE KEY uk_token (token_hash),
  KEY idx_user (user_id),
  CONSTRAINT fk_td_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
