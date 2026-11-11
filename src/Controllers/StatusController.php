<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Db;
use Bmon\Web\HttpException;
use Bmon\Web\Monit;
use Bmon\Web\View;

/** Status → host list, and the detail page of one host. */
final class StatusController
{
    private const PER_PAGE = 100;

    /** sort key => SQL expression (computed columns of the list query) */
    private const SORTS = [
        'state'    => 'state',
        'host'     => 'h.hostname',
        'cpu'      => 'cpu',
        'mem'      => 'mem',
        'load'     => 'load1',
        'services' => 'h.services_failed',
        'report'   => 'h.last_seen',
    ];

    private const FILTERS = ['all', 'ok', 'failing', 'offline'];

    public function index(): string
    {
        $q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        $filter = in_array($_GET['filter'] ?? '', self::FILTERS, true) ? $_GET['filter'] : 'all';
        $sort = array_key_exists($_GET['sort'] ?? '', self::SORTS) ? $_GET['sort'] : 'state';
        $dir = ($_GET['dir'] ?? '') === 'asc' ? 'asc' : (($_GET['dir'] ?? '') === 'desc' ? 'desc' : null);
        // default direction: problems / highest values first, names A→Z
        $dir ??= $sort === 'host' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $vars = [
            'title' => 'Status', 'nav' => 'status',
            'q' => $q, 'filter' => $filter, 'sort' => $sort, 'dir' => $dir, 'page' => $page,
            'hosts' => [], 'counts' => null, 'total' => 0, 'pages' => 1, 'collectorError' => null,
        ];

        try {
            $h = Db::collector('hosts');
            $s = Db::collector('services');

            $where = [];
            $params = [];
            if ($q !== '') {
                $where[] = '(h.hostname LIKE ? OR h.remote_addr LIKE ?)';
                $like = '%' . addcslashes($q, '%_\\') . '%';
                array_push($params, $like, $like);
            }
            $searchWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // counts per state, honouring the search but not the state filter
            $vars['counts'] = Db::one(
                "SELECT COUNT(*) AS `all`,
                        COALESCE(SUM(h.offline_since IS NULL AND h.services_failed = 0), 0) AS ok,
                        COALESCE(SUM(h.offline_since IS NULL AND h.services_failed > 0), 0) AS failing,
                        COALESCE(SUM(h.offline_since IS NOT NULL), 0) AS offline
                   FROM $h h $searchWhere",
                $params
            );

            $where[] = match ($filter) {
                'ok' => 'h.offline_since IS NULL AND h.services_failed = 0',
                'failing' => 'h.offline_since IS NULL AND h.services_failed > 0',
                'offline' => 'h.offline_since IS NOT NULL',
                default => '1 = 1',
            };
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $vars['total'] = (int) ($filter === 'all' ? $vars['counts']['all'] : $vars['counts'][$filter]);
            $vars['pages'] = max(1, (int) ceil($vars['total'] / self::PER_PAGE));
            $page = min($page, $vars['pages']);
            $vars['page'] = $page;
            $offset = ($page - 1) * self::PER_PAGE;

            $cpu = "COALESCE(JSON_EXTRACT(sys.data, '$.system.cpu.%s'), 0)";
            $cpuExpr = implode(' + ', array_map(static fn ($k) => sprintf($cpu, $k),
                ['user', 'system', 'nice', 'wait', 'hardirq', 'softirq', 'steal']));

            $order = self::SORTS[$sort] . ' ' . strtoupper($dir);
            // nulls (no system service) last, then a stable tiebreak on the name
            $nullsLast = in_array($sort, ['cpu', 'mem', 'load'], true) ? self::SORTS[$sort] . ' IS NULL, ' : '';
            $tiebreak = $sort === 'state' ? ', h.services_failed DESC, h.hostname' : ', h.hostname';

            $vars['hosts'] = Db::all(
                "SELECT h.id, h.hostname, h.remote_addr, h.monit_version, h.os_name, h.os_release,
                        h.cpu_count, h.memory_kb, h.services_total, h.services_failed,
                        h.last_seen, h.offline_since, h.poll_interval,
                        CASE WHEN h.offline_since IS NOT NULL THEN 2 WHEN h.services_failed > 0 THEN 1 ELSE 0 END AS state,
                        IF(sys.id IS NULL, NULL, LEAST(100, $cpuExpr)) AS cpu,
                        IF(sys.id IS NULL, NULL, JSON_EXTRACT(sys.data, '$.system.memory.percent') + 0) AS mem,
                        IF(sys.id IS NULL, NULL, JSON_EXTRACT(sys.data, '$.system.swap.percent') + 0) AS swap,
                        IF(sys.id IS NULL, NULL, JSON_EXTRACT(sys.data, '$.system.load.avg01') + 0) AS load1,
                        IF(sys.id IS NULL, NULL, JSON_EXTRACT(sys.data, '$.system.load.avg05') + 0) AS load5,
                        IF(sys.id IS NULL, NULL, JSON_EXTRACT(sys.data, '$.system.load.avg15') + 0) AS load15
                   FROM $h h
                   LEFT JOIN $s sys ON sys.host_id = h.id AND sys.type = 5
                   $whereSql
                  ORDER BY $nullsLast$order$tiebreak
                  LIMIT " . self::PER_PAGE . " OFFSET $offset",
                $params
            );
        } catch (\PDOException $e) {
            error_log('bmon-web: collector database: ' . $e->getMessage());
            $vars['collectorError'] = 'The collector database is not reachable.';
        }
        return View::render('status/index', $vars);
    }

    public function host(int $id): string
    {
        $h = Db::collector('hosts');
        $s = Db::collector('services');
        $host = Db::one("SELECT * FROM $h WHERE id = ?", [$id]) ?? throw new HttpException(404, 'Host not found.');

        $services = Db::all(
            "SELECT id, name, type, status, status_hint, monitor, monitor_mode, pending_action,
                    collected_at, status_changed_at, data
               FROM $s WHERE host_id = ?
              ORDER BY FIELD(type, " . implode(',', Monit::TYPE_ORDER) . "), name",
            [$id]
        );
        foreach ($services as &$sv) {
            $sv['data'] = json_decode((string) $sv['data'], true) ?: [];
        }
        unset($sv);

        $groups = [];
        foreach (Db::all("SELECT group_name, service FROM " . Db::collector('service_groups')
                . " WHERE host_id = ? ORDER BY group_name, service", [$id]) as $g) {
            $groups[$g['group_name']][] = $g['service'];
        }

        $events = Db::all(
            "SELECT service, service_type, event_id, state, action, message, collected_at
               FROM " . Db::collector('events') . "
              WHERE host_id = ? ORDER BY collected_at DESC LIMIT 30",
            [$id]
        );

        return View::render('status/host', [
            'title' => $host['hostname'],
            'nav' => 'status',
            'host' => $host,
            'services' => $services,
            'groups' => $groups,
            'events' => $events,
        ]);
    }
}
