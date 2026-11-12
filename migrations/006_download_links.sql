-- AgentConf: links to download an agent's zip without signing in (e.g. with curl
-- from the agent itself). Only the SHA-256 of the token is stored; links expire.

CREATE TABLE IF NOT EXISTS download_links (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash   CHAR(64)        NOT NULL,              -- hex SHA-256 of the token in the URL
  host_id      BIGINT UNSIGNED NOT NULL,
  created_by   VARCHAR(64)     NOT NULL DEFAULT '',
  created_at   DATETIME(3)     NOT NULL,
  expires_at   DATETIME(3)     NOT NULL,
  revoked_at   DATETIME(3)     NULL DEFAULT NULL,
  uses         INT UNSIGNED    NOT NULL DEFAULT 0,
  last_used_at DATETIME(3)     NULL DEFAULT NULL,
  last_used_ip VARCHAR(45)     NOT NULL DEFAULT '',
  UNIQUE KEY uk_token (token_hash),
  KEY idx_host (host_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
