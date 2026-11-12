<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Repository for inventory items and their assignment to hosts. */
final class Inventory
{
    /** All items with the number of (still existing) hosts that carry them. */
    public static function allWithCounts(): array
    {
        return Db::all(
            'SELECT i.id, i.name, i.description, i.created_at, i.updated_at, COUNT(h.id) AS hosts
               FROM inventory i
               LEFT JOIN host_inventory hi ON hi.item_id = i.id
               LEFT JOIN ' . Db::collector('hosts') . ' h ON h.id = hi.host_id
              GROUP BY i.id, i.name, i.description, i.created_at, i.updated_at
              ORDER BY i.name'
        );
    }

    public static function all(): array
    {
        return Db::all('SELECT id, name, description FROM inventory ORDER BY name');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT id, name, description, created_at, updated_at FROM inventory WHERE id = ?', [$id]);
    }

    public static function findByName(string $name): ?array
    {
        return Db::one('SELECT id, name FROM inventory WHERE name = ?', [$name]);
    }

    public static function create(string $name, string $description): int
    {
        $now = now_utc();
        return Db::insert(
            'INSERT INTO inventory (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $description, $now, $now]
        );
    }

    public static function update(int $id, string $name, string $description): void
    {
        Db::exec('UPDATE inventory SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [$name, $description, now_utc(), $id]);
    }

    /** Deletes the item; its host assignments go with it (ON DELETE CASCADE). */
    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM inventory WHERE id = ?', [$id]);
    }

    /** Hosts carrying an item. */
    public static function hosts(int $itemId): array
    {
        return Db::all(
            'SELECT h.id, ' . Db::hostName() . ' AS hostname, h.remote_addr, h.services_failed, h.offline_since
               FROM host_inventory hi
               JOIN ' . Db::collector('hosts') . ' h ON h.id = hi.host_id ' . Db::hostMetaJoin() . '
              WHERE hi.item_id = ?
              ORDER BY hostname',
            [$itemId]
        );
    }

    /** Items of one host. */
    public static function forHost(int $hostId): array
    {
        return Db::all(
            'SELECT i.id, i.name, i.description
               FROM host_inventory hi JOIN inventory i ON i.id = hi.item_id
              WHERE hi.host_id = ?
              ORDER BY i.name',
            [$hostId]
        );
    }

    /** @return bool false if the host already had the item */
    public static function assign(int $hostId, int $itemId): bool
    {
        return Db::exec(
            'INSERT IGNORE INTO host_inventory (host_id, item_id, created_at) VALUES (?, ?, ?)',
            [$hostId, $itemId, now_utc()]
        ) > 0;
    }

    public static function unassign(int $hostId, int $itemId): bool
    {
        return Db::exec('DELETE FROM host_inventory WHERE host_id = ? AND item_id = ?', [$hostId, $itemId]) > 0;
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
                $errors['name'] = 'An item with this name already exists.';
            }
        }
        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'At most 1000 characters.';
        }
        return $errors;
    }
}
