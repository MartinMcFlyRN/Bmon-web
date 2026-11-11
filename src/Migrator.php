<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Applies migrations/NNN_name.sql and NNN_name.php files in order, once each.
 * A .php migration returns a callable receiving the PDO connection; use it when
 * a change must look at the current schema to be safely re-run.
 */
final class Migrator
{
    /** @return list<string> versions applied in this run */
    public static function run(): array
    {
        $pdo = Db::pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at DATETIME    NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $done = array_flip(array_column(Db::all('SELECT version FROM schema_migrations'), 'version'));
        $files = self::files();

        $applied = [];
        foreach ($files as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            if (isset($done[$version])) {
                continue;
            }
            if (str_ends_with($file, '.php')) {
                $fn = require $file;
                $fn($pdo);
            } else {
                foreach (self::statements((string) file_get_contents($file)) as $sql) {
                    $pdo->exec($sql);   // DDL auto-commits in MySQL: keep migrations idempotent
                }
            }
            Db::exec('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    public static function pending(): array
    {
        try {
            $done = array_flip(array_column(Db::all('SELECT version FROM schema_migrations'), 'version'));
        } catch (\PDOException) {
            $done = [];
        }
        $pending = [];
        foreach (self::files() as $file) {
            if (!isset($done[pathinfo($file, PATHINFO_FILENAME)])) {
                $pending[] = pathinfo($file, PATHINFO_FILENAME);
            }
        }
        return $pending;
    }

    private static function files(): array
    {
        $files = array_merge(glob(BMON_ROOT . '/migrations/*.sql') ?: [], glob(BMON_ROOT . '/migrations/*.php') ?: []);
        usort($files, static fn ($a, $b) => strcmp(basename($a), basename($b)));
        return $files;
    }

    /** Splits on ';' at end of line and drops '--' comment lines. */
    private static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), static fn ($l) => !str_starts_with(ltrim($l), '--'));
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
