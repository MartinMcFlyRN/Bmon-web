-- AgentConf auto-update: the configuration published for each agent (the exact
-- zip it downloads) and what each agent last said about it.

CREATE TABLE IF NOT EXISTS agent_releases (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  host_id     BIGINT UNSIGNED NOT NULL,               -- hosts.id of bmon-collector
  version     CHAR(64)      NOT NULL,               -- sha256 of the zip
  fingerprint CHAR(64)      NOT NULL,               -- AgentChecks::fingerprint() at publish time
  zip         MEDIUMBLOB    NOT NULL,
  size        INT UNSIGNED  NOT NULL,
  checks      INT UNSIGNED  NOT NULL,               -- number of enabled checks in it
  created_by  VARCHAR(64)   NOT NULL,
  created_at  DATETIME(3)   NOT NULL,
  KEY idx_host (host_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_update_state (
  host_id           BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  last_poll_at      DATETIME(3)   NULL,
  last_poll_ip      VARCHAR(45)   NOT NULL DEFAULT '',
  last_ts           BIGINT        NOT NULL DEFAULT 0,   -- timestamp of the last signed request (replay guard)
  installed_version CHAR(64)      NOT NULL DEFAULT '',  -- as reported by the agent
  last_status       VARCHAR(16)   NOT NULL DEFAULT '',  -- installed | rolled_back | failed
  last_message      TEXT          NULL,
  last_result_at    DATETIME(3)   NULL,
  last_auth_error   VARCHAR(255)  NOT NULL DEFAULT '',
  last_auth_error_at DATETIME(3)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
