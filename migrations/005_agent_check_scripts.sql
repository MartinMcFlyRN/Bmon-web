-- AgentConf 0.8.1: a shell script attached to a check, installed on the agent
-- in <monit_dir>/bmon-scripts/<script_name> (mode 0755) by the zip.

ALTER TABLE agent_checks
  ADD COLUMN script_name VARCHAR(100) NOT NULL DEFAULT '' AFTER config,
  ADD COLUMN script      MEDIUMTEXT   NULL     AFTER script_name;
