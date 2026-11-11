<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Repository for labels and their assignment to hosts. */
final class Labels
{
    /** All labels with the number of (still existing) hosts that carry them. */
    public static function allWithCounts(): array
    {
        return Db::all(
            'SELECT l.id, l.name, l.description, l.created_at, l.updated_at, COUNT(h.id) AS hosts
               FROM labels l
               LEFT JOIN host_labels hl ON hl.label_id = l.id
               LEFT JOIN ' . Db::collector('hosts') . ' h ON h.id = hl.host_id
              GROUP BY l.id, l.name, l.description, l.created_at, l.updated_at
              ORDER BY l.name'
        );
    }

    public static function all(): array
    {
        return Db::all('SELECT id, name, description FROM labels ORDER BY name');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT id, name, description, created_at, updated_at FROM labels WHERE id = ?', [$id]);
    }

    public static function findByName(string $name): ?array
    {
        return Db::one('SELECT id, name FROM labels WHERE name = ?', [$name]);
    }

    public static function create(string $name, string $description): int
    {
        $now = now_utc();
        return Db::insert(
            'INSERT INTO labels (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $description, $now, $now]
        );
    }

    public static function update(int $id, string $name, string $description): void
    {
        Db::exec('UPDATE labels SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [$name, $description, now_utc(), $id]);
    }

    /** Deletes the label; its host assignments go with it (ON DELETE CASCADE). */
    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM labels WHERE id = ?', [$id]);
    }

    /** Hosts carrying a label. */
    public static function hosts(int $labelId): array
    {
        return Db::all(
            'SELECT h.id, h.hostname, h.remote_addr, h.services_failed, h.offline_since
               FROM host_labels hl
               JOIN ' . Db::collector('hosts') . ' h ON h.id = hl.host_id
              WHERE hl.label_id = ?
              ORDER BY h.hostname',
            [$labelId]
        );
    }

    /** Labels of one host. */
    public static function forHost(int $hostId): array
    {
        return Db::all(
            'SELECT l.id, l.name, l.description
               FROM host_labels hl JOIN labels l ON l.id = hl.label_id
              WHERE hl.host_id = ?
              ORDER BY l.name',
            [$hostId]
        );
    }

    /** @return bool false if the host already had the label */
    public static function assign(int $hostId, int $labelId): bool
    {
        return Db::exec(
            'INSERT IGNORE INTO host_labels (host_id, label_id, created_at) VALUES (?, ?, ?)',
            [$hostId, $labelId, now_utc()]
        ) > 0;
    }

    public static function unassign(int $hostId, int $labelId): bool
    {
        return Db::exec('DELETE FROM host_labels WHERE host_id = ? AND label_id = ?', [$hostId, $labelId]) > 0;
    }

    /** @return array<string,string> field => error */
    public static function validate(string $name, string $description, ?int $existingId = null): array
    {
        $errors = [];
        if ($name === '' || mb_strlen($name) > 64) {
            $errors['name'] = 'The name is required (at most 64 characters).';
        } elseif (preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            $errors['name'] = 'The name cannot contain control characters.';
        } else {
            $other = self::findByName($name);
            if ($other !== null && (int) $other['id'] !== $existingId) {
                $errors['name'] = 'A label with this name already exists.';
            }
        }
        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'At most 1000 characters.';
        }
        return $errors;
    }
}
