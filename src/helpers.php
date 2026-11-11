<?php

declare(strict_types=1);

use Bmon\Web\Config;
use Bmon\Web\Csrf;

/** HTML-escapes a value for output in templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Builds an application URL honouring app.base_path. */
function url(string $path = '/'): string
{
    return rtrim((string) Config::get('app.base_path'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path), true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Returns and clears pending flash messages. */
function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Current UTC time in MySQL DATETIME(3) format. */
function now_utc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
}

/** Formats a UTC DATETIME from the database in the display timezone. */
function fmt_dt(?string $utc, string $format = 'Y-m-d H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone((string) Config::get('app.timezone')))->format($format);
}

/** Time elapsed since a UTC DATETIME, compact: "45s", "12m", "3h 20m", "4d 6h". */
function since(?string $utc): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $s = max(0, time() - (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp());
    return match (true) {
        $s < 60 => $s . 's',
        $s < 3600 => intdiv($s, 60) . 'm',
        $s < 86400 => intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm',
        default => intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h',
    };
}

/** Client IP, honouring X-Forwarded-For / X-Real-IP only from trusted proxies. */
function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted = (array) Config::get('security.trusted_proxies', []);
    if ($remote === '' || !ip_in_cidrs($remote, $trusted)) {
        return $remote;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // walk from the right, skipping our own proxies
        $chain = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !ip_in_cidrs($ip, $trusted)) {
                return $ip;
            }
        }
    }
    $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    return filter_var($real, FILTER_VALIDATE_IP) ? $real : $remote;
}

function ip_in_cidrs(string $ip, array $cidrs): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($cidrs as $cidr) {
        [$net, $bits] = array_pad(explode('/', (string) $cidr, 2), 2, null);
        $netBin = @inet_pton((string) $net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            continue;
        }
        $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            continue;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        if ((($bin[$bytes] ?? "\0") & $mask) === (($netBin[$bytes] ?? "\0") & $mask)) {
            return true;
        }
    }
    return false;
}
