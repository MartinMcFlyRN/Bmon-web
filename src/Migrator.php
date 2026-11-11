<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Applies migrations/NNN_name.sql files in order, once each. */
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
        $files = glob(BMON_ROOT . '/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (isset($done[$version])) {
                continue;
            }
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $pdo->exec($sql);   // DDL auto-commits in MySQL: keep migrations idempotent
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
        foreach (glob(BMON_ROOT . '/migrations/*.sql') ?: [] as $file) {
            if (!isset($done[basename($file, '.sql')])) {
                $pending[] = basename($file, '.sql');
            }
        }
        return $pending;
    }

    /** Splits on ';' at end of line and drops '--' comment lines. */
    private static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", $sql), static fn ($l) => !str_starts_with(ltrim($l), '--'));
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }
}
