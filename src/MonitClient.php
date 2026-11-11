<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Sends service actions to the Monit agent of a host, through Monit's httpd
 * (the same request the `monit` command line and M/Monit make):
 *
 *   POST http(s)://<address>:<port>/_doaction
 *   Authorization: Basic <httpd credentials>
 *   Cookie: securitytoken=<t>      body: securitytoken=<t>&service=<name>&action=<action>
 *
 * Monit answers 302 when the action is accepted; it then runs it in the
 * background and reports the result with the next report.
 *
 * Address, port, SSL and credentials come from the reports stored by
 * bmon-collector (hosts.httpd_*). The password is decrypted with
 * monit.credentials_key (same key as BMON_CREDENTIALS_KEY of the collector).
 */
final class MonitClient
{
    public const ACTIONS = [
        'start' => 'Start', 'stop' => 'Stop', 'restart' => 'Restart',
        'monitor' => 'Monitor', 'unmonitor' => 'Unmonitor',
    ];

    /** @return array{ok:bool,message:string,target:string} */
    public static function action(array $host, string $service, string $action): array
    {
        if (!array_key_exists($action, self::ACTIONS)) {
            return self::fail('Unknown action.');
        }
        try {
            $target = self::target($host);
            [$user, $password] = self::credentials($host);
        } catch (\RuntimeException $e) {
            return self::fail($e->getMessage());
        }

        $token = bin2hex(random_bytes(16));
        $body = http_build_query(['securitytoken' => $token, 'service' => $service, 'action' => $action]);
        $verify = (bool) Config::get('monit.tls_verify');
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Basic ' . base64_encode("$user:$password"),
                    "Cookie: securitytoken=$token",
                    'Content-Type: application/x-www-form-urlencoded',
                    'Content-Length: ' . strlen($body),
                    'User-Agent: Bmon-web/' . BMON_WEB_VERSION,
                    'Connection: close',
                ]),
                'content' => $body,
                'timeout' => (float) Config::get('monit.timeout', 10),
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify,
            ],
        ]);

        $url = $target . '/_doaction';
        $http_response_header = [];
        $err = 'no response';
        set_error_handler(static function (int $no, string $msg) use (&$err): bool {
            $err = $msg;
            return true;
        });
        try {
            $response = file_get_contents($url, false, $ctx);
        } finally {
            restore_error_handler();
        }
        if ($response === false && $http_response_header === []) {
            $err = preg_replace('/^.*?(failed to open stream|Failed to open stream): /i', '', $err);
            return self::fail("Cannot reach Monit at $target: $err", $target);
        }

        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m)) {
            $status = (int) $m[1];
        }
        return match (true) {
            $status === 302 || $status === 200 => ['ok' => true, 'message' => '', 'target' => $target],
            $status === 401 => self::fail('Monit rejected the credentials (did they change? the next report will update them).', $target),
            $status === 403 => self::fail("Monit refused the request: add this server's address to the 'allow' list of 'set httpd' in monitrc.", $target),
            default => self::fail("Monit answered $status: " . self::errorText((string) $response), $target),
        };
    }

    /** Base URL of the host's Monit httpd, e.g. https://192.0.2.10:2812 */
    public static function target(array $host): string
    {
        $port = (int) $host['httpd_port'];
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException('Monit httpd is not enabled on this host (no "set httpd" in monitrc).');
        }
        $addr = trim((string) $host['httpd_address']);
        // httpd on all interfaces or on loopback: use the address the reports come from
        if ($addr === '' || in_array($addr, ['0.0.0.0', '::', '*'], true) || self::isLoopback($addr)) {
            $addr = trim((string) $host['remote_addr']);
        }
        if ($addr === '') {
            throw new \RuntimeException('The address of Monit httpd is not known.');
        }
        if (!filter_var($addr, FILTER_VALIDATE_IP)
            && !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $addr)) {
            throw new \RuntimeException('Invalid Monit httpd address: ' . $addr);
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $addr = "[$addr]";
        }
        return ((int) $host['httpd_ssl'] ? 'https' : 'http') . "://$addr:$port";
    }

    /** Whether actions can be attempted at all (for showing the controls). */
    public static function available(array $host): bool
    {
        return (int) $host['httpd_port'] > 0 && ($host['httpd_password'] ?? '') !== '';
    }

    /** @return array{0:string,1:string} */
    private static function credentials(array $host): array
    {
        $user = (string) ($host['httpd_username'] ?? '');
        $stored = (string) ($host['httpd_password'] ?? '');
        if ($stored === '') {
            throw new \RuntimeException('No Monit httpd credentials for this host: remove "and register without credentials" from "set mmonit" in its monitrc.');
        }
        if (str_starts_with($stored, 'plain:')) {
            return [$user, substr($stored, 6)];
        }
        if (!str_starts_with($stored, 'aes256gcm:')) {
            throw new \RuntimeException('Unknown format of the stored Monit password.');
        }
        $key = base64_decode((string) Config::get('monit.credentials_key'), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('The Monit passwords are encrypted: set monit.credentials_key in the Bmon-web configuration (same value as BMON_CREDENTIALS_KEY of the collector).');
        }
        $raw = base64_decode(substr($stored, 10), true);
        if ($raw === false || strlen($raw) < 12 + 16) {
            throw new \RuntimeException('The stored Monit password is malformed.');
        }
        $plain = openssl_decrypt(
            substr($raw, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, -16), (string) $host['monit_id']
        );
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt the Monit password: monit.credentials_key differs from the collector key.');
        }
        return [$user, $plain];
    }

    private static function isLoopback(string $addr): bool
    {
        return $addr === 'localhost' || $addr === '::1' || str_starts_with($addr, '127.');
    }

    /** Monit error pages are HTML: keep the sentence after the title. */
    private static function errorText(string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
        $text = preg_replace('/^(\d{3} )?([A-Z][a-z]+( [A-Z][a-z]+)*) \2 /', '', $text);  // "Bad Request Bad Request ..."
        $text = preg_replace('/ monit [\d.]+$/i', '', $text);
        return mb_strimwidth($text !== '' ? $text : 'no details', 0, 300, '…');
    }

    private static function fail(string $message, string $target = ''): array
    {
        return ['ok' => false, 'message' => $message, 'target' => $target];
    }
}
