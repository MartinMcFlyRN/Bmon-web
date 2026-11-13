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
        $r = self::request($target . '/_doaction', [$user, $password], 'POST',
            http_build_query(['securitytoken' => $token, 'service' => $service, 'action' => $action]),
            ["Cookie: securitytoken=$token", 'Content-Type: application/x-www-form-urlencoded'],
            (float) Config::get('monit.timeout', 10));

        if ($r['error'] !== null) {
            return self::fail("Cannot reach Monit at $target: {$r['error']}", $target);
        }
        return match (true) {
            $r['status'] === 302 || $r['status'] === 200 => ['ok' => true, 'message' => '', 'target' => $target],
            $r['status'] === 401 => self::fail('Monit rejected the credentials (did they change? the next report will update them).', $target),
            $r['status'] === 403 => self::fail("Monit refused the request: add this server's address to the 'allow' list of 'set httpd' in monitrc.", $target),
            default => self::fail("Monit answered {$r['status']}: " . self::errorText($r['body']), $target),
        };
    }

    /**
     * Checks that Monit httpd answers (GET /_ping, "pong" with valid credentials).
     * @return array{state:string,label:string,detail:string,target:string}
     *         state: ok | fail | unknown
     */
    public static function ping(array $host): array
    {
        try {
            $target = self::target($host);
        } catch (\RuntimeException $e) {
            return ['state' => 'unknown', 'label' => 'Not available', 'detail' => $e->getMessage(), 'target' => ''];
        }
        $creds = null;
        $credsNote = '';
        try {
            $creds = self::credentials($host);
        } catch (\RuntimeException $e) {
            $credsNote = $e->getMessage();   // still check that the port answers
        }

        $r = self::request($target . '/_ping', $creds, 'GET', '', [], 4.0);
        $res = static fn (string $state, string $label, string $detail = '') => [
            'state' => $state, 'label' => $label, 'detail' => $detail, 'target' => $target,
        ];
        if ($r['error'] !== null) {
            return $res('fail', 'Unreachable', $r['error']);
        }
        return match (true) {
            $r['status'] === 200 && $creds !== null => $res('ok', 'Reachable', 'Monit httpd answered and accepted the credentials.'),
            $r['status'] === 401 && $creds === null => $res('ok', 'Reachable', 'Port open, credentials not verified: ' . $credsNote),
            $r['status'] === 401 => $res('fail', 'Credentials refused', 'Monit httpd answered but refused the stored credentials.'),
            $r['status'] === 403 => $res('fail', 'Not allowed', "Monit httpd answered but this server's address is not in its 'allow' list."),
            default => $res('fail', 'Error ' . $r['status'], self::errorText($r['body'])),
        };
    }

    /**
     * One HTTP request to Monit httpd.
     * @param array{0:string,1:string}|null $auth
     * @return array{status:int,body:string,error:?string}
     */
    private static function request(string $url, ?array $auth, string $method, string $body, array $headers, float $timeout): array
    {
        if ($auth !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($auth[0] . ':' . $auth[1]);
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Length: ' . strlen($body);
        }
        $headers[] = 'User-Agent: Bmon-web/' . BMON_WEB_VERSION;
        $headers[] = 'Connection: close';
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => self::sslOptions(),
        ]);

        $http_response_header = [];
        $errs = [];
        set_error_handler(static function (int $no, string $msg) use (&$errs): bool {
            $errs[] = $msg;   // OpenSSL reports the real cause before the generic "failed to open stream"
            return true;
        });
        try {
            $response = file_get_contents($url, false, $ctx);
        } finally {
            restore_error_handler();
        }
        if ($response === false && $http_response_header === []) {
            return ['status' => 0, 'body' => '', 'error' => self::friendlyError($errs === [] ? 'no response' : implode(' | ', $errs), $url)];
        }
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        return ['status' => $status, 'body' => (string) $response, 'error' => null];
    }

    /** Base URL of the host's Monit httpd, e.g. https://192.0.2.10:2812 */
    public static function target(array $host): string
    {
        $port = (int) $host['httpd_port'];
        if ($port <= 0 || $port > 65535) {
            throw new \RuntimeException('Monit httpd is not enabled on this host (no "set httpd" in monitrc).');
        }
        if (!(int) $host['httpd_ssl'] && Config::get('monit.require_tls')) {
            throw new \RuntimeException('Monit httpd on this host does not use HTTPS, and HTTPS is required (Admin → Configuration): '
                . 'add "with ssl { pemfile: … }" to "set httpd" in its monitrc.');
        }
        // address set in Bmon-web (e.g. the public IP instead of the private one Monit reports)
        $addr = trim((string) ($host['monit_address'] ?? ''));
        if ($addr !== '') {
            $port = (int) ($host['monit_port'] ?? 0) ?: $port;
        } else {
            $addr = self::reportedAddress($host);
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

    /**
     * Address Bmon-web would use from the reports alone: the httpd address, or the
     * address the reports come from when httpd listens on all interfaces or loopback.
     */
    public static function reportedAddress(array $host): string
    {
        $addr = trim((string) $host['httpd_address']);
        if ($addr === '' || in_array($addr, ['0.0.0.0', '::', '*'], true) || self::isLoopback($addr)) {
            $addr = trim((string) $host['remote_addr']);
        }
        return $addr;
    }

    /** Validation of an address typed in Bmon-web (IP or host name). */
    public static function addressError(string $addr): ?string
    {
        if ($addr === '') {
            return null;
        }
        if (filter_var($addr, FILTER_VALIDATE_IP)
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $addr)) {
            return null;
        }
        return 'Enter an IP address or a host name (without http:// or port).';
    }

    /**
     * TLS options for Monit httpd: with monit.tls_verify, the certificate must be
     * issued by monit.ca_cert (or by a CA of this server when it is empty) and,
     * with monit.tls_verify_name, name the address Bmon connects to.
     */
    public static function sslOptions(): array
    {
        if (!Config::get('monit.tls_verify')) {
            return ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'SNI_enabled' => true];
        }
        $opts = [
            'verify_peer' => true,
            'verify_peer_name' => (bool) Config::get('monit.tls_verify_name', true),
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        if (($ca = self::caFile()) !== null) {
            $opts['cafile'] = $ca;
            $opts['capath'] = '/nonexistent';   // only this CA, not the system ones
        }
        return $opts;
    }

    /** monit.ca_cert written to a file (OpenSSL needs a path); null if not set. */
    public static function caFile(): ?string
    {
        $pem = trim((string) Config::get('monit.ca_cert', ''));
        if ($pem === '') {
            return null;
        }
        $file = sys_get_temp_dir() . '/bmon-web-monit-ca-' . hash('sha256', $pem) . '.pem';
        if (!is_file($file)) {
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, $pem . "\n");
            chmod($tmp, 0644);
            rename($tmp, $file);
        }
        return $file;
    }

    /** One line per certificate of a PEM bundle: subject, validity, fingerprint. */
    public static function caSummary(string $pem): array
    {
        $out = [];
        preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $m);
        foreach ($m[0] as $c) {
            $info = @openssl_x509_parse($c);
            if ($info === false) {
                continue;
            }
            $cn = $info['subject']['CN'] ?? ($info['subject']['O'] ?? '?');
            $cn = is_array($cn) ? implode(', ', $cn) : $cn;
            $fp = strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($c, 'sha256'), 2)));
            $out[] = "CN=$cn · valid until " . gmdate('Y-m-d', (int) $info['validTo_time_t'])
                . (empty($info['extensions']['basicConstraints']) || !str_contains($info['extensions']['basicConstraints'], 'CA:TRUE') ? ' · NOT a CA certificate' : '')
                . ' · SHA-256 ' . substr($fp, 0, 23) . '…';
        }
        return $out;
    }

    /** Readable reason from PHP/OpenSSL stream errors. */
    private static function friendlyError(string $err, string $url = ''): string
    {
        $last = $err;
        if (($p = strrpos($err, ' | ')) !== false) {
            $last = substr($err, $p + 3);
        }
        return match (true) {
            str_contains($err, 'certificate verify failed') =>
                'the certificate of Monit httpd is not issued by the configured CA (or it has expired)',
            (bool) preg_match('/Peer certificate CN=`([^\']*)\' did not match expected CN=`([^\']*)\'/', $err, $m) =>
                "the certificate of Monit httpd is for “{$m[1]}”, not for “{$m[2]}”: add that address to its subjectAltName, "
                . 'or turn off the name check in Admin → Configuration',
            (bool) preg_match('/did not match expected (?:CN|name)=?\s*`([^\']*)\'/', $err, $m) =>
                "the certificate of Monit httpd is not valid for “{$m[1]}”: add that address to its subjectAltName, "
                . 'or turn off the name check in Admin → Configuration',
            str_contains($err, 'wrong version number') || str_contains($err, 'record layer failure') || str_contains($err, 'packet length too long') =>
                'Monit httpd does not speak HTTPS on this port (SSL not enabled in monitrc?)',
            str_starts_with($url, 'http://') && str_contains($err, 'HTTP request failed') =>
                'no HTTP answer: if Monit httpd uses SSL, Bmon will switch to HTTPS after its next report',
            default => preg_replace('/^.*?failed to open stream: /i', '', $last),
        };
    }

    /** Whether actions can be attempted at all (for showing the controls). */
    public static function available(array $host): bool
    {
        return (int) $host['httpd_port'] > 0 && ($host['httpd_password'] ?? '') !== '';
    }

    /** @return array{0:string,1:string} */
    public static function credentials(array $host): array
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
