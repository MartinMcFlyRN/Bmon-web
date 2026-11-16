-- 0.27.0: listening ports of an agent, scanned on request.
CREATE TABLE IF NOT EXISTS agent_ports (
  host_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  requested_at DATETIME(3)  NULL,                    -- scan asked from the agent page
  requested_by VARCHAR(64)  NOT NULL DEFAULT '',
  scanned_at   DATETIME(3)  NULL,                    -- result received from bmon-update.sh
  tool         VARCHAR(16)  NOT NULL DEFAULT '',     -- ss | netstat | none
  output       MEDIUMTEXT   NULL                     -- as sent by the agent, parsed when shown
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
