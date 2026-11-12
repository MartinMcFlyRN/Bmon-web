<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Monit service groups defined in Admin → Service groups, assigned to the
 * AgentConf checks. In the files of the zip they become "group <name>" lines.
 */
final class ServiceGroups
{
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public static function all(): array
    {
        return Db::all('SELECT id, name, description FROM service_group_defs ORDER BY name');
    }

    /** With the number of AgentConf checks using each group and of the services Monit reports in it. */
    public static function allWithUsage(): array
    {
        $reported = [];
        try {
            foreach (Db::all('SELECT group_name, COUNT(*) AS n, COUNT(DISTINCT host_id) AS hosts FROM '
                    . Db::collector('service_groups') . ' GROUP BY group_name') as $r) {
                $reported[$r['group_name']] = $r;
            }
        } catch (\PDOException) {
            // collector database unreachable: no usage numbers
        }
        $rows = Db::all(
            'SELECT g.id, g.name, g.description, g.updated_at, COUNT(acg.check_id) AS checks
               FROM service_group_defs g LEFT JOIN agent_check_groups acg ON acg.group_id = g.id
              GROUP BY g.id, g.name, g.description, g.updated_at ORDER BY g.name'
        );
        foreach ($rows as &$r) {
            $r['reported_services'] = (int) ($reported[$r['name']]['n'] ?? 0);
            $r['reported_hosts'] = (int) ($reported[$r['name']]['hosts'] ?? 0);
        }
        return $rows;
    }

    /** Groups Monit reports from the agents that are not defined in Bmon yet. */
    public static function reportedNotDefined(): array
    {
        try {
            return Db::all(
                'SELECT sg.group_name AS name, COUNT(*) AS services, COUNT(DISTINCT sg.host_id) AS hosts
                   FROM ' . Db::collector('service_groups') . ' sg
                  WHERE sg.group_name NOT IN (SELECT name FROM service_group_defs)
                  GROUP BY sg.group_name ORDER BY sg.group_name'
            );
        } catch (\PDOException) {
            return [];
        }
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM service_group_defs WHERE id = ?', [$id]);
    }

    public static function create(string $name, string $description): int
    {
        $now = now_utc();
        return Db::insert('INSERT INTO service_group_defs (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [$name, $description, $now, $now]);
    }

    public static function update(int $id, string $name, string $description): void
    {
        Db::exec('UPDATE service_group_defs SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [$name, $description, now_utc(), $id]);
    }

    /** The checks lose the group (ON DELETE CASCADE). */
    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM service_group_defs WHERE id = ?', [$id]);
    }

    /** AgentConf checks using a group, with their host. */
    public static function checks(int $groupId): array
    {
        return Db::all(
            'SELECT c.id, c.name, c.host_id, ' . Db::hostName() . ' AS hostname
               FROM agent_check_groups acg
               JOIN agent_checks c ON c.id = acg.check_id
               LEFT JOIN ' . Db::collector('hosts') . ' h ON h.id = c.host_id ' . Db::hostMetaJoin() . '
              WHERE acg.group_id = ? ORDER BY hostname, c.name',
            [$groupId]
        );
    }

    /** @return list<int> group ids of a check */
    public static function idsForCheck(int $checkId): array
    {
        return array_map('intval', array_column(
            Db::all('SELECT group_id FROM agent_check_groups WHERE check_id = ?', [$checkId]), 'group_id'));
    }

    /** @return array<int,list<string>> check id => group names, for the checks of a host */
    public static function namesByCheck(int $hostId): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT acg.check_id, g.name FROM agent_check_groups acg
               JOIN service_group_defs g ON g.id = acg.group_id
               JOIN agent_checks c ON c.id = acg.check_id
              WHERE c.host_id = ? ORDER BY g.name', [$hostId]) as $r) {
            $out[(int) $r['check_id']][] = $r['name'];
        }
        return $out;
    }

    /** Replaces the groups of a check; unknown ids are ignored. */
    public static function setForCheck(int $checkId, array $groupIds): void
    {
        $valid = array_flip(array_map('intval', array_column(self::all(), 'id')));
        Db::exec('DELETE FROM agent_check_groups WHERE check_id = ?', [$checkId]);
        foreach (array_unique(array_map('intval', $groupIds)) as $gid) {
            if (isset($valid[$gid])) {
                Db::exec('INSERT INTO agent_check_groups (check_id, group_id) VALUES (?, ?)', [$checkId, $gid]);
            }
        }
    }

    /** Group names for submitted ids (for validation before the check exists). */
    public static function namesFor(array $groupIds): array
    {
        $ids = array_flip(array_map('intval', $groupIds));
        return array_values(array_map(static fn ($g) => $g['name'],
            array_filter(self::all(), static fn ($g) => isset($ids[(int) $g['id']]))));
    }

    /** @return array<string,string> field => error */
    public static function validate(string $name, string $description, ?int $id = null): array
    {
        $errors = [];
        if (!preg_match(self::NAME_PATTERN, $name)) {
            $errors['name'] = 'Letters, digits, dot, dash or underscore (max 64), no spaces: Monit accepts nothing else.';
        } else {
            $other = Db::one('SELECT id FROM service_group_defs WHERE name = ?', [$name]);
            if ($other !== null && (int) $other['id'] !== $id) {
                $errors['name'] = 'A group with this name already exists.';
            }
        }
        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'At most 1000 characters.';
        }
        return $errors;
    }

    /**
     * Configuration with "group <name>" lines added after every check statement
     * of the snippet (a snippet normally defines one check).
     */
    public static function apply(string $config, array $names): string
    {
        if ($names === []) {
            return $config;
        }
        $lines = '';
        foreach ($names as $n) {
            $lines .= "    group $n\n";
        }
        $out = preg_replace(
            '/^(\s*check\s+(?:system|process|file|fifo|filesystem|directory|host|network|program)\s+[^\n]*\n)/mi',
            '$1' . str_replace('$', '\$', $lines),
            $config
        );
        return $out ?? $config;
    }
}
