-- Users, login throttling and audit log. All DATETIME values are UTC.

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(64)  NOT NULL,            -- case-insensitive unique (collation)
  full_name       VARCHAR(255) NOT NULL DEFAULT '',
  email           VARCHAR(255) NOT NULL DEFAULT '',
  password_hash   VARCHAR(255) NOT NULL,            -- password_hash(), Argon2id when available
  role            ENUM('admin','user') NOT NULL DEFAULT 'user',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,  -- bumped to end all sessions of the user
  last_login_at   DATETIME(3)  NULL DEFAULT NULL,
  last_login_ip   VARCHAR(45)  NOT NULL DEFAULT '',
  created_at      DATETIME(3)  NOT NULL,
  updated_at      DATETIME(3)  NOT NULL,
  UNIQUE KEY uk_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(64) NOT NULL,
  ip           VARCHAR(45) NOT NULL,
  success      TINYINT(1)  NOT NULL,
  attempted_at DATETIME(3) NOT NULL,
  KEY idx_ip (ip, attempted_at),
  KEY idx_user_ip (username, ip, attempted_at),
  KEY idx_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED  NULL DEFAULT NULL,       -- actor; NULL for failed logins / CLI
  username   VARCHAR(64)   NOT NULL DEFAULT '',
  action     VARCHAR(64)   NOT NULL,                -- login.success, user.create, ...
  detail     VARCHAR(1024) NOT NULL DEFAULT '',
  ip         VARCHAR(45)   NOT NULL DEFAULT '',
  created_at DATETIME(3)   NOT NULL,
  KEY idx_created (created_at),
  KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
