-- 0.20.0: AgentConf Group Checks and Custom Templates.
--
-- The checks of a group are rows of agent_checks with host_id = -<group id>
-- (agents have positive ids), so they share editor, validation and service groups.

ALTER TABLE agent_checks MODIFY host_id BIGINT NOT NULL;

CREATE TABLE IF NOT EXISTS check_groups (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(64)  NOT NULL,
  slug        VARCHAR(32)  NOT NULL,                 -- prefix of its files on the agents: bmon-<slug>.<check>.conf
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME(3)  NOT NULL,
  updated_at  DATETIME(3)  NOT NULL,
  updated_by  VARCHAR(64)  NOT NULL DEFAULT '',
  UNIQUE KEY uk_name (name),
  UNIQUE KEY uk_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS check_group_hosts (
  group_id   INT UNSIGNED    NOT NULL,
  host_id    BIGINT UNSIGNED NOT NULL,                -- hosts.id of bmon-collector
  created_at DATETIME(3)     NOT NULL,
  created_by VARCHAR(64)     NOT NULL DEFAULT '',
  PRIMARY KEY (group_id, host_id),
  KEY idx_host (host_id),
  CONSTRAINT fk_cgh_group FOREIGN KEY (group_id) REFERENCES check_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_templates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  label       VARCHAR(80)  NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  filename    VARCHAR(64)  NOT NULL,                 -- default check name
  fields      TEXT         NOT NULL,                 -- JSON list of {key,label,type,default,required,help}
  body        TEXT         NOT NULL,                 -- Monit configuration with {{field}} and ?field lines
  script_name VARCHAR(100) NOT NULL DEFAULT '',
  script      MEDIUMTEXT   NULL,
  created_at  DATETIME(3)  NOT NULL,
  updated_at  DATETIME(3)  NOT NULL,
  updated_by  VARCHAR(64)  NOT NULL DEFAULT '',
  UNIQUE KEY uk_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
