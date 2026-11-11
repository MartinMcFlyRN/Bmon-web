<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Session-based authentication.
 *
 * The session stores the user id and the user's session_version. Every request
 * reloads the user and compares the version: changing the password, the role
 * or disabling the account bumps it and ends all of that user's sessions.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $uid = $_SESSION['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }
        $now = time();
        $idle = (int) Config::get('session.idle_timeout');
        $absolute = (int) Config::get('session.absolute_timeout');
        if ($now - (int) ($_SESSION['last_activity'] ?? 0) > $idle
            || $now - (int) ($_SESSION['login_time'] ?? 0) > $absolute) {
            Session::reset();
            flash('info', 'Your session has expired. Please sign in again.');
            return null;
        }

        $user = Users::find($uid);
        if ($user === null || !(int) $user['is_active']
            || (int) $user['session_version'] !== (int) ($_SESSION['sv'] ?? -1)) {
            Session::reset();
            flash('info', 'Your session has ended. Please sign in again.');
            return null;
        }
        $_SESSION['last_activity'] = $now;
        return self::$user = $user;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? null) === 'admin';
    }

    /** @return string|null error message, null on success */
    public static function attempt(string $username, string $password): ?string
    {
        $ip = client_ip();
        $username = trim($username);

        if (RateLimiter::isBlocked($username, $ip)) {
            Audit::log('login.throttled', '', null, $username);
            return 'Too many failed attempts. Wait a few minutes and try again.';
        }

        $user = $username === '' ? null : Users::findByUsername($username);
        $valid = Password::verify($password, $user['password_hash'] ?? Password::dummyHash()) && $user !== null;

        if (!$valid) {
            RateLimiter::record($username, $ip, false);
            Audit::log('login.failed', $user === null ? 'unknown user' : 'wrong password', null, $username);
            return 'Invalid username or password.';
        }
        if (!(int) $user['is_active']) {
            RateLimiter::record($username, $ip, false);
            Audit::log('login.failed', 'account disabled', $user);
            return 'This account is disabled.';
        }

        RateLimiter::record($username, $ip, true);
        if (Password::needsRehash($user['password_hash'])) {
            Users::rehash((int) $user['id'], $password);
        }
        Users::recordLogin((int) $user['id'], $ip);
        self::login($user);
        Audit::log('login.success', '', $user);
        return null;
    }

    public static function login(array $user): void
    {
        Session::reset();
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['sv'] = (int) $user['session_version'];
        $_SESSION['login_time'] = $_SESSION['last_activity'] = time();
        self::$user = $user;
        self::$loaded = true;
    }

    /** After changing your own password: keep the current session, end the others. */
    public static function refresh(): void
    {
        $user = Users::find((int) ($_SESSION['uid'] ?? 0));
        if ($user !== null) {
            Session::regenerate();
            $_SESSION['sv'] = (int) $user['session_version'];
            self::$user = $user;
        }
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user !== null) {
            Audit::log('logout', '', $user);
        }
        Session::reset();
        self::$user = null;
    }
}
