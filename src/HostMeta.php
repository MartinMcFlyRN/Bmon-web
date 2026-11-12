<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Data kept by Bmon-web about a host: display name and notes (table host_meta). */
final class HostMeta
{
    public const MAX_NAME = 255;
    public const MAX_NOTES = 10000;

    /** '' restores the name sent by Monit. */
    public static function setName(int $hostId, string $name, string $by): void
    {
        Db::exec(
            'INSERT INTO host_meta (host_id, display_name, name_updated_at, name_updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name),
                 name_updated_at = VALUES(name_updated_at), name_updated_by = VALUES(name_updated_by)',
            [$hostId, $name, now_utc(), $by]
        );
    }

    public static function setNotes(int $hostId, string $notes, string $by): void
    {
        Db::exec(
            'INSERT INTO host_meta (host_id, notes, notes_updated_at, notes_updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE notes = VALUES(notes),
                 notes_updated_at = VALUES(notes_updated_at), notes_updated_by = VALUES(notes_updated_by)',
            [$hostId, $notes === '' ? null : $notes, now_utc(), $by]
        );
    }

    public static function nameError(string $name): ?string
    {
        if (mb_strlen($name) > self::MAX_NAME) {
            return 'At most ' . self::MAX_NAME . ' characters.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            return 'The name cannot contain control characters.';
        }
        return null;
    }
}
