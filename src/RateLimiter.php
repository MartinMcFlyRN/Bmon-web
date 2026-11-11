<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Login throttling based on the login_attempts table.
 *
 * Two limits within security.login_window:
 *  - failed attempts from one IP (any username): login_max_per_ip
 *  - failed attempts for one username from one IP: login_max_per_user
 *    (counted since that pair's last successful login)
 *
 * Throttling per username+IP rather than per username alone means an attacker
 * cannot lock a known account (e.g. "admin") out from everywhere.
 */
final class RateLimiter
{
    public static function isBlocked(string $username, string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - (int) Config::get('security.login_window'));

        $perIp = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        if ($perIp >= (int) Config::get('security.login_max_per_ip')) {
            return true;
        }

        $perUser = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE username = ? AND ip = ? AND success = 0
                AND attempted_at > GREATEST(?, COALESCE(
                    (SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND ip = ? AND success = 1),
                    \'1970-01-01\'))',
            [$username, $ip, $since, $username, $ip]
        );
        return $perUser >= (int) Config::get('security.login_max_per_user');
    }

    public static function record(string $username, string $ip, bool $success): void
    {
        Db::exec(
            'INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?, ?, ?, ?)',
            [mb_substr($username, 0, 64), $ip, $success ? 1 : 0, now_utc()]
        );
        if (random_int(1, 100) === 1) {
            Db::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        }
    }
}
