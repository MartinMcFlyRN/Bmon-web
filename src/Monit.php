<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Monit constants, as stored by bmon-collector. */
final class Monit
{
    /** Service_Type */
    public const SERVICE_TYPES = [
        0 => 'Filesystem', 1 => 'Directory', 2 => 'File', 3 => 'Process', 4 => 'Remote host',
        5 => 'System', 6 => 'Fifo', 7 => 'Program', 8 => 'Network',
    ];

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

    public static function serviceType(int $type): string
    {
        return self::SERVICE_TYPES[$type] ?? "Type $type";
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
}
