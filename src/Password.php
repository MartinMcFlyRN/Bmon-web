<?php

declare(strict_types=1);

namespace Bmon\Web;

final class Password
{
    public static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /**
     * Hash checked when the username does not exist, so that both paths cost one
     * verification (no user enumeration by timing). Fixed hashes of random strings,
     * with PHP's default cost parameters: nothing verifies against them.
     */
    public static function dummyHash(): string
    {
        return self::algo() === PASSWORD_DEFAULT
            ? '$2y$10$bfCOx1w5xgfR1IqVm6qMzutQeBHM8U/9JnayqOl34r/yGbChsyuXK'
            : '$argon2id$v=19$m=65536,t=4,p=1$LndnUzBNTEM4NFpIUXVzaA$F8P66M4PKW41CrwgiT+n/y+hXGsGFtBb92GT4jKs/Gs';
    }

    /** Returns an error message, or null if the password is acceptable. */
    public static function policyError(string $password, string $username = ''): ?string
    {
        $min = (int) Config::get('security.password_min_length', 10);
        if (mb_strlen($password) < $min) {
            return "The password must be at least $min characters long.";
        }
        if (strlen($password) > 4096) {
            return 'The password is too long.';
        }
        if ($username !== '' && strcasecmp($password, $username) === 0) {
            return 'The password cannot be the same as the username.';
        }
        return null;
    }
}
