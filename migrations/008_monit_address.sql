-- Address used to reach the Monit httpd of a host for actions and the
-- reachability check, when the one Monit reports (often a private IP) is not
-- reachable from Bmon-web. '' / NULL = automatic (reported address).

ALTER TABLE host_meta
  ADD COLUMN monit_address    VARCHAR(255) NOT NULL DEFAULT '' AFTER notes_updated_by,
  ADD COLUMN monit_port       INT UNSIGNED NULL DEFAULT NULL   AFTER monit_address,
  ADD COLUMN addr_updated_at  DATETIME(3)  NULL DEFAULT NULL   AFTER monit_port,
  ADD COLUMN addr_updated_by  VARCHAR(64)  NOT NULL DEFAULT '' AFTER addr_updated_at;
