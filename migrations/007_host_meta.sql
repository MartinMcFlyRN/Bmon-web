-- Data kept by Bmon-web about each host (hosts.id of the collector database):
-- a display name that replaces the name sent by Monit, and free notes.

CREATE TABLE IF NOT EXISTS host_meta (
  host_id          BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  display_name     VARCHAR(255)    NOT NULL DEFAULT '',    -- '' = the name Monit sends
  notes            TEXT            NULL,
  name_updated_at  DATETIME(3)     NULL DEFAULT NULL,
  name_updated_by  VARCHAR(64)     NOT NULL DEFAULT '',
  notes_updated_at DATETIME(3)     NULL DEFAULT NULL,
  notes_updated_by VARCHAR(64)     NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
