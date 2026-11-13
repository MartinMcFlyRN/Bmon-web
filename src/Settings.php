<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Settings edited in Admin → Configuration (table `settings`).
 *
 * Precedence: value saved here > config.php > default. A value equal to the
 * inherited one is not stored, so the setting keeps following config.php.
 *
 * Not editable here, on purpose (they stay in config.php): the database
 * connection, collector_db and app.base_path (a wrong value would break the
 * interface used to fix it), app.debug, monit.credentials_key (the key must not
 * sit in the database next to what it protects) and agentconf.monit_binary (a
 * program run by the server).
 */
final class Settings
{
    public const SECTIONS = [
        'general' => 'General',
        'security' => 'Sign-in and sessions',
        'monit' => 'Monit actions',
        'slack' => 'Slack report',
        'agentconf' => 'AgentConf',
    ];

    /** key => definition */
    public static function fields(): array
    {
        $int = static fn (string $section, string $label, int $min, int $max, string $help = '', string $unit = '') =>
            compact('section', 'label', 'min', 'max', 'help', 'unit') + ['type' => 'int'];
        return [
            'app.name' => ['section' => 'general', 'type' => 'text', 'label' => 'Name', 'max' => 50,
                'help' => 'Shown in the header and in the page titles.', 'required' => true],
            'app.url' => ['section' => 'general', 'type' => 'url', 'label' => 'Public URL',
                'help' => 'Address of Bmon-web as the users and the agents reach it, e.g. https://bmon.example.com. Used for the links in the Slack report and for the AgentConf download links.'],
            'app.timezone' => ['section' => 'general', 'type' => 'timezone', 'label' => 'Time zone',
                'help' => 'Times are shown in this zone (the database stores UTC).'],

            'session.secure' => ['section' => 'security', 'type' => 'bool', 'label' => 'Session cookie only over HTTPS',
                'help' => 'Turn off only to use Bmon-web over plain HTTP.'],
            'session.idle_timeout' => $int('security', 'Sign out after inactivity', 60, 604800, '', 'seconds'),
            'session.absolute_timeout' => $int('security', 'Maximum session length', 300, 2592000, '', 'seconds'),
            'security.password_min_length' => $int('security', 'Minimum password length', 8, 128, '', 'characters'),
            'security.login_window' => $int('security', 'Failed sign-in window', 60, 86400, 'Period over which failed sign-ins are counted.', 'seconds'),
            'security.login_max_per_ip' => $int('security', 'Failed sign-ins per IP', 1, 1000, 'Then that address is blocked for the rest of the window.'),
            'security.login_max_per_user' => $int('security', 'Failed sign-ins per user and IP', 1, 100),
            'security.trusted_proxies' => ['section' => 'security', 'type' => 'cidrs', 'label' => 'Trusted proxies',
                'help' => 'One address or network per line (e.g. 10.0.0.0/8): only these may set X-Forwarded-For / X-Real-IP. Leave empty when nginx talks to PHP-FPM directly.'],

            'monit.require_tls' => ['section' => 'monit', 'type' => 'bool', 'label' => 'Require HTTPS to Monit httpd',
                'help' => 'Actions and the reachability check refuse agents whose httpd does not use SSL, instead of talking to them in clear.'],
            'monit.tls_verify' => ['section' => 'monit', 'type' => 'bool', 'label' => 'Verify the certificate of Monit httpd',
                'help' => 'With a CA certificate below, only certificates issued by that CA are accepted; without it, the CAs of this server are used.'],
            'monit.ca_cert' => ['section' => 'monit', 'type' => 'pem', 'label' => 'CA certificate',
                'help' => 'PEM of the CA (or CAs) that issue the certificates of the Monit agents: -----BEGIN CERTIFICATE----- … Only the public certificate, never the CA key.'],
            'monit.tls_verify_name' => ['section' => 'monit', 'type' => 'bool', 'label' => 'Check the name in the certificate',
                'help' => 'The certificate must contain the IP address or host name Bmon connects to (subjectAltName). Turn off to accept any certificate issued by the CA.'],
            'monit.timeout' => $int('monit', 'Timeout of an action', 1, 120, '', 'seconds'),

            'slack.webhook_url' => ['section' => 'slack', 'type' => 'secret', 'label' => 'Incoming Webhook URL',
                'help' => 'https://hooks.slack.com/services/… of the channel. Kept secret: it is never shown again.', 'pattern' => '#^https://\S+$#',
                'error' => 'An https:// URL.'],
            'slack.title' => ['section' => 'slack', 'type' => 'text', 'label' => 'Title', 'max' => 150, 'required' => true],
            'slack.no_alerts' => ['section' => 'slack', 'type' => 'text', 'label' => 'Text when nothing is failing', 'max' => 150, 'required' => true],
            'slack.proxy' => ['section' => 'slack', 'type' => 'url', 'label' => 'Outgoing proxy',
                'help' => 'e.g. http://proxy.example.com:3128, only if this server reaches Slack through a proxy.'],
            'slack.timeout' => $int('slack', 'Timeout', 1, 120, '', 'seconds'),

            'agentconf.monit_dir' => ['section' => 'agentconf', 'type' => 'path', 'label' => 'Monit include directory on the agents', 'required' => true,
                'help' => 'Where the checks of the zip are installed.'],
            'agentconf.script_dir' => ['section' => 'agentconf', 'type' => 'path', 'label' => 'Script directory on the agents',
                'help' => 'Where the attached scripts are installed. Empty = <include directory>/bmon-scripts. Never the include directory itself.'],
            'agentconf.link_ttl_hours' => $int('agentconf', 'Lifetime of the download links', 1, 720, '', 'hours'),
            'agentconf.monitrc_dir' => ['section' => 'agentconf', 'type' => 'path', 'label' => 'Monit directory on the agents', 'required' => true,
                'help' => 'Where monitrc and monit.pem (key + certificate for HTTPS) are. The "Reporting to Bmon" template uses <directory>/monit.pem.'],
            'agentconf.collector_url' => ['section' => 'agentconf', 'type' => 'url', 'label' => 'Collector URL (template default)',
                'help' => 'Default of "set mmonit" in the "Reporting to Bmon" template, e.g. https://collector.example.com/collector. The user and password below are added to it.'],
            'agentconf.collector_user' => ['section' => 'agentconf', 'type' => 'text', 'label' => 'Collector user', 'max' => 64,
                'help' => 'User of bmon-collector, put into the collector URL of the "Reporting to Bmon" template.',
                'pattern' => '#^[A-Za-z0-9._~+=!*()-]*$#', 'error' => 'Letters, digits and . _ ~ + = ! * ( ) -'],
            'agentconf.collector_password' => ['section' => 'agentconf', 'type' => 'secret', 'label' => 'Collector password',
                'help' => 'Password of that user. Kept secret here: it is never shown again, but it is written into the "set mmonit" line of the checks created from the template.',
                'pattern' => '#^[A-Za-z0-9._~+=!*()-]{1,128}$#', 'error' => 'Up to 128 letters, digits and . _ ~ + = ! * ( ) - (characters that need no escaping in a URL).'],
            'agentconf.web_address' => ['section' => 'agentconf', 'type' => 'text', 'label' => 'Bmon-web address (template default)', 'max' => 64,
                'help' => 'Default "allow" of "set httpd" in that template: the address of this server as the agents see it.',
                'pattern' => '#^[A-Za-z0-9.:/-]*$#', 'error' => 'An address or a network.'],
        ];
    }

