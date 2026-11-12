-- Settings edited in Admin → Configuration. They override config.php, which
-- keeps the database connection and the deployment/secret settings.

CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(100) NOT NULL PRIMARY KEY,   -- dot notation, e.g. slack.title
  value      TEXT         NOT NULL,               -- JSON
  updated_at DATETIME(3)  NOT NULL,
  updated_by VARCHAR(64)  NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
