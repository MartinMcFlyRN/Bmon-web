<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * AgentConf → Group Checks: named sets of checks (rows of agent_checks with
 * host_id = -<group id>, edited like the checks of an agent). "Add group checks"
 * on an agent copies them into it in one go: the copies are independent, so
 * changing the group later does not change the agents.
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
                    (SELECT COALESCE(SUM(c.enabled), 0) FROM agent_checks c WHERE c.host_id = -g.id) AS enabled
               FROM check_groups g ORDER BY g.name'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM check_groups WHERE id = ?', [$id]);
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

    /**
     * Checks of a group that clash with the agent: same name, or same script
     * file name, as one of its checks. name => reason.
     */
    public static function clashes(int $groupId, int $hostId): array
    {
        $own = array_column(AgentChecks::forHost($hostId), null, 'name');
        $scripts = [];
        foreach ($own as $c) {
            if ($c['script_name'] !== '') {
                $scripts[$c['script_name']] = $c['name'];
            }
        }
        $out = [];
        foreach (AgentChecks::forHost(self::sid($groupId)) as $c) {
            if (isset($own[$c['name']])) {
                $out[$c['name']] = 'the agent already has a check with this name';
            } elseif ($c['script_name'] !== '' && isset($scripts[$c['script_name']])) {
                $out[$c['name']] = "check {$scripts[$c['script_name']]} of the agent already has the script {$c['script_name']}";
            }
        }
        return $out;
    }

    /**
     * Copies all the checks of a group into an agent (same name, enabled state,
     * script and service groups), as independent checks. All or nothing: if one
     * clashes with the agent, nothing is copied.
     * @return array{added:list<string>,clashes:array<string,string>}
     */
    public static function copyToHost(int $groupId, int $hostId, string $by): array
    {
        $clashes = self::clashes($groupId, $hostId);
        if ($clashes) {
            return ['added' => [], 'clashes' => $clashes];
        }
        $added = [];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach (AgentChecks::forHost(self::sid($groupId)) as $c) {
                $id = AgentChecks::create($hostId, $c['name'], $c['template'], $c['config'], (bool) (int) $c['enabled'], $by);
                if ($c['script_name'] !== '') {
                    AgentChecks::setScript($id, $c['script_name'], (string) $c['script']);
                }
                ServiceGroups::setForCheck($id, ServiceGroups::idsForCheck((int) $c['id']));
                $added[] = $c['name'];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['added' => $added, 'clashes' => []];
    }

    /** A free internal slug for a new group. */
    public static function freeSlug(string $name, ?int $id = null): string
    {
        $base = self::slugify($name);
        $slug = $base;
        for ($i = 2; ($o = Db::one('SELECT id FROM check_groups WHERE slug = ?', [$slug])) && (int) $o['id'] !== $id; $i++) {
            $slug = substr($base, 0, 28) . '-' . $i;
        }
        return $slug;
    }

    /** Deletes the group, its checks (and their service groups) and its assignments. */
    public static function delete(int $id): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM agent_checks WHERE host_id = ?', [self::sid($id)]);
            Db::exec('DELETE FROM check_groups WHERE id = ?', [$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }



}
