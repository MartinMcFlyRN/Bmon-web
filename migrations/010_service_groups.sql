-- Service groups defined in Admin → Service groups, and the groups of each
-- AgentConf check. The "group <name>" lines are added to the check files when
-- the zip is built (and for monit -t), not stored in the editable text, so
-- renaming or deleting a group updates every check that uses it.

CREATE TABLE IF NOT EXISTS service_group_defs (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(64)   NOT NULL,               -- Monit group name
  description VARCHAR(1000) NOT NULL DEFAULT '',
  created_at  DATETIME(3)   NOT NULL,
  updated_at  DATETIME(3)   NOT NULL,
  UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_check_groups (
  check_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (check_id, group_id),
  KEY idx_group (group_id),
  CONSTRAINT fk_acg_check FOREIGN KEY (check_id) REFERENCES agent_checks (id) ON DELETE CASCADE,
  CONSTRAINT fk_acg_group FOREIGN KEY (group_id) REFERENCES service_group_defs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
