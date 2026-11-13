-- 0.22.0: Agent → Installer: packages to set up Monit on new servers.

CREATE TABLE IF NOT EXISTS agent_packages (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(64)   NOT NULL,
  base_dir         VARCHAR(200)  NOT NULL,              -- where the zip is extracted, e.g. /usr/local/monit
  monit_bin        MEDIUMBLOB    NOT NULL,              -- bin/monit
  monit_bin_name   VARCHAR(200)  NOT NULL DEFAULT '',   -- name of the uploaded file
  monit_bin_sha256 CHAR(64)      NOT NULL,
  monit_bin_info   VARCHAR(100)  NOT NULL DEFAULT '',   -- e.g. "ELF 64-bit x86-64"
  pem              TEXT          NOT NULL,              -- conf/monit.pem (key + certificate), encrypted
  pem_info         VARCHAR(255)  NOT NULL DEFAULT '',   -- subject and expiry of the certificate
  httpd_user       VARCHAR(32)   NOT NULL,
  httpd_password   VARCHAR(255)  NOT NULL,              -- encrypted
  httpd_port       INT UNSIGNED  NOT NULL DEFAULT 2812,
  token            CHAR(64)      NOT NULL,              -- of the permanent download link /pkg/{token}
  downloads        INT UNSIGNED  NOT NULL DEFAULT 0,
  last_download_at DATETIME(3)   NULL,
  last_download_ip VARCHAR(45)   NOT NULL DEFAULT '',
  created_at       DATETIME(3)   NOT NULL,
  updated_at       DATETIME(3)   NOT NULL,
  updated_by       VARCHAR(64)   NOT NULL DEFAULT '',
  UNIQUE KEY uk_name (name),
  UNIQUE KEY uk_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