    /** Loads the stored settings over config.php. Safe before the migration. */
    public static function apply(): void
    {
        try {
            $rows = Db::all('SELECT name, value FROM settings');
        } catch (\PDOException) {
            return;   // table not created yet, or database unreachable: config.php only
        }
        $fields = self::fields();
        foreach ($rows as $r) {
            if (isset($fields[$r['name']])) {
                Config::set($r['name'], json_decode($r['value'], true));
            }
        }
    }

    /** Value as shown in the form. */
    public static function formValue(string $key, mixed $value): string
    {
        return match (self::fields()[$key]['type']) {
            'bool' => $value ? '1' : '0',
            'cidrs' => implode("\n", (array) $value),
            'secret' => '',
            default => (string) $value,
        };
    }

    /**
     * Validates and stores the submitted form.
     * @return array{0:array<string,string>,1:list<string>} [errors, changed keys]
     */
    public static function save(array $post, string $by): array
    {
        $errors = [];
        $parsed = [];
        foreach (self::fields() as $key => $f) {
            $name = str_replace('.', '__', $key);
            $raw = $post[$name] ?? null;
            if ($f['type'] === 'secret') {
                if (!empty($post[$name . '__clear'])) {
                    $parsed[$key] = '';
                } elseif (is_string($raw) && trim($raw) !== '') {
                    $parsed[$key] = trim($raw);
                } else {
                    continue;   // empty = keep the current secret
                }
            } elseif ($f['type'] === 'bool') {
                $parsed[$key] = !empty($raw);
                continue;
            } else {
                $parsed[$key] = is_string($raw) ? trim($raw) : '';
            }

            $v = $parsed[$key];
            $err = null;
            switch ($f['type']) {
                case 'int':
                    if (!preg_match('/^\d+$/', $v) || (int) $v < $f['min'] || (int) $v > $f['max']) {
                        $err = "A whole number between {$f['min']} and {$f['max']}.";
                    } else {
                        $parsed[$key] = (int) $v;
                    }
                    break;
                case 'timezone':
                    if (!in_array($v, \DateTimeZone::listIdentifiers(), true)) {
                        $err = 'Choose a time zone.';
                    }
                    break;
                case 'url':
                    if ($v !== '' && (!preg_match('#^https?://[^\s"\'<>]+$#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false)) {
                        $err = 'An http:// or https:// URL.';
                    }
                    $parsed[$key] = rtrim($v, $key === 'app.url' ? '/' : '');
                    break;
                case 'path':
                    if ($v !== '' && (!preg_match('#^/[A-Za-z0-9._/@+-]*$#', $v) || str_contains($v, '/../'))) {
                        $err = 'An absolute path (letters, digits and . _ / @ + -).';
                    }
                    $parsed[$key] = $v === '/' ? $v : rtrim($v, '/');
                    break;
                case 'pem':
                    $v = str_replace(["\r\n", "\r"], "\n", $v);
                    $parsed[$key] = $v === '' ? '' : trim($v) . "\n";
                    if ($v !== '') {
                        preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $v, $mm);
                        if (!$mm[0]) {
                            $err = 'Paste one or more certificates in PEM format (-----BEGIN CERTIFICATE----- …).';
                        } elseif (str_contains($v, 'PRIVATE KEY')) {
                            $err = 'This contains a private key: paste only the CA certificate.';
                        } else {
                            foreach ($mm[0] as $pem) {
                                $info = @openssl_x509_parse($pem);
                                if ($info === false) {
                                    $err = 'A certificate cannot be read.';
                                    break;
                                }
                            }
                        }
                    }
                    break;
                case 'cidrs':
                    $list = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $v) ?: []), 'strlen'));
                    foreach ($list as $c) {
                        [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
                        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
                        if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int) $bits > $max))) {
                            $err = "“{$c}” is not an IP address or network.";
                            break;
                        }
                    }
                    $parsed[$key] = $list;
                    break;
            }
            if ($err === null && isset($f['max']) && $f['type'] === 'text' && mb_strlen((string) $v) > $f['max']) {
                $err = "At most {$f['max']} characters.";
            }
            if ($err === null && !empty($f['required']) && $v === '') {
                $err = 'Required.';
            }
            if ($err === null && isset($f['pattern']) && $v !== '' && !preg_match($f['pattern'], (string) $v)) {
                $err = $f['error'] ?? 'Invalid value.';
            }
            if ($err === null && $f['type'] !== 'pem' && preg_match('/[\x00-\x1F\x7F]/u', is_string($parsed[$key]) ? $parsed[$key] : '')) {
                $err = 'No control characters.';
            }
            if ($err !== null) {
                $errors[$key] = $err;
            }
        }
        // cross-field rule
        $monitDir = $parsed['agentconf.monit_dir'] ?? Config::get('agentconf.monit_dir');
        if (!isset($errors['agentconf.script_dir']) && ($parsed['agentconf.script_dir'] ?? '') !== '' && $parsed['agentconf.script_dir'] === $monitDir) {
            $errors['agentconf.script_dir'] = 'It cannot be the include directory itself: Monit would read the scripts as configuration.';
        }
        if ($errors) {
            return [$errors, []];
        }

        $changed = [];
        foreach ($parsed as $key => $value) {
            $current = Config::get($key);
            if ($value === Config::inherited($key)) {
                // same as config.php / default: do not store, keep following it
                if (Config::source($key) === 'database') {
                    Db::exec('DELETE FROM settings WHERE name = ?', [$key]);
                    $changed[] = $key;
                }
                continue;
            }
            if ($value === $current && Config::source($key) === 'database') {
                continue;
            }
            Db::exec(
                'INSERT INTO settings (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
                [$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), now_utc(), $by]
            );
            $changed[] = $key;
        }
        return [[], $changed];
    }

    /** Back to config.php / default. */
    public static function reset(string $key): bool
    {
        return isset(self::fields()[$key]) && Db::exec('DELETE FROM settings WHERE name = ?', [$key]) > 0;
    }

    public static function stored(): array
    {
        try {
            $out = [];
            foreach (Db::all('SELECT name, updated_at, updated_by FROM settings') as $r) {
                $out[$r['name']] = $r;
            }
            return $out;
        } catch (\PDOException) {
            return [];
        }
    }
}
