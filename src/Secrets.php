<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Secrets kept in the database (installer private keys and passwords, TOTP
 * seeds): AES-256-GCM with monit.credentials_key, bound to a context string so a
 * value cannot be moved to another row. Without the key they are stored as
 * "plain:…" (and work), with a hint in the pages that use them.
 */
final class Secrets
{
    private static function key(): ?string
    {
        $key = base64_decode((string) Config::get('monit.credentials_key', ''), true);
        return $key !== false && strlen($key) === 32 ? $key : null;
    }

    public static function available(): bool
    {
        return self::key() !== null;
    }

    public static function seal(string $plain, string $context): string
    {
        $key = self::key();
        if ($key === null) {
            return 'plain:' . $plain;
        }
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
        return 'aes256gcm:' . base64_encode($iv . $ct . $tag);
    }

    public static function open(string $stored, string $context): string
    {
        if (str_starts_with($stored, 'plain:')) {
            return substr($stored, 6);
        }
        $key = self::key() ?? throw new \RuntimeException('This secret is encrypted: set monit.credentials_key in the Bmon-web configuration.');
        $raw = base64_decode(substr($stored, 10), true);
        $plain = $raw === false || strlen($raw) < 28 ? false : openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, -16), $context);
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt a secret: monit.credentials_key changed.');
        }
        return $plain;
    }
}
