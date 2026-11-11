<?php

declare(strict_types=1);

namespace Bmon\Web;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) Config::get('session.absolute_timeout'));

        session_name((string) Config::get('session.name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim((string) Config::get('app.base_path'), '/') . '/',
            'secure'   => (bool) Config::get('session.secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** New session id, keeping the data (call on every privilege change). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Drops all session data and issues a fresh, empty session. */
    public static function reset(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }
}
