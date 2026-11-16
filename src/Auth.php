<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Session-based authentication.
 *
 * The session stores the user id and the user's session_version. Every request
 * reloads the user and compares the version: changing the password, the role
 * or disabling the account bumps it and ends all of that user's sessions.
 *
 * Signing in takes two steps: the password (attempt) and a TOTP code
 * (secondFactor), or setting up the authenticator app at the first sign-in.
 * Between them the session only holds a "pending" user, valid for 5 minutes and
 * 5 codes; a full session is marked "mfa".
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

        if (($_SESSION['mfa'] ?? false) !== true) {
            // session opened before two-factor authentication was required
            Session::reset();
            flash('info', 'Two-factor authentication is now required: sign in again.');
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

        if (Password::needsRehash($user['password_hash'])) {
            Users::rehash((int) $user['id'], $password);
        }
        // a computer remembered after a code: the password is enough
        if (($device = TrustedDevices::current($user)) !== null) {
            TrustedDevices::touch((int) $device['id']);
            RateLimiter::record($username, $ip, true);
            Users::recordLogin((int) $user['id'], $ip);
            self::login($user);
            Audit::log('login.success', 'remembered computer (two-factor skipped until ' . substr($device['expires_at'], 0, 10) . ')', $user);
            return null;
        }
        // password right: the second factor completes the sign-in
        Session::reset();
        $_SESSION['mfa_uid'] = (int) $user['id'];
        $_SESSION['mfa_sv'] = (int) $user['session_version'];
        $_SESSION['mfa_time'] = time();
        $_SESSION['mfa_tries'] = 0;
        Audit::log('login.password_ok', $user['totp_enabled_at'] !== null ? 'code requested' : 'two-factor set-up requested', $user);
        return null;
    }

    private const PENDING_TTL = 300;
    private const PENDING_TRIES = 5;

    /** The user between the password and the code, or null. */
    public static function pending(): ?array
    {
        $uid = $_SESSION['mfa_uid'] ?? null;
        if (!is_int($uid) || time() - (int) ($_SESSION['mfa_time'] ?? 0) > self::PENDING_TTL) {
            return null;
        }
        $user = Users::find($uid);
        return $user !== null && (int) $user['is_active'] && (int) $user['session_version'] === (int) ($_SESSION['mfa_sv'] ?? -1)
            ? $user : null;
    }

    /** Secret being set up by the pending user (kept in the session until confirmed). */
    public static function pendingSecret(): string
    {
        if (!is_string($_SESSION['mfa_new'] ?? null)) {
            $_SESSION['mfa_new'] = Totp::newSecret();
        }
        return $_SESSION['mfa_new'];
    }

    public static function cancelPending(): void
    {
        unset($_SESSION['mfa_uid'], $_SESSION['mfa_sv'], $_SESSION['mfa_time'], $_SESSION['mfa_tries'], $_SESSION['mfa_new']);
    }

    /**
     * Second step: a code of the authenticator app (or the first one, to confirm
     * the set-up). On success the user is signed in.
     * @return string|null error message, null on success
     */
    public static function secondFactor(string $code, bool $remember = false): ?string
    {
        $user = self::pending() ?? throw new \RuntimeException('No sign-in in progress.');
        $ip = client_ip();
        if (RateLimiter::isBlocked($user['username'], $ip)) {
            self::cancelPending();
            Audit::log('login.throttled', 'two-factor', $user);
            return 'Too many failed attempts. Wait a few minutes and sign in again.';
        }
        $setup = $user['totp_enabled_at'] === null;
        $secret = $setup ? self::pendingSecret() : Users::totpSecret($user);
        $step = Totp::verify((string) $secret, $code, $setup ? 0 : (int) $user['totp_last_step']);
        if ($step === null) {
            RateLimiter::record($user['username'], $ip, false);
            Audit::log('login.failed', $setup ? 'two-factor set-up: wrong code' : 'wrong two-factor code', $user);
            if (++$_SESSION['mfa_tries'] >= self::PENDING_TRIES) {
                self::cancelPending();
                return 'Too many wrong codes: sign in again.';
            }
            return 'Wrong code. Check that the time of your phone is right, and use the current code.';
        }
        if ($setup) {
            Users::setTotp((int) $user['id'], (string) $secret, $step);
            Audit::log('user.2fa_enabled', '', $user);
        } else {
            Users::setTotpStep((int) $user['id'], $step);
        }
        RateLimiter::record($user['username'], $ip, true);
        Users::recordLogin((int) $user['id'], $ip);
        self::login(Users::find((int) $user['id']));
        if ($remember) {
            TrustedDevices::remember(self::$user);
        }
        Audit::log('login.success', ($setup ? 'two-factor set up' : 'two-factor')
            . ($remember && TrustedDevices::days() > 0 ? ', computer remembered for ' . TrustedDevices::days() . ' days' : ''), self::$user);
        return null;
    }

    public static function login(array $user): void
    {
        Session::reset();
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['sv'] = (int) $user['session_version'];
        $_SESSION['login_time'] = $_SESSION['last_activity'] = time();
        $_SESSION['mfa'] = true;
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
