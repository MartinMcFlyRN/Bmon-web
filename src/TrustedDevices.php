<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * "Remember this computer for N days" after a two-factor code: a random cookie
 * whose SHA-256 is kept in trusted_devices. On that browser the next sign-ins
 * ask only for the password (the cookie alone never signs in). A device is
 * forgotten when it expires, when the user's session_version changes (password
 * change, two-factor reset, account disabled or role changed), or when it is
 * revoked from My account or Admin → Users.
 */
final class TrustedDevices
{
    public const COOKIE = 'bmon_device';

    public static function days(): int
    {
        return max(0, (int) Config::get('security.remember_device_days', 30));
    }

    /** Remembers the current browser for a user (after a right code). */
    public static function remember(array $user): void
    {
        $days = self::days();
        if ($days === 0) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $expires = time() + $days * 86400;
        Db::exec(
            'INSERT INTO trusted_devices (user_id, token_hash, user_sv, created_at, expires_at, ip, last_ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $user['id'], hash('sha256', $token), (int) $user['session_version'], now_utc(), gmdate('Y-m-d H:i:s', $expires),
                client_ip(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]
        );
        self::setCookie($token, $expires);
        if (random_int(1, 20) === 1) {
            Db::exec('DELETE FROM trusted_devices WHERE expires_at < ?', [now_utc()]);
        }
    }

    /** The remembered device of this browser for a user, or null. */
    public static function current(array $user): ?array
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (self::days() === 0 || !is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $d = Db::one('SELECT * FROM trusted_devices WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($d === null || (int) $d['user_id'] !== (int) $user['id'] || (int) $d['user_sv'] !== (int) $user['session_version']
            || strtotime($d['expires_at'] . ' UTC') < time() || $user['totp_enabled_at'] === null) {
            return null;
        }
        return $d;
    }

    public static function touch(int $id): void
    {
        Db::exec('UPDATE trusted_devices SET last_used_at = ?, last_ip = ? WHERE id = ?', [now_utc(), client_ip(), $id]);
    }

    /** Still valid devices of a user, newest first. */
    public static function forUser(array $user): array
    {
        return Db::all(
            'SELECT * FROM trusted_devices WHERE user_id = ? AND user_sv = ? AND expires_at > ? ORDER BY COALESCE(last_used_at, created_at) DESC',
            [(int) $user['id'], (int) $user['session_version'], now_utc()]
        );
    }

    public static function revoke(int $userId, int $id): bool
    {
        return Db::exec('DELETE FROM trusted_devices WHERE id = ? AND user_id = ?', [$id, $userId]) === 1;
    }

    public static function revokeAll(int $userId): int
    {
        return Db::exec('DELETE FROM trusted_devices WHERE user_id = ?', [$userId]);
    }

    /** Id of the device of this browser (to mark it in the list), or null. */
    public static function currentId(): ?int
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        $id = Db::value('SELECT id FROM trusted_devices WHERE token_hash = ?', [hash('sha256', $token)]);
        return $id === null ? null : (int) $id;
    }

    /** "Firefox on Linux"-like label of a user agent. */
    public static function label(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS X') => 'macOS', str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };
        return $browser . ($os !== '' ? " on $os" : '');
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => rtrim((string) Config::get('app.base_path'), '/') . '/',
            'secure' => (bool) Config::get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
