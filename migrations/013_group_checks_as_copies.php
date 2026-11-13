<?php

declare(strict_types=1);

// 0.21.0: Group Checks are no longer linked to the agents. "Add group checks"
// copies their checks into an agent, as independent checks.
// Every assignment made with 0.20.0 becomes such copies now (same name as in the
// group; a check whose name or script the agent already has is skipped), so the
// agents keep the checks they had; then check_group_hosts is dropped.
// Re-runnable: it does nothing once the table is gone.

return static function (PDO $pdo): void {
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'check_group_hosts'");
    if ((int) $st->fetchColumn() === 0) {
        return;
    }
    $assignments = $pdo->query('SELECT group_id, host_id, created_by FROM check_group_hosts')->fetchAll(PDO::FETCH_ASSOC);
    $checks = $pdo->prepare('SELECT * FROM agent_checks WHERE host_id = ? AND enabled = 1');
    $exists = $pdo->prepare("SELECT COUNT(*) FROM agent_checks WHERE host_id = ? AND (name = ? OR (script_name <> '' AND script_name = ?))");
    $insert = $pdo->prepare(
        'INSERT INTO agent_checks (host_id, name, template, config, script_name, script, enabled, created_at, updated_at, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(3), NOW(3), ?)'
    );
    $groups = $pdo->prepare('INSERT IGNORE INTO agent_check_groups (check_id, group_id) SELECT ?, group_id FROM agent_check_groups WHERE check_id = ?');
    foreach ($assignments as $a) {
        $checks->execute([-(int) $a['group_id']]);
        foreach ($checks->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $exists->execute([(int) $a['host_id'], $c['name'], $c['script_name']]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }
            $insert->execute([(int) $a['host_id'], $c['name'], $c['template'], $c['config'], $c['script_name'], $c['script'],
                'migration (group ' . $a['group_id'] . ')']);
            $groups->execute([(int) $pdo->lastInsertId(), (int) $c['id']]);
        }
    }
    $pdo->exec('DROP TABLE check_group_hosts');
};
