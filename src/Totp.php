<?php

declare(strict_types=1);

namespace Bmon\Web;

/** Time-based one-time passwords (RFC 6238: SHA-1, 6 digits, 30 s), as used by authenticator apps. */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new secret: 160 random bits, base32. */
    public static function newSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function unbase32(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=]/', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $v = strpos(self::B32, $c);
            if ($v === false) {
                throw new \InvalidArgumentException('Invalid base32.');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::unbase32($secret), true);
        $o = ord($h[19]) & 0x0F;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Checks a code within ±1 step (clock skew) and newer than $lastStep (a code
     * cannot be used twice). Returns the matching step, or null.
     */
    public static function verify(string $secret, string $code, int $lastStep = 0, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $step = intdiv($now ?? time(), self::PERIOD);
        foreach ([$step - 1, $step, $step + 1] as $s) {
            if ($s > $lastStep && hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }
        return null;
    }

    /** otpauth:// URI for the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }
}
