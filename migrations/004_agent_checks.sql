-- AgentConf: Monit check definitions prepared in Bmon-web for each agent,
-- downloaded as a zip of /etc/monit/conf.d/bmon-<name>.conf files.
-- host_id is hosts.id in the bmon-collector database.

CREATE TABLE IF NOT EXISTS agent_checks (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  host_id    BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(64)     NOT NULL,                 -- file name in the zip: bmon-<name>.conf
  template   VARCHAR(64)     NOT NULL DEFAULT 'custom',
  config     TEXT            NOT NULL,                 -- monitrc snippet
  enabled    TINYINT(1)      NOT NULL DEFAULT 1,       -- disabled checks are left out of the zip
  created_at DATETIME(3)     NOT NULL,
  updated_at DATETIME(3)     NOT NULL,
  updated_by VARCHAR(64)     NOT NULL DEFAULT '',
  UNIQUE KEY uk_host_name (host_id, name),
  KEY idx_host (host_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
