<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Db;
use Bmon\Web\View;

final class DashboardController
{
    /** Maximum rows per list; the rest is summarised as "and N more". */
    private const LIST_LIMIT = 100;

    public function index(): string
    {
        $vars = [
            'title' => 'Dashboard',
            'nav' => 'dashboard',
            'stats' => null,
            'offlineHosts' => [],
            'failingHosts' => [],
            'failingServices' => [],
            'limit' => self::LIST_LIMIT,
            'collectorError' => null,
        ];
        try {
            $hosts = Db::collector('hosts');
            $services = Db::collector('services');
            $events = Db::collector('events');

            // Services of offline hosts are stale: they are counted apart, not as OK or failing.
            $vars['stats'] = Db::one(
                "SELECT COUNT(*) AS hosts,
                        COALESCE(SUM(offline_since IS NOT NULL), 0) AS hosts_offline,
                        COALESCE(SUM(offline_since IS NULL AND services_failed > 0), 0) AS hosts_failing,
                        COALESCE(SUM(IF(offline_since IS NULL, services_total, 0)), 0) AS services,
                        COALESCE(SUM(IF(offline_since IS NULL, services_failed, 0)), 0) AS services_failed,
                        COALESCE(SUM(IF(offline_since IS NOT NULL, services_total, 0)), 0) AS services_offline
                   FROM $hosts"
            );

            $limit = self::LIST_LIMIT + 1;  // one extra row tells whether the list was cut
            $vars['offlineHosts'] = Db::all(
                "SELECT id, hostname, remote_addr, last_seen, offline_since
                   FROM $hosts
                  WHERE offline_since IS NOT NULL
                  ORDER BY offline_since, hostname
                  LIMIT $limit"
            );
            $vars['failingHosts'] = Db::all(
                "SELECT id, hostname, remote_addr, services_failed, services_total, last_seen
                   FROM $hosts
                  WHERE offline_since IS NULL AND services_failed > 0
                  ORDER BY services_failed DESC, hostname
                  LIMIT $limit"
            );
            $vars['failingServices'] = Db::all(
                "SELECT h.id AS host_id, h.hostname, s.name, s.type, s.status, s.status_changed_at,
                        (SELECT e.message FROM $events e
                          WHERE e.host_id = s.host_id AND e.service = s.name AND e.state = 1
                          ORDER BY e.collected_at DESC LIMIT 1) AS message
                   FROM $services s
                   JOIN $hosts h ON h.id = s.host_id
                  WHERE s.status <> 0 AND h.offline_since IS NULL
                  ORDER BY h.hostname, s.name
                  LIMIT $limit"
            );
        } catch (\PDOException $e) {
            error_log('bmon-web: collector database: ' . $e->getMessage());
            $vars['collectorError'] = 'The collector database is not reachable. Check collector_db in the configuration '
                . 'and that the web database user has SELECT on it.';
        }
        return View::render('dashboard', $vars);
    }
}
