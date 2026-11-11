-- Labels, defined in Labels and assigned to hosts on the host page.
-- host_id is hosts.id in the bmon-collector database (no foreign key across
-- databases: assignments of hosts that no longer exist are simply ignored).

CREATE TABLE IF NOT EXISTS labels (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(64)   NOT NULL,                -- case-insensitive unique (collation)
  description VARCHAR(1000) NOT NULL DEFAULT '',
  created_at  DATETIME(3)   NOT NULL,
  updated_at  DATETIME(3)   NOT NULL,
  UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS host_labels (
  host_id    BIGINT UNSIGNED NOT NULL,
  label_id   INT UNSIGNED    NOT NULL,
  created_at DATETIME(3)     NOT NULL,
  PRIMARY KEY (host_id, label_id),
  KEY idx_label (label_id),
  CONSTRAINT fk_host_labels_label FOREIGN KEY (label_id) REFERENCES labels (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
