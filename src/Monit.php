<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Monit constants and presentation of the service data stored by bmon-collector. */
final class Monit
{
    /** Service_Type */
    public const SERVICE_TYPES = [
        0 => 'Filesystem', 1 => 'Directory', 2 => 'File', 3 => 'Process', 4 => 'Remote host',
        5 => 'System', 6 => 'Fifo', 7 => 'Program', 8 => 'Network',
    ];

    /** Display order of service types on the host page (as in Monit's own status page). */
    public const TYPE_ORDER = [5, 3, 7, 0, 1, 2, 6, 4, 8];

    /** Event_* bitmask used by the service status (src/event.h in Monit). */
    public const EVENTS = [
        0x1 => 'Checksum', 0x2 => 'Resource limit', 0x4 => 'Timeout', 0x8 => 'Timestamp',
        0x10 => 'Size', 0x20 => 'Connection', 0x40 => 'Permission', 0x80 => 'UID',
        0x100 => 'GID', 0x200 => 'Does not exist', 0x400 => 'Invalid type', 0x800 => 'Data access',
        0x1000 => 'Execution', 0x2000 => 'Filesystem flags', 0x4000 => 'ICMP', 0x8000 => 'Content',
        0x10000 => 'Monit instance', 0x20000 => 'Action', 0x40000 => 'PID', 0x80000 => 'PPID',
        0x100000 => 'Heartbeat', 0x200000 => 'Status', 0x400000 => 'Uptime', 0x800000 => 'Link',
        0x1000000 => 'Speed', 0x2000000 => 'Saturation', 0x4000000 => 'Download bytes',
        0x8000000 => 'Upload bytes', 0x10000000 => 'Download packets', 0x20000000 => 'Upload packets',
        0x40000000 => 'Exists',
    ];

    /** Event state */
    public const EVENT_STATES = [0 => 'Succeeded', 1 => 'Failed', 2 => 'Changed', 3 => 'Not changed', 4 => 'Init'];

    /** Action_Type (pending action) */
    public const ACTIONS = [
        0 => 'ignore', 1 => 'alert', 2 => 'restart', 3 => 'stop', 4 => 'exec',
        5 => 'unmonitor', 6 => 'start', 7 => 'monitor',
    ];

    public static function serviceType(int $type): string
    {
        return self::SERVICE_TYPES[$type] ?? "Type $type";
    }

    public static function eventName(int $id): string
    {
        return $id === 0 ? 'Monit' : (self::EVENTS[$id] ?? sprintf('Event 0x%x', $id));
    }

    /** Names of the failing checks in a status bitmask, e.g. ['Connection', 'Timeout']. */
    public static function failures(int $status): array
    {
        $names = [];
        foreach (self::EVENTS as $bit => $name) {
            if ($status & $bit) {
                $names[] = $name;
            }
        }
        if ($names === [] && $status !== 0) {
            $names[] = sprintf('Status 0x%x', $status);
        }
        return $names;
    }

    /**
     * Status of a service row for display.
     * @return array{0:string,1:string} [css class: ok|fail|off, label]
     */
    public static function serviceState(array $s): array
    {
        $monitor = (int) $s['monitor'];
        if ($monitor === 0) {
            return ['off', 'Not monitored'];
        }
        if ((int) $s['status'] !== 0) {
            return ['fail', implode(', ', self::failures((int) $s['status']))];
        }
        if ($monitor === 2) {
            return ['off', 'Initializing'];
        }
        return ['ok', 'OK'];
    }

