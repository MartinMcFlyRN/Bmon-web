<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Security-relevant actions (logins, user changes), shown in Admin → Audit log. */
final class Audit
{
    public static function log(string $action, string $detail = '', ?array $actor = null, ?string $username = null): void
    {
        $actor ??= Auth::user();
        try {
            Db::exec(
                'INSERT INTO audit_log (user_id, username, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $actor['id'] ?? null,
                    mb_substr($username ?? ($actor['username'] ?? ''), 0, 64),
                    $action,
                    mb_substr($detail, 0, 1024),
                    PHP_SAPI === 'cli' ? 'cli' : client_ip(),
                    now_utc(),
                ]
            );
        } catch (\Throwable $e) {
            error_log('bmon-web: audit log write failed: ' . $e->getMessage());
        }
    }

    public static function recent(int $limit = 200): array
    {
        return Db::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . max(1, min($limit, 1000)));
    }
}
