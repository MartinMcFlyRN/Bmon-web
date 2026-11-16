<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Listening TCP/UDP ports of an agent, scanned only on request: the agent page
 * marks a scan as requested and starts the auto-update check; bmon-update.sh
 * finds "ports" in the X-Bmon-Tasks header of Bmon-web and sends the output of
 * `ss -tulpn` (or `netstat -tulpn`), signed like its other requests.
 */
final class AgentPorts
{
    public const TASK = 'ports';

    public static function get(int $hostId): ?array
    {
        return Db::one('SELECT * FROM agent_ports WHERE host_id = ?', [$hostId]);
    }

    public static function request(int $hostId, string $by): void
    {
        Db::exec('INSERT INTO agent_ports (host_id, requested_at, requested_by) VALUES (?, ?, ?)
                  ON DUPLICATE KEY UPDATE requested_at = VALUES(requested_at), requested_by = VALUES(requested_by)',
            [$hostId, now_utc(), $by]);
    }

    /** Whether a scan is waiting for the agent. */
    public static function pending(?array $row): bool
    {
        return $row !== null && $row['requested_at'] !== null
            && ($row['scanned_at'] === null || strcmp($row['scanned_at'], $row['requested_at']) < 0);
    }

    /** Tasks for the X-Bmon-Tasks header of an agent. */
    public static function tasks(int $hostId): array
    {
        return self::pending(self::get($hostId)) ? [self::TASK] : [];
    }

    public static function store(int $hostId, string $output): void
    {
        $tool = preg_match('/^TOOL (\w+)/', $output, $m) ? substr($m[1], 0, 16) : '';
        Db::exec('INSERT INTO agent_ports (host_id, scanned_at, tool, output) VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE scanned_at = VALUES(scanned_at), tool = VALUES(tool), output = VALUES(output)',
            [$hostId, now_utc(), $tool, mb_substr($output, 0, 1000000)]);
    }

