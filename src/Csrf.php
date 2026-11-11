<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Synchronizer-token CSRF protection, checked on every POST by App. */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function valid(): bool
    {
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent) && !empty($_SESSION['_csrf']) && is_string($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $sent);
    }
}
