<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Current state read from the bmon-collector database: totals, offline hosts,
 * hosts with failing services and failing services. Shared by the dashboard and
 * the Slack report, so both always show the same thing.
 *
 * Services of offline hosts are stale: they are counted apart, never as OK or failing.
 */
final class Overview
{
    public static function stats(): array
    {
        return Db::one(
            'SELECT COUNT(*) AS hosts,
                    COALESCE(SUM(offline_since IS NOT NULL), 0) AS hosts_offline,
                    COALESCE(SUM(offline_since IS NULL AND services_failed > 0), 0) AS hosts_failing,
                    COALESCE(SUM(IF(offline_since IS NULL, services_total, 0)), 0) AS services,
                    COALESCE(SUM(IF(offline_since IS NULL, services_failed, 0)), 0) AS services_failed,
                    COALESCE(SUM(IF(offline_since IS NOT NULL, services_total, 0)), 0) AS services_offline
               FROM ' . Db::collector('hosts')
        ) ?? [];
    }

    public static function offlineHosts(int $limit): array
    {
        return Db::all(
            'SELECT id, hostname, remote_addr, last_seen, offline_since
               FROM ' . Db::collector('hosts') . '
              WHERE offline_since IS NOT NULL
              ORDER BY offline_since, hostname
              LIMIT ' . max(1, $limit)
        );
    }

    public static function failingHosts(int $limit): array
    {
        return Db::all(
            'SELECT id, hostname, remote_addr, services_failed, services_total, last_seen
               FROM ' . Db::collector('hosts') . '
              WHERE offline_since IS NULL AND services_failed > 0
              ORDER BY services_failed DESC, hostname
              LIMIT ' . max(1, $limit)
        );
    }

    /** Failing services of online hosts, with the message of their last failure event. */
    public static function failingServices(int $limit): array
    {
        $events = Db::collector('events');
        return Db::all(
            "SELECT h.id AS host_id, h.hostname, h.remote_addr, s.name, s.type, s.status, s.status_changed_at,
                    (SELECT e.message FROM $events e
                      WHERE e.host_id = s.host_id AND e.service = s.name AND e.state = 1
                      ORDER BY e.collected_at DESC LIMIT 1) AS message
               FROM " . Db::collector('services') . ' s
               JOIN ' . Db::collector('hosts') . ' h ON h.id = s.host_id
              WHERE s.status <> 0 AND h.offline_since IS NULL
              ORDER BY h.hostname, s.name
              LIMIT ' . max(1, $limit)
        );
    }
}
