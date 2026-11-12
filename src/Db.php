<?php

declare(strict_types=1);

namespace Bmon\Web;

use PDO;

/** Thin PDO wrapper. All DATETIME values are stored in UTC, like bmon-collector. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                (string) Config::get('db.dsn'),
                (string) Config::get('db.user'),
                (string) Config::get('db.password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]
            );
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Executes a statement and returns the number of affected rows. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::exec($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Name of a host as shown by Bmon-web: the display name set in Bmon-web
     * (host_meta), or the name Monit sends. Use with hostMetaJoin() on the same alias.
     */
    public static function hostName(string $alias = 'h'): string
    {
        return "COALESCE(NULLIF(hm.display_name, ''), $alias.hostname)";
    }

    public static function hostMetaJoin(string $alias = 'h'): string
    {
        return "LEFT JOIN host_meta hm ON hm.host_id = $alias.id";
    }

    /**
     * A host of the collector with the Bmon-web data: hostname = displayed name,
     * monit_hostname = the name Monit sends, display_name, notes, ...
     */
    public static function host(int $id): ?array
    {
        $row = self::one(
            'SELECT h.*, h.hostname AS monit_hostname, ' . self::hostName() . ' AS display_hostname,
                    hm.display_name, hm.notes, hm.name_updated_at, hm.name_updated_by, hm.notes_updated_at, hm.notes_updated_by
               FROM ' . self::collector('hosts') . ' h ' . self::hostMetaJoin() . ' WHERE h.id = ?',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $row['hostname'] = $row['display_hostname'];
        unset($row['display_hostname']);
        return $row;
    }

    /** Fully qualified name of a bmon-collector table, e.g. `bmon_collector`.`hosts`. */
    public static function collector(string $table): string
    {
        return '`' . Config::get('collector_db') . '`.`' . $table . '`';
    }
}