    /**
     * Sockets from the output of ss -tulpn or netstat -tulpn.
     * @return list<array{proto:string,addr:string,port:int,process:string,pid:string,service:string,scope:string}>
     */
    public static function parse(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 4) {
                continue;
            }
            if (preg_match('/^(tcp|udp)6?$/', $f[0], $pm) && isset($f[4]) && str_contains($f[4], ':') && !preg_match('/^\d+$/', $f[1])) {
                // ss: Netid State Recv-Q Send-Q Local Peer [Process]
                $proto = $pm[1];
                $local = $f[4];
                $proc = implode(' ', array_slice($f, 6));
            } elseif (preg_match('/^(tcp|udp)6?$/', $f[0], $pm) && isset($f[3]) && preg_match('/^\d+$/', $f[1])) {
                // netstat: Proto Recv-Q Send-Q Local Foreign [State] PID/Program
                $proto = $pm[1];
                $local = $f[3];
                $proc = '';
                foreach (array_slice($f, 4) as $i => $x) {
                    if (preg_match('#^\d+/#', $x)) {
                        $proc = implode(' ', array_slice($f, 4 + $i));
                        break;
                    }
                }
                if ($proto === 'tcp' && ($f[5] ?? '') !== 'LISTEN') {
                    continue;
                }
            } else {
                continue;
            }
            $cut = strrpos($local, ':');
            if ($cut === false) {
                continue;
            }
            $port = substr($local, $cut + 1);
            if (!ctype_digit($port)) {
                continue;
            }
            $addr = trim(preg_replace('/%[^\]]*/', '', substr($local, 0, $cut)), '[]');
            $process = '';
            $pid = '';
            if (preg_match('/users:\(\("([^"]+)",pid=(\d+)/', $proc, $m)) {
                [$process, $pid] = [$m[1], $m[2]];
            } elseif (preg_match('#^(\d+)/(.+)$#', $proc, $m)) {
                [$pid, $process] = [$m[1], $m[2]];
            }
            $key = "$proto|$addr|$port";
            if (isset($rows[$key])) {
                continue;
            }
            $service = (string) (@getservbyport((int) $port, $proto) ?: '');
            $rows[$key] = [
                'proto' => $proto, 'addr' => $addr === '' ? '*' : $addr, 'port' => (int) $port,
                'process' => $process, 'pid' => $pid, 'service' => $service, 'scope' => self::scope($addr),
            ];
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b) => [$a['port'], $a['proto'], $a['addr']] <=> [$b['port'], $b['proto'], $b['addr']]);
        return $rows;
    }

    /**
     * Sockets grouped by port: one entry per port with its protocols, addresses, processes and usual services.
     *
     * @param list<array> $rows from parse()
     * @return list<array{port:int,protos:list<string>,addrs:list<string>,scope:string,procs:list<array{process:string,pid:string}>,services:list<string>}>
     */
    public static function byPort(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $g = &$groups[$r['port']];
            $g ??= ['port' => $r['port'], 'protos' => [], 'addrs' => [], 'scope' => 'local', 'procs' => [], 'services' => []];
            self::merge($g, $r);
            unset($g);
        }
        ksort($groups);
        return array_map([self::class, 'tidy'], array_values($groups));
    }

    /**
     * Sockets grouped by service (process name): one entry per process with its ports.
     *
     * @param list<array> $rows from parse()
     * @return list<array{process:string,pids:list<string>,ports:list<array{port:int,protos:list<string>}>,scope:string,addrs:list<string>,services:list<string>}>
     */
    public static function byService(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $g = &$groups[$r['process']];
            $g ??= ['process' => $r['process'], 'pids' => [], 'ports' => [], 'addrs' => [], 'scope' => 'local', 'procs' => [], 'services' => []];
            self::merge($g, $r);
            if ($r['pid'] !== '' && !in_array($r['pid'], $g['pids'], true)) {
                $g['pids'][] = $r['pid'];
            }
            $g['ports'][$r['port']]['port'] = $r['port'];
            $g['ports'][$r['port']]['protos'][] = $r['proto'];
            unset($g);
        }
        foreach ($groups as &$g) {
            ksort($g['ports']);
            $g['ports'] = array_map(static fn ($p) => ['port' => $p['port'], 'protos' => array_values(array_unique($p['protos']))], array_values($g['ports']));
            sort($g['pids'], SORT_NUMERIC);
            $g = self::tidy($g);
        }
        unset($g);
        // Named processes first (alphabetically), sockets without a process last.
        uksort($groups, static fn ($a, $b) => [(string) $a === '', strtolower((string) $a)] <=> [(string) $b === '', strtolower((string) $b)]);
        return array_values($groups);
    }

    private static function merge(array &$g, array $r): void
    {
        $g['protos'][] = $r['proto'];
        $g['addrs'][] = $r['addr'];
        $g['procs'][$r['process'] . '|' . $r['pid']] = ['process' => $r['process'], 'pid' => $r['pid']];
        if ($r['service'] !== '') {
            $g['services'][] = $r['service'];
        }
        $rank = ['local' => 0, 'one' => 1, 'all' => 2];
        if ($rank[$r['scope']] > $rank[$g['scope']]) {
            $g['scope'] = $r['scope'];
        }
    }

    private static function tidy(array $g): array
    {
        foreach (['protos', 'addrs', 'services'] as $k) {
            $g[$k] = array_values(array_unique($g[$k] ?? []));
        }
        $g['procs'] = array_values($g['procs']);
        return $g;
    }

    /** all (every interface) | local (loopback only) | one (a specific address). */
    private static function scope(string $addr): string
    {
        return match (true) {
            in_array($addr, ['', '*', '0.0.0.0', '::'], true) => 'all',
            str_starts_with($addr, '127.') || $addr === '::1' || $addr === 'localhost' => 'local',
            default => 'one',
        };
    }
}
