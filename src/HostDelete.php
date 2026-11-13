<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Removes an offline host with everything about it: in bmon-collector the host
 * row (services, events and service groups follow by ON DELETE CASCADE), in
 * Bmon-web its name/notes/address, inventory, AgentConf checks, published
 * versions, auto-update state and download links. The audit log is kept.
 *
 * Bmon-web needs DELETE on bmon_collector.hosts (it only reads the rest):
 *   GRANT DELETE ON bmon_collector.hosts TO 'bmon_web'@'localhost';
 * If the host reports again later, bmon-collector creates it anew.
 */
final class HostDelete
{
    /** Bmon-web tables with a host_id (agent_check_groups follows agent_checks by cascade). */
    private const WEB_TABLES = ['agent_checks', 'agent_releases', 'agent_update_state', 'download_links', 'host_inventory', 'host_meta'];

    /** Whether Bmon-web may delete rows of bmon_collector.hosts. */
    public static function allowed(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $grants = implode("\n", array_map(static fn ($r) => (string) reset($r), Db::all('SHOW GRANTS FOR CURRENT_USER()')));
            $db = preg_quote(self::collectorDb(), '/');
            $ok = (bool) preg_match('/GRANT (?:ALL PRIVILEGES|[^\n]*\bDELETE\b[^\n]*) ON (?:\*\.\*|`?' . $db . '`?\.(?:\*|`?hosts`?)) TO/i', $grants);
        }
        return $ok;
    }

    public static function grantHint(): string
    {
        return 'GRANT DELETE ON ' . self::collectorDb() . ".hosts TO '" . Config::get('db.user') . "'@'localhost';";
    }

    /**
     * @return array{ok:bool,error?:string,counts?:array<string,int>}
     */
    public static function delete(int $id): array
    {
        $host = Db::host($id);
        if ($host === null) {
            return ['ok' => false, 'error' => 'Host not found.'];
        }
        if ($host['offline_since'] === null) {
            return ['ok' => false, 'error' => 'Only offline hosts can be deleted: this one is still reporting.'];
        }
        $counts = [
            'services' => (int) Db::value('SELECT COUNT(*) FROM ' . Db::collector('services') . ' WHERE host_id = ?', [$id]),
            'events' => (int) Db::value('SELECT COUNT(*) FROM ' . Db::collector('events') . ' WHERE host_id = ?', [$id]),
        ];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach (self::WEB_TABLES as $t) {
                $counts[$t] = Db::exec("DELETE FROM $t WHERE host_id = ?", [$id]);
            }
            // only if still offline: a report that arrived meanwhile wins
            if (Db::exec('DELETE FROM ' . Db::collector('hosts') . ' WHERE id = ? AND offline_since IS NOT NULL', [$id]) !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'The host has just reported again: it was not deleted.'];
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if (($e->errorInfo[1] ?? 0) === 1142) {   // command denied
                return ['ok' => false, 'error' => 'Bmon-web may not delete hosts in the collector database. As a database administrator run: ' . self::grantHint()];
            }
            throw $e;
        }
        return ['ok' => true, 'counts' => $counts, 'host' => $host];
    }

    private static function collectorDb(): string
    {
        // Db::collector('hosts') is "`db`.`hosts`" or "hosts"
        return preg_match('/^`?([^`.]+)`?\./', Db::collector('hosts'), $m) ? $m[1] : (string) Db::value('SELECT DATABASE()');
    }
}
