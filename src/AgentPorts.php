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
