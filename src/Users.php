<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Repository and validation rules for the users table. */
final class Users
{
    public const ROLES = ['admin' => 'Administrator', 'user' => 'User'];

    private const COLUMNS = 'id, username, full_name, email, password_hash, role, is_active,
        session_version, last_login_at, last_login_ip, created_at, updated_at, totp_secret, totp_enabled_at, totp_last_step';

    public static function find(int $id): ?array
    {
        return Db::one('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$id]);
    }

    public static function findByUsername(string $username): ?array
    {
        return Db::one('SELECT ' . self::COLUMNS . ' FROM users WHERE username = ?', [$username]);
    }

    public static function all(): array
    {
        return Db::all('SELECT ' . self::COLUMNS . ' FROM users ORDER BY username');
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM users');
    }

    public static function countActiveAdmins(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
    }

    public static function create(string $username, string $password, string $role, string $fullName = '', string $email = '', bool $active = true): int
    {
        $now = now_utc();
        return Db::insert(
            'INSERT INTO users (username, full_name, email, password_hash, role, is_active, session_version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$username, $fullName, $email, Password::hash($password), $role, $active ? 1 : 0, $now, $now]
        );
    }

    /** Updates profile fields. Disabling or changing the role ends the user's sessions. */
    public static function update(int $id, array $fields): void
    {
        $allowed = ['username', 'full_name', 'email', 'role', 'is_active'];
        $current = self::find($id);
        if ($current === null) {
            return;
        }
        $set = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[] = "$col = ?";
                $params[] = $fields[$col];
            }
        }
        $endSessions = (isset($fields['role']) && $fields['role'] !== $current['role'])
            || (isset($fields['is_active']) && (int) $fields['is_active'] === 0 && (int) $current['is_active'] === 1);
        if ($endSessions) {
            $set[] = 'session_version = session_version + 1';
        }
        if ($set === []) {
            return;
        }
        $set[] = 'updated_at = ?';
        $params[] = now_utc();
        $params[] = $id;
        Db::exec('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
    }

    /** Sets a new password and invalidates every existing session of the user. */
    public static function setPassword(int $id, string $password): void
    {
        Db::exec(
            'UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
            [Password::hash($password), now_utc(), $id]
        );
    }

    /** Transparent rehash after login (algorithm or cost change); sessions stay valid. */
    public static function rehash(int $id, string $password): void
    {
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [Password::hash($password), $id]);
    }

    /** Two-factor set up: the sealed secret and the step of the code that confirmed it. */
    public static function setTotp(int $id, string $secret, int $step): void
    {
        Db::exec('UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ?, updated_at = ? WHERE id = ?',
            [Secrets::seal($secret, "totp-$id"), now_utc(), $step, now_utc(), $id]);
    }

    /** Records the step of an accepted code (a code is never accepted twice). */
    public static function setTotpStep(int $id, int $step): void
    {
        Db::exec('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?', [$step, $id, $step]);
    }

    /** Two-factor reset: set up again at the next sign-in; the user's sessions end. */
    public static function resetTotp(int $id): void
    {
        Db::exec('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = 0,
                         session_version = session_version + 1, updated_at = ? WHERE id = ?', [now_utc(), $id]);
    }

    public static function totpSecret(array $user): ?string
    {
        return $user['totp_secret'] !== null && $user['totp_enabled_at'] !== null
            ? Secrets::open((string) $user['totp_secret'], 'totp-' . $user['id']) : null;
    }

    public static function recordLogin(int $id, string $ip): void
    {
        Db::exec('UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ?', [now_utc(), $ip, $id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM users WHERE id = ?', [$id]);
    }

    // --- validation -------------------------------------------------------

    /** @return array<string,string> field => error message */
    public static function validate(array $in, ?int $existingId = null): array
    {
        $errors = [];
        $username = $in['username'] ?? '';
        if (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $username)) {
            $errors['username'] = 'Use 2-64 characters: letters, digits, dot, dash, underscore or @.';
        } else {
            $other = self::findByUsername($username);
            if ($other !== null && (int) $other['id'] !== $existingId) {
                $errors['username'] = 'This username is already taken.';
            }
        }
        if (mb_strlen($in['full_name'] ?? '') > 255) {
            $errors['full_name'] = 'At most 255 characters.';
        }
        $email = $in['email'] ?? '';
        if ($email !== '' && (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Not a valid e-mail address.';
        }
        if (isset($in['role']) && !array_key_exists($in['role'], self::ROLES)) {
            $errors['role'] = 'Unknown role.';
        }
        return $errors;
    }
}