    /**
     * Short type-specific summary of a service, as label => value pairs.
     * $d is the decoded `data` column.
     */
    public static function summary(int $type, array $d): array
    {
        $out = [];
        $g = static function (array $a, string $path): mixed {
            foreach (explode('.', $path) as $k) {
                if (!is_array($a) || !array_key_exists($k, $a)) {
                    return null;
                }
                $a = $a[$k];
            }
            return $a;
        };

        switch ($type) {
            case 5: // system
                if (($l = $g($d, 'system.load')) !== null) {
                    $out['Load'] = sprintf('%.2f  %.2f  %.2f', $l['avg01'] ?? 0, $l['avg05'] ?? 0, $l['avg15'] ?? 0);
                }
                if (($c = $g($d, 'system.cpu')) !== null) {
                    $out['CPU'] = fmt_pct(self::cpuBusy($c)) . sprintf(
                        '  (us %.1f, sy %.1f, wa %.1f)', $c['user'] ?? 0, $c['system'] ?? 0, $c['wait'] ?? 0
                    );
                }
                if (($m = $g($d, 'system.memory')) !== null) {
                    $out['Memory'] = fmt_pct($m['percent'] ?? null) . '  (' . fmt_bytes(($m['kilobyte'] ?? 0) * 1024) . ')';
                }
                if (($m = $g($d, 'system.swap')) !== null) {
                    $out['Swap'] = fmt_pct($m['percent'] ?? null) . '  (' . fmt_bytes(($m['kilobyte'] ?? 0) * 1024) . ')';
                }
                break;

            case 0: // filesystem (block values in MB)
                if (($b = $g($d, 'block')) !== null) {
                    $out['Space'] = fmt_pct($b['percent'] ?? null) . '  ('
                        . fmt_bytes(($b['usage'] ?? 0) * 1048576) . ' of ' . fmt_bytes(($b['total'] ?? 0) * 1048576) . ')';
                }
                if (($i = $g($d, 'inode.percent')) !== null) {
                    $out['Inodes'] = fmt_pct($i);
                }
                if (($t = $g($d, 'fstype')) !== null) {
                    $out['Type'] = (string) $t;
                }
                break;

            case 3: // process
                if (($p = $g($d, 'pid')) !== null) {
                    $out['PID'] = (string) $p;
                }
                if (($u = $g($d, 'uptime')) !== null) {
                    $out['Uptime'] = fmt_duration((int) $u);
                }
                if (($c = $g($d, 'cpu.percenttotal')) !== null) {
                    $out['CPU'] = fmt_pct($c);
                }
                if (($m = $g($d, 'memory.kilobytetotal')) !== null) {
                    $out['Memory'] = fmt_bytes($m * 1024) . '  (' . fmt_pct($g($d, 'memory.percenttotal')) . ')';
                }
                if (($ch = $g($d, 'children')) !== null) {
                    $out['Children'] = (string) $ch;
                }
                break;

            case 4: // remote host
                foreach (['icmp' => 'Ping', 'port' => 'Port', 'unix' => 'Socket'] as $key => $label) {
                    $items = $g($d, $key);
                    if ($items === null) {
                        continue;
                    }
                    $items = array_is_list($items) ? $items : [$items];
                    foreach ($items as $n => $it) {
                        $rt = (float) ($it['responsetime'] ?? -1);
                        $time = $rt < 0 ? 'failed' : number_format($rt * 1000, 1) . ' ms';
                        if ($key === 'icmp') {
                            $what = (string) ($it['type'] ?? 'Ping');
                        } elseif ($key === 'port') {
                            $what = ($it['hostname'] ?? '') . ':' . ($it['portnumber'] ?? '')
                                . ' ' . ($it['protocol'] ?? '') . '/' . ($it['type'] ?? '');
                        } else {
                            $what = (string) ($it['path'] ?? '') . ' ' . ($it['protocol'] ?? '');
                        }
                        $out[$label . (count($items) > 1 ? ' ' . ($n + 1) : '')] = trim($what) . '  ' . $time;
                    }
                }
                break;

            case 2: // file
                if (($sz = $g($d, 'size')) !== null) {
                    $out['Size'] = fmt_bytes($sz);
                }
                // fall through: timestamps are shared with directory and fifo
            case 1:
            case 6:
                if (($t = $g($d, 'timestamps.modify') ?? $g($d, 'timestamp')) !== null) {
                    $out['Modified'] = fmt_ts($t);
                }
                if (($c = $g($d, 'checksum')) !== null && !is_array($c)) {
                    $out['Checksum'] = (string) $c;
                }
                break;

            case 7: // program
                if (($os = OsInfo::parse($g($d, 'program.output'))) !== null) {
                    $out['OS'] = $os['pretty'];
                    if ($os['arch'] !== '') {
                        $out['Arch'] = $os['arch'];
                    }
                    break;
                }
                if (($st = $g($d, 'program.status')) !== null) {
                    $out['Exit status'] = (string) $st;
                }
                if (($o = $g($d, 'program.output')) !== null && trim((string) $o) !== '') {
                    $out['Output'] = mb_strimwidth(trim((string) $o), 0, 200, '…');
                }
                if (($t = $g($d, 'program.started')) !== null) {
                    $out['Last run'] = fmt_ts($t);
                }
                break;

            case 8: // network interface
                if (($state = $g($d, 'link.state')) !== null) {
                    $out['Link'] = (int) $state === 1 ? 'up' : ((int) $state === 0 ? 'down' : 'n/a');
                }
                if (($sp = $g($d, 'link.speed')) !== null && (int) $sp > 0) {
                    $out['Speed'] = ((int) $sp >= 1e9 ? ((int) $sp / 1e9) . ' Gb/s' : ((int) $sp / 1e6) . ' Mb/s');
                }
                if (($dn = $g($d, 'link.download.bytes.now')) !== null) {
                    $out['Download'] = fmt_bytes($dn) . '/s';
                }
                if (($up = $g($d, 'link.upload.bytes.now')) !== null) {
                    $out['Upload'] = fmt_bytes($up) . '/s';
                }
                break;
        }
        return $out;
    }

    /** CPU busy percentage from a Monit system.cpu block (everything but idle). */
    public static function cpuBusy(array $c): float
    {
        $sum = 0.0;
        foreach (['user', 'system', 'nice', 'wait', 'hardirq', 'softirq', 'steal'] as $k) {
            $sum += (float) ($c[$k] ?? 0);
        }
        return min(100.0, $sum);
    }

    /** Flattens nested data into "a.b.c" => scalar pairs, for the raw details view. */
    public static function flatten(array $d, string $prefix = ''): array
    {
        $out = [];
        foreach ($d as $k => $v) {
            $key = $prefix === '' ? (string) $k : "$prefix.$k";
            if (is_array($v)) {
                $out += self::flatten($v, $key);
            } else {
                $out[$key] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            }
        }
        return $out;
    }
}
