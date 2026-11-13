<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * AgentConf → Group Checks: named sets of checks assigned to agents. Their checks
 * are rows of agent_checks with host_id = -<group id>; an agent gets the enabled
 * ones of its groups in its zip and publications, as files
 * bmon-<group slug>.<check name>.conf. Editing a group changes every agent of it
 * at the next zip / Publish.
 */
final class CheckGroups
{
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}$/';

    /** host_id of the checks of a group in agent_checks. */
    public static function sid(int $groupId): int
    {
        return -$groupId;
    }

    public static function all(): array
    {
        return Db::all(
            'SELECT g.*,
                    (SELECT COUNT(*) FROM agent_checks c WHERE c.host_id = -g.id) AS checks,
                    (SELECT COALESCE(SUM(c.enabled), 0) FROM agent_checks c WHERE c.host_id = -g.id) AS enabled,
                    (SELECT COUNT(*) FROM check_group_hosts h WHERE h.group_id = g.id) AS hosts
               FROM check_groups g ORDER BY g.name'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM check_groups WHERE id = ?', [$id]);
    }

    /** Groups assigned to an agent, by name. */
    public static function forHost(int $hostId): array
    {
        if ($hostId <= 0) {
            return [];
        }
        return Db::all(
            'SELECT g.* FROM check_groups g JOIN check_group_hosts h ON h.group_id = g.id WHERE h.host_id = ? ORDER BY g.slug',
            [$hostId]
        );
    }

    /** Host ids of a group. */
    public static function hostIds(int $groupId): array
    {
        return array_map('intval', array_column(Db::all('SELECT host_id FROM check_group_hosts WHERE group_id = ?', [$groupId]), 'host_id'));
    }

    /** @return array<string,string> field => error */
    public static function validate(string $name, string $slug, string $description, ?int $id): array
    {
        $e = [];
        if ($name === '' || mb_strlen($name) > 64) {
            $e['name'] = 'Give the group a name (max 64 characters).';
        } elseif (($o = Db::one('SELECT id FROM check_groups WHERE name = ?', [$name])) && (int) $o['id'] !== $id) {
            $e['name'] = 'Another group has this name.';
        }
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            $e['slug'] = 'Lowercase letters, digits and dashes (max 32), e.g. web.';
        } elseif (($o = Db::one('SELECT id FROM check_groups WHERE slug = ?', [$slug])) && (int) $o['id'] !== $id) {
            $e['slug'] = 'Another group uses this prefix.';
        }
        if (mb_strlen($description) > 255) {
            $e['description'] = 'Max 255 characters.';
        }
        return $e;
    }

    /** A prefix from a name: "Web server" -> "web-server". */
    public static function slugify(string $name): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
        return substr($s, 0, 32) ?: 'group';
    }

    public static function create(string $name, string $slug, string $description, string $by): int
    {
        $now = now_utc();
        return Db::insert('INSERT INTO check_groups (name, slug, description, created_at, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $slug, $description, $now, $now, $by]);
    }

    public static function update(int $id, string $name, string $slug, string $description, string $by): void
    {
        Db::exec('UPDATE check_groups SET name = ?, slug = ?, description = ?, updated_at = ?, updated_by = ? WHERE id = ?',
            [$name, $slug, $description, now_utc(), $by, $id]);
    }

    /** Deletes the group, its checks (and their service groups) and its assignments. */
    public static function delete(int $id): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM agent_checks WHERE host_id = ?', [self::sid($id)]);
            Db::exec('DELETE FROM check_groups WHERE id = ?', [$id]);   // check_group_hosts by cascade
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function assign(int $groupId, int $hostId, string $by): bool
    {
        return Db::exec('INSERT IGNORE INTO check_group_hosts (group_id, host_id, created_at, created_by) VALUES (?, ?, ?, ?)',
            [$groupId, $hostId, now_utc(), $by]) === 1;
    }

    public static function unassign(int $groupId, int $hostId): bool
    {
        return Db::exec('DELETE FROM check_group_hosts WHERE group_id = ? AND host_id = ?', [$groupId, $hostId]) === 1;
    }

    /** Group id => number of enabled checks, for the agent list. */
    public static function enabledByHost(): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT h.host_id, COUNT(c.id) AS n FROM check_group_hosts h
               JOIN agent_checks c ON c.host_id = -h.group_id AND c.enabled = 1 GROUP BY h.host_id') as $r) {
            $out[(int) $r['host_id']] = (int) $r['n'];
        }
        return $out;
    }
}
