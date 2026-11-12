<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\HostMeta;
use Bmon\Web\HttpException;
use Bmon\Web\Inventory;
use Bmon\Web\Audit;
use Bmon\Web\MonitClient;
use Bmon\Web\Monit;
use Bmon\Web\View;

/** Status → host list, and the detail page of one host. */
final class StatusController
{
    private const PER_PAGE = 100;

    /** sort key => SQL expression (computed columns of the list query) */
    private const SORTS = [
        'state'    => 'state',
        'host'     => 'hostname',
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
                $where[] = "(h.hostname LIKE ? OR hm.display_name LIKE ? OR h.remote_addr LIKE ?)";
                $like = '%' . addcslashes($q, '%_\\') . '%';
                array_push($params, $like, $like, $like);
            }
            $searchWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            // counts per state, honouring the search but not the state filter
            $vars['counts'] = Db::one(
                "SELECT COUNT(*) AS `all`,
                        COALESCE(SUM(h.offline_since IS NULL AND h.services_failed = 0), 0) AS ok,
                        COALESCE(SUM(h.offline_since IS NULL AND h.services_failed > 0), 0) AS failing,
                        COALESCE(SUM(h.offline_since IS NOT NULL), 0) AS offline
                   FROM $h h " . Db::hostMetaJoin() . " $searchWhere",
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
            $tiebreak = $sort === 'state' ? ', h.services_failed DESC, hostname' : ', hostname';

            $vars['hosts'] = Db::all(
                'SELECT h.id, ' . Db::hostName() . " AS hostname, h.hostname AS monit_hostname, h.remote_addr, h.monit_version, h.os_name, h.os_release,
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
                   " . Db::hostMetaJoin() . "
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
        $host = Db::host($id) ?? throw new HttpException(404, 'Host not found.');

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
        [$sort, $dir] = $this->sortServices($services);

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
            'sort' => $sort,
            'dir' => $dir,
            'groups' => $groups,
            'events' => $events,
            'inventory' => Inventory::forHost($id),
            'allItems' => Inventory::all(),
            'canEdit' => Auth::isAdmin(),
            'canAct' => Auth::isAdmin() && MonitClient::available($host) && $host['offline_since'] === null,
            'actionsNote' => self::actionsNote($host),
        ]);
    }

    /** Default direction per column of the host's services table. */
    private const SERVICE_SORTS = [
        'type' => 'asc', 'status' => 'desc', 'service' => 'asc', 'since' => 'desc', 'checked' => 'desc',
    ];

    /**
     * Sorts the host's services in place by ?sort= / ?dir=.
     * The default ("type") keeps the SQL order: Monit's type order, then name.
     * @return array{0:string,1:string} effective sort and direction
     */
    private function sortServices(array &$services): array
    {
        $sort = array_key_exists($_GET['sort'] ?? '', self::SERVICE_SORTS) ? $_GET['sort'] : 'type';
        $dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : self::SERVICE_SORTS[$sort];

        $typeRank = array_flip(Monit::TYPE_ORDER);
        // failing > not monitored / initializing > OK
        $stateRank = static fn (array $s): int => match (Monit::serviceState($s)[0]) {
            'fail' => 2, 'off' => 1, default => 0,
        };
        $key = match ($sort) {
            'status'  => static fn (array $s): array => [$stateRank($s), (string) $s['status_changed_at']],
            'service' => static fn (array $s): array => [mb_strtolower($s['name'])],
            'since'   => static fn (array $s): array => [(string) $s['status_changed_at']],
            'checked' => static fn (array $s): array => [(string) $s['collected_at']],
            default   => static fn (array $s): array => [$typeRank[(int) $s['type']] ?? 99, mb_strtolower($s['name'])],
        };
        usort($services, static function (array $a, array $b) use ($key, $dir): int {
            $c = $key($a) <=> $key($b);
            if ($c === 0) {
                return strnatcasecmp($a['name'], $b['name']);   // stable, A→Z tiebreak
            }
            return $dir === 'asc' ? $c : -$c;
        });
        return [$sort, $dir];
    }

    /** POST /status/{id}/action: send start/stop/restart/monitor/unmonitor to the host's Monit. */
    public function action(int $id): never
    {
        $host = Db::host($id) ?? throw new HttpException(404, 'Host not found.');
        $service = is_string($_POST['service'] ?? null) ? $_POST['service'] : '';
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        // back to the same view (sort order) of the host page
        $back = '/status/' . $id;
        $sort = $_POST['sort'] ?? '';
        if (is_string($sort) && array_key_exists($sort, self::SERVICE_SORTS)) {
            $back .= '?' . http_build_query(['sort' => $sort, 'dir' => ($_POST['dir'] ?? '') === 'asc' ? 'asc' : 'desc']);
        }

        $exists = Db::value('SELECT COUNT(*) FROM ' . Db::collector('services') . ' WHERE host_id = ? AND name = ?', [$id, $service]);
        if (!$exists || !array_key_exists($action, MonitClient::ACTIONS)) {
            flash('error', 'Choose a service and an action.');
            redirect($back);
        }
        if ($host['offline_since'] !== null) {
            flash('error', "{$host['hostname']} is offline: the action was not sent.");
            redirect($back);
        }

        $label = MonitClient::ACTIONS[$action];
        $res = MonitClient::action($host, $service, $action);
        Audit::log(
            'service.' . $action,
            "{$host['hostname']} (id $id) / $service: " . ($res['ok'] ? 'sent' : 'failed — ' . $res['message'])
        );
        if ($res['ok']) {
            flash('success', "$label sent to “{$service}” on {$host['hostname']}. Monit runs it now; "
                . 'the new state appears with the next report.');
        } else {
            flash('error', "$label of “{$service}” failed: {$res['message']}");
        }
        redirect($back);
    }

    /** Why actions are not available on this host (null = they are). */
    private static function actionsNote(array $host): ?string
    {
        if (!Auth::isAdmin()) {
            return null;
        }
        if ((int) $host['httpd_port'] <= 0) {
            return 'Actions are not available: Monit httpd is not enabled on this host ("set httpd" in monitrc).';
        }
        if (($host['httpd_password'] ?? '') === '') {
            return 'Actions are not available: the reports of this host carry no httpd credentials. '
                . 'Remove "and register without credentials" from "set mmonit" in its monitrc.';
        }
        if ($host['offline_since'] !== null) {
            return 'Actions are disabled while the host is offline.';
        }
        return null;
    }

    /** GET /status/{id}/monit-check: JSON reachability of the host's Monit httpd (used by app.js). */
    public function monitCheck(int $id): string
    {
        $host = Db::host($id) ?? throw new HttpException(404, 'Host not found.');
        header('Content-Type: application/json; charset=utf-8');
        return json_encode(MonitClient::ping($host), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** POST /status/{id}/name: display name in Bmon-web ('' = the name sent by Monit). */
    public function rename(int $id): never
    {
        $host = Db::host($id) ?? throw new HttpException(404, 'Host not found.');
        $name = trim(preg_replace('/\s+/', ' ', is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : ''));
        if ($name === $host['monit_hostname']) {
            $name = '';   // same as Monit's: no override
        }
        if (($err = HostMeta::nameError($name)) !== null) {
            flash('error', $err);
            redirect('/status/' . $id);
        }
        HostMeta::setName($id, $name, (string) Auth::user()['username']);
        Audit::log('host.rename', "id $id: " . ($host['display_name'] ?: $host['monit_hostname'])
            . ' → ' . ($name !== '' ? $name : $host['monit_hostname'] . ' (name sent by Monit)'));
        flash('success', $name !== ''
            ? "The host is now shown as “{$name}” (Monit calls it “{$host['monit_hostname']}”)."
            : "The host is shown again with the name sent by Monit, “{$host['monit_hostname']}”.");
        redirect('/status/' . $id);
    }

    /** POST /status/{id}/notes */
    public function notes(int $id): never
    {
        $host = Db::host($id) ?? throw new HttpException(404, 'Host not found.');
        $notes = rtrim(str_replace(["\r\n", "\r"], "\n", is_string($_POST['notes'] ?? null) ? $_POST['notes'] : ''));
        if (mb_strlen($notes) > HostMeta::MAX_NOTES) {
            flash('error', 'The notes are too long (max ' . HostMeta::MAX_NOTES . ' characters).');
            redirect('/status/' . $id . '#notes');
        }
        HostMeta::setNotes($id, $notes, (string) Auth::user()['username']);
        Audit::log('host.notes', "{$host['hostname']} (id $id): " . ($notes === '' ? 'notes cleared' : mb_strlen($notes) . ' characters'));
        flash('success', 'Notes saved.');
        redirect('/status/' . $id . '#notes');
    }
}
