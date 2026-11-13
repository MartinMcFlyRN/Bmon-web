<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Catalogue of Monit check templates for AgentConf, based on the examples of the
 * Monit manual (https://mmonit.com/monit/documentation/monit.html) with the paths
 * of Debian/Ubuntu packages and systemd units.
 *
 * A template has form fields and a body. In the body:
 *   {{key}}        is replaced by the field value;
 *   "?key " at the start of a line (after the indentation) keeps the line only
 *                  when the field is not empty.
 * Field values are checked against a pattern, so they can never break the syntax
 * (no quotes, newlines or backslashes). After creation the text is freely editable.
 */
final class AgentTemplates
{
    private const NAME = '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$';
    private const PATH = '^/[A-Za-z0-9._/@+:-]*$';
    private const UNIT = '^[A-Za-z0-9@._-]{1,128}$';
    private const ADDRESS = '^[A-Za-z0-9.:-]{1,253}$';
    private const PORT = '^([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5])$';
    private const INT = '^[1-9][0-9]{0,5}$';
    private const PERCENT = '^([1-9][0-9]?|100)(\.[0-9])?$';
    private const DECIMAL = '^[0-9]{1,4}(\.[0-9]{1,2})?$';
    private const TEXT = '^[^"\\\\\r\n]{1,200}$';
    private const URLPATH = '^/[^"\\\\\s]*$';

    public const CATEGORIES = [
        'mine' => 'Custom templates',   // Agent → Custom Templates
        'system' => 'System',
        'service' => 'Services (processes)',
        'remote' => 'Remote checks',
        'file' => 'Files and directories',
        'program' => 'Programs and network',
        'global' => 'Monit settings',
        'custom' => 'Custom',
    ];

    public const PROTOCOLS = [
        '' => '— none (TCP connect only) —', 'http' => 'HTTP', 'https' => 'HTTPS', 'ssh' => 'SSH',
        'smtp' => 'SMTP', 'imap' => 'IMAP', 'imaps' => 'IMAPS', 'pop' => 'POP3', 'mysql' => 'MySQL',
        'pgsql' => 'PostgreSQL', 'redis' => 'Redis', 'memcache' => 'Memcache', 'dns' => 'DNS', 'ftp' => 'FTP',
    ];

    /** @return array<string,array> key => template */
    public static function all(): array
    {
        static $all = null;
        return ($all ??= self::build()) + CustomTemplates::asTemplates();
    }

    /** Built-in templates built now (not cached): their paths follow the current Config. */
    public static function fresh(): array
    {
        return self::build();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Script shipped with a template (resources/agentconf/), or null. */
    public static function script(array $tpl): ?array
    {
        if (empty($tpl['script'])) {
            return null;
        }
        if (isset($tpl['script']['content'])) {   // custom template: kept in the database
            return ['name' => $tpl['script']['name'], 'content' => (string) $tpl['script']['content']];
        }
        $content = (string) file_get_contents(BMON_ROOT . '/resources/agentconf/' . basename($tpl['script']['file']));
        return ['name' => $tpl['script']['name'], 'content' => $content];
    }

    /**
     * Validates the submitted values of a template.
     * @return array{0:array<string,string>,1:array<string,string>} [values, errors]
     */
    public static function values(array $tpl, array $input): array
    {
        $values = [];
        $errors = [];
        foreach ($tpl['fields'] as $f) {
            $v = trim(is_string($input[$f['key']] ?? null) ? $input[$f['key']] : '');
            $values[$f['key']] = $v;
            if ($v === '') {
                if (!empty($f['required'])) {
                    $errors[$f['key']] = 'Required.';
                }
                continue;
            }
            if (($f['type'] ?? 'text') === 'select') {
                if (!array_key_exists($v, $f['options'])) {
                    $errors[$f['key']] = 'Choose one of the options.';
                }
            } elseif (!preg_match('#' . $f['pattern'] . '#u', $v)) {
                $errors[$f['key']] = $f['error'] ?? 'Invalid value.';
            }
        }
        return [$values, $errors];
    }

    /** Monit configuration text from a template and validated values. */
    public static function render(array $tpl, array $values): string
    {
        $out = [];
        foreach (explode("\n", $tpl['body']) as $line) {
            if (preg_match('/^(\s*)\?(\w+) (.*)$/', $line, $m)) {
                if (($values[$m[2]] ?? '') === '') {
                    continue;
                }
                $line = $m[1] . $m[3];
            }
            $out[] = preg_replace_callback('/\{\{(\w+)\}\}/', static fn ($mm) => $values[$mm[1]] ?? '', $line);
        }
        return rtrim(implode("\n", $out)) . "\n";
    }

    /** Best template for a service reported by Monit (to create its check from the agent page). */
    public static function suggest(int $type, string $name): string
    {
        $n = strtolower($name);
        if ($type === 3) {
            foreach (['nginx', 'apache', 'mariadb', 'mysql', 'php', 'ssh', 'postfix', 'dovecot', 'redis', 'memcache', 'cron'] as $k) {
                if (str_contains($n, $k)) {
                    return ['apache' => 'apache2', 'mysql' => 'mariadb', 'php' => 'php-fpm', 'ssh' => 'sshd', 'memcache' => 'memcached'][$k] ?? $k;
                }
            }
            return 'process-pidfile';
        }
        return match ($type) {
            0 => 'filesystem', 1 => 'directory-age', 2 => 'file-checksum', 4 => 'host-website',
            5 => 'system', 7 => 'program', 8 => 'network', default => 'custom',
        };
    }

    // -------------------------------------------------------------------------

    /** Directory of monitrc and monit.pem on the agents. */
    public static function monitrcDir(): string
    {
        return rtrim((string) Config::get('agentconf.monitrc_dir', '/etc/monit'), '/') ?: '/etc/monit';
    }

    /**
     * Default "set mmonit" URL: agentconf.collector_url with agentconf.collector_user
     * and collector_password put in (replacing any user:password already in the URL).
     */
    public static function collectorUrl(): string
    {
        $url = trim((string) Config::get('agentconf.collector_url', ''));
        $user = trim((string) Config::get('agentconf.collector_user', ''));
        $pass = (string) Config::get('agentconf.collector_password', '');
        if ($url === '') {
            $url = 'https://collector.example.com/collector';
            if ($user === '') {
                return 'https://monit:PASSWORD@collector.example.com/collector';
            }
        }
        if ($user === '' || !preg_match('#^(https?://)(?:[^/@]*@)?(.*)$#i', $url, $m)) {
            return $url;
        }
        return $m[1] . $user . ($pass !== '' ? ':' . $pass : '') . '@' . $m[2];
    }

    private static function f(string $key, string $label, string $default, string $pattern, string $help = '', bool $required = true, ?string $error = null): array
    {
        return compact('key', 'label', 'default', 'pattern', 'help', 'required') + ['type' => 'text', 'error' => $error];
    }

    private static function nameField(string $default): array
    {
        return self::f('name', 'Check name', $default, self::NAME,
            'Name of the service in Monit. Letters, digits, dot, dash, underscore.', true,
            'Use letters, digits, dot, dash or underscore (max 64).');
    }

    /** A systemd service with a pidfile, a restart policy and an optional port/socket test. */
    private static function service(string $label, string $desc, string $name, string $pidfile, string $unit, string $test, array $extra = []): array
    {
        return [
            'category' => 'service',
            'label' => $label,
            'description' => $desc,
            'fields' => array_merge([
                self::nameField($name),
                self::f('pidfile', 'PID file', $pidfile, self::PATH, '', true, 'An absolute path.'),
                self::f('unit', 'systemd unit', $unit, self::UNIT, 'Used by the start and stop programs.'),
            ], $extra),
            'body' => "check process {{name}} with pidfile {{pidfile}}\n"
                . "    start program = \"/usr/bin/systemctl start {{unit}}\" with timeout 60 seconds\n"
                . "    stop program  = \"/usr/bin/systemctl stop {{unit}}\"\n"
                . $test
                . "    if 5 restarts within 5 cycles then unmonitor\n",
        ];
    }

    private static function build(): array
    {
        $port = static fn (string $d, bool $req = true) => self::f('port', 'Port', $d, self::PORT, '', $req, 'A port number (1-65535).');
        $sock = static fn (string $d) => self::f('socket', 'Unix socket', $d, self::PATH, '', true, 'An absolute path.');

        return [
            // --- system -------------------------------------------------------
            'system' => [
                'category' => 'system',
                'label' => 'System resources',
                'description' => 'Load average, CPU, memory and swap of the whole host. Only one per agent. Monit names it after the machine ($HOST) and uses that name as the agent name in Bmon: if the current system check has another name, the host will show the new one.',
                'fields' => [
                    self::f('load5', 'Load average (5 min) above', '4', self::DECIMAL, 'Usually about the number of CPUs.', true, 'A number, e.g. 4 or 2.5.'),
                    self::f('cpu', 'CPU usage above (%)', '90', self::PERCENT, '', true, 'A percentage (1-100).'),
                    self::f('memory', 'Memory usage above (%)', '90', self::PERCENT, '', true, 'A percentage (1-100).'),
                    self::f('swap', 'Swap usage above (%)', '25', self::PERCENT, '', true, 'A percentage (1-100).'),
                    self::f('cycles', 'For cycles', '5', self::INT, 'How many consecutive cycles before alerting on CPU, load and memory.', true, 'A whole number.'),
                ],
                'filename' => 'system',
                'body' => "check system \$HOST\n"
                    . "    if loadavg (5min) > {{load5}} for {{cycles}} cycles then alert\n"
                    . "    if cpu usage > {{cpu}}% for {{cycles}} cycles then alert\n"
                    . "    if memory usage > {{memory}}% for {{cycles}} cycles then alert\n"
                    . "    if swap usage > {{swap}}% then alert\n",
            ],
            'os-info' => [
                'category' => 'system',
                'label' => 'OS information',
                'description' => 'Reports the distribution and its version (e.g. Debian 12, Ubuntu 24.04) to Bmon-web, shown on the host page and in Status. Uses an attached script that reads /etc/os-release; it never alerts.',
                'fields' => [
                    self::nameField('os-info'),
                    self::f('every', 'Run every (minutes)', '5', '^([1-9]|[1-5][0-9])$', 'Monit reads the result of a program at the following run, so the OS appears in Bmon after about two runs.', true, 'Minutes, 1 to 59.'),
                ],
                'script' => ['name' => 'bmon-osinfo.sh', 'file' => 'bmon-osinfo.sh'],
                'body' => "check program {{name}} with path \"" . AgentChecks::scriptPath('bmon-osinfo.sh') . "\" with timeout 10 seconds\n"
                    . "    every \"{{cron}}\"\n"
                    . "    if status != 0 then alert\n",
            ],
            'filesystem' => [
                'category' => 'system',
                'label' => 'Filesystem space',
                'description' => 'Disk space and inode usage of a mount point; alerts if the mount flags change (e.g. remounted read-only).',
                'fields' => [
                    self::nameField('rootfs'),
                    self::f('path', 'Mount point', '/', self::PATH, '', true, 'An absolute path.'),
                    self::f('space', 'Space usage above (%)', '90', self::PERCENT, '', true, 'A percentage (1-100).'),
                    self::f('inode', 'Inode usage above (%)', '90', self::PERCENT, '', true, 'A percentage (1-100).'),
                ],
                'body' => "check filesystem {{name}} with path {{path}}\n"
                    . "    if space usage > {{space}}% then alert\n"
                    . "    if inode usage > {{inode}}% then alert\n"
                    . "    if changed fsflags then alert\n",
            ],

            // --- services ------------------------------------------------------
            'nginx' => self::service('nginx', 'nginx web server: restarted if it stops or does not answer HTTP.',
                'nginx', '/run/nginx.pid', 'nginx',
                "    if failed host {{host}} port {{port}} protocol http with timeout 10 seconds for 2 cycles then restart\n",
                [self::f('host', 'Test address', '127.0.0.1', self::ADDRESS, '', true, 'A host name or IP address.'), $port('80')]),
            'apache2' => self::service('Apache', 'Apache httpd (Debian apache2): restarted if it stops or does not answer HTTP.',
                'apache2', '/run/apache2/apache2.pid', 'apache2',
                "    if failed host {{host}} port {{port}} protocol http with timeout 10 seconds for 2 cycles then restart\n",
                [self::f('host', 'Test address', '127.0.0.1', self::ADDRESS, '', true, 'A host name or IP address.'), $port('80')]),
            'mariadb' => self::service('MariaDB / MySQL', 'Database server: restarted if it stops or its socket does not answer the MySQL protocol.',
                'mariadb', '/run/mysqld/mysqld.pid', 'mariadb',
                "    if failed unixsocket {{socket}} protocol mysql for 3 cycles then restart\n",
                [$sock('/run/mysqld/mysqld.sock')]),
            'php-fpm' => self::service('PHP-FPM', 'PHP FastCGI process manager: restarted if it stops or its socket is not reachable. Adjust the PHP version in the paths.',
                'php-fpm', '/run/php/php8.2-fpm.pid', 'php8.2-fpm',
                "    if failed unixsocket {{socket}} for 3 cycles then restart\n",
                [$sock('/run/php/php8.2-fpm.sock')]),
            'sshd' => self::service('OpenSSH', 'SSH daemon: restarted if it stops or does not answer the SSH protocol.',
                'sshd', '/run/sshd.pid', 'ssh',
                "    if failed host 127.0.0.1 port {{port}} protocol ssh for 2 cycles then restart\n",
                [$port('22')]),
            'postfix' => self::service('Postfix', 'Mail server: restarted if it stops or does not answer SMTP.',
                'postfix', '/var/spool/postfix/pid/master.pid', 'postfix',
                "    if failed host 127.0.0.1 port {{port}} protocol smtp for 2 cycles then restart\n",
                [$port('25')]),
            'dovecot' => self::service('Dovecot', 'IMAP/POP3 server: restarted if it stops or does not answer IMAP.',
                'dovecot', '/run/dovecot/master.pid', 'dovecot',
                "    if failed host 127.0.0.1 port {{port}} protocol imap for 2 cycles then restart\n",
                [$port('143')]),
            'redis' => self::service('Redis', 'Redis server: restarted if it stops or does not answer the Redis protocol.',
                'redis', '/run/redis/redis-server.pid', 'redis-server',
                "    if failed host 127.0.0.1 port {{port}} protocol redis for 2 cycles then restart\n",
                [$port('6379')]),
            'memcached' => [
                'category' => 'service',
                'label' => 'Memcached',
                'description' => 'Memcached (found by its command line): restarted if it stops or does not answer the memcache protocol.',
                'fields' => [self::nameField('memcached'), self::f('unit', 'systemd unit', 'memcached', self::UNIT), $port('11211')],
                'body' => "check process {{name}} matching \"memcached\"\n"
                    . "    start program = \"/usr/bin/systemctl start {{unit}}\" with timeout 60 seconds\n"
                    . "    stop program  = \"/usr/bin/systemctl stop {{unit}}\"\n"
                    . "    if failed host 127.0.0.1 port {{port}} protocol memcache for 2 cycles then restart\n"
                    . "    if 5 restarts within 5 cycles then unmonitor\n",
            ],
            'cron' => [
                'category' => 'service',
                'label' => 'cron',
                'description' => 'cron daemon (found by its command line): started again if it stops.',
                'fields' => [self::nameField('cron'), self::f('unit', 'systemd unit', 'cron', self::UNIT)],
                'body' => "check process {{name}} matching \"/usr/sbin/cron\"\n"
                    . "    start program = \"/usr/bin/systemctl start {{unit}}\" with timeout 60 seconds\n"
                    . "    stop program  = \"/usr/bin/systemctl stop {{unit}}\"\n"
                    . "    if 5 restarts within 5 cycles then unmonitor\n",
            ],
            'process-pidfile' => [
                'category' => 'service',
                'label' => 'Any service (PID file)',
                'description' => 'A systemd service found by its PID file, with an optional port test and CPU/memory limits.',
                'fields' => [
                    self::nameField('myservice'),
                    self::f('pidfile', 'PID file', '/run/myservice.pid', self::PATH, '', true, 'An absolute path.'),
                    self::f('unit', 'systemd unit', 'myservice', self::UNIT),
                    self::f('host', 'Test address', '127.0.0.1', self::ADDRESS, 'Used by the port test.', true, 'A host name or IP address.'),
                    $port('', false) + ['help' => 'Leave empty for no port test.'],
                    ['key' => 'protocol', 'label' => 'Protocol of the port test', 'default' => '', 'type' => 'select',
                        'options' => self::PROTOCOLS, 'required' => false, 'help' => ''],
                    self::f('cpu', 'Restart if CPU above (%)', '', self::PERCENT, 'Leave empty for no limit.', false, 'A percentage (1-100).'),
                    self::f('memory', 'Alert if memory above (MB)', '', self::INT, 'Leave empty for no limit.', false, 'A whole number of MB.'),
                ],
                'body' => "check process {{name}} with pidfile {{pidfile}}\n"
                    . "    start program = \"/usr/bin/systemctl start {{unit}}\" with timeout 60 seconds\n"
                    . "    stop program  = \"/usr/bin/systemctl stop {{unit}}\"\n"
                    . "    ?port if failed host {{host}} port {{port}} {{protocol_clause}}for 2 cycles then restart\n"
                    . "    ?cpu if cpu usage > {{cpu}}% for 5 cycles then restart\n"
                    . "    ?memory if total memory > {{memory}} MB for 5 cycles then alert\n"
                    . "    if 5 restarts within 5 cycles then unmonitor\n",
            ],
            'process-matching' => [
                'category' => 'service',
                'label' => 'Any process (command line)',
                'description' => 'A process found by a regular expression on its command line, for programs without a PID file.',
                'fields' => [
                    self::nameField('myprocess'),
                    self::f('pattern', 'Command line matches', 'myprogram', self::TEXT, 'Regular expression, e.g. "node /srv/app/server.js".', true, 'No quotes or backslashes.'),
                    self::f('unit', 'systemd unit', '', self::UNIT, 'Leave empty if Monit should only alert.', false),
                ],
                'body' => "check process {{name}} matching \"{{pattern}}\"\n"
                    . "    ?unit start program = \"/usr/bin/systemctl start {{unit}}\" with timeout 60 seconds\n"
                    . "    ?unit stop program  = \"/usr/bin/systemctl stop {{unit}}\"\n"
                    . "    if does not exist then alert\n",
            ],

            // --- remote ------------------------------------------------------------
            'host-website' => [
                'category' => 'remote',
                'label' => 'Website (HTTPS)',
                'description' => 'A page must answer with status 200, and the TLS certificate must not expire within the given days.',
                'fields' => [
                    self::nameField('website'),
                    self::f('address', 'Host name', 'www.example.com', self::ADDRESS, 'Also sent as the HTTP Host header.', true, 'A host name or IP address.'),
                    self::f('path', 'Path', '/', self::URLPATH, '', true, 'A path starting with /.'),
                    self::f('cert_days', 'Certificate valid for at least (days)', '15', self::INT, '', true, 'A whole number of days.'),
                ],
                'body' => "check host {{name}} with address {{address}}\n"
                    . "    if failed port 443 protocol https request \"{{path}}\" status = 200 with timeout 15 seconds for 2 cycles then alert\n"
                    . "    if failed port 443 protocol https and certificate valid > {{cert_days}} days then alert\n",
            ],
            'host-http' => [
                'category' => 'remote',
                'label' => 'Web page (HTTP)',
                'description' => 'A plain HTTP page must answer with status 200, optionally containing a text.',
                'fields' => [
                    self::nameField('webpage'),
                    self::f('address', 'Host name', 'www.example.com', self::ADDRESS, '', true, 'A host name or IP address.'),
                    $port('80'),
                    self::f('path', 'Path', '/', self::URLPATH, '', true, 'A path starting with /.'),
                    self::f('content', 'Page must contain', '', self::TEXT, 'Leave empty to check only the status.', false, 'No quotes or backslashes.'),
                ],
                'body' => "check host {{name}} with address {{address}}\n"
                    . "    if failed port {{port}} protocol http request \"{{path}}\" status = 200 {{content_clause}}with timeout 15 seconds for 2 cycles then alert\n",
            ],
            'host-tcp' => [
                'category' => 'remote',
                'label' => 'TCP port',
                'description' => 'A TCP port of a remote host must accept connections, optionally speaking a protocol.',
                'fields' => [
                    self::nameField('remote-port'),
                    self::f('address', 'Address', '192.0.2.10', self::ADDRESS, '', true, 'A host name or IP address.'),
                    $port('443'),
                    ['key' => 'protocol', 'label' => 'Protocol', 'default' => '', 'type' => 'select',
                        'options' => self::PROTOCOLS, 'required' => false, 'help' => ''],
                ],
                'body' => "check host {{name}} with address {{address}}\n"
                    . "    if failed port {{port}} {{protocol_clause}}with timeout 10 seconds for 2 cycles then alert\n",
            ],
            'host-ping' => [
                'category' => 'remote',
                'label' => 'Ping',
                'description' => 'A remote host must answer ICMP echo (Monit must run as root).',
                'fields' => [
                    self::nameField('gateway'),
                    self::f('address', 'Address', '192.0.2.1', self::ADDRESS, '', true, 'A host name or IP address.'),
                ],
                'body' => "check host {{name}} with address {{address}}\n"
                    . "    if failed ping count 3 with timeout 5 seconds for 2 cycles then alert\n",
            ],

            // --- files --------------------------------------------------------------
            'file-checksum' => [
                'category' => 'file',
                'label' => 'File integrity',
                'description' => 'Alert when a file changes (checksum), e.g. binaries or important configuration.',
                'fields' => [
                    self::nameField('passwd'),
                    self::f('path', 'File', '/etc/passwd', self::PATH, '', true, 'An absolute path.'),
                    self::f('perm', 'Expected permissions', '', '^[0-7]{3,4}$', 'Octal, e.g. 0644. Leave empty to skip.', false, 'Octal permissions, e.g. 0644.'),
                ],
                'body' => "check file {{name}} with path {{path}}\n"
                    . "    if changed checksum then alert\n"
                    . "    ?perm if failed permission {{perm}} then alert\n",
            ],
            'file-age' => [
                'category' => 'file',
                'label' => 'File freshness',
                'description' => 'Alert when a file is older than expected, e.g. a nightly backup or a heartbeat file.',
                'fields' => [
                    self::nameField('backup'),
                    self::f('path', 'File', '/var/backups/latest.tar.gz', self::PATH, '', true, 'An absolute path.'),
                    self::f('hours', 'Older than (hours)', '26', self::INT, '', true, 'A whole number of hours.'),
                ],
                'body' => "check file {{name}} with path {{path}}\n"
                    . "    if does not exist then alert\n"
                    . "    if timestamp > {{hours}} hours then alert\n",
            ],
            'file-size' => [
                'category' => 'file',
                'label' => 'File size',
                'description' => 'Alert when a file (e.g. a log) grows beyond a size.',
                'fields' => [
                    self::nameField('syslog'),
                    self::f('path', 'File', '/var/log/syslog', self::PATH, '', true, 'An absolute path.'),
                    self::f('size', 'Larger than (MB)', '1024', self::INT, '', true, 'A whole number of MB.'),
                ],
                'body' => "check file {{name}} with path {{path}}\n"
                    . "    if size > {{size}} MB then alert\n",
            ],
            'file-content' => [
                'category' => 'file',
                'label' => 'Log content',
                'description' => 'Alert when new lines of a file match a regular expression, e.g. errors in a log.',
                'fields' => [
                    self::nameField('app-errors'),
                    self::f('path', 'File', '/var/log/app/error.log', self::PATH, '', true, 'An absolute path.'),
                    self::f('pattern', 'Line matches', 'FATAL|PANIC', self::TEXT, 'Regular expression.', true, 'No quotes or backslashes.'),
                ],
                'body' => "check file {{name}} with path {{path}}\n"
                    . "    if content = \"{{pattern}}\" then alert\n",
            ],
            'directory-age' => [
                'category' => 'file',
                'label' => 'Directory freshness',
                'description' => 'Alert when nothing has changed in a directory for too long, e.g. a backup destination.',
                'fields' => [
                    self::nameField('backups'),
                    self::f('path', 'Directory', '/var/backups', self::PATH, '', true, 'An absolute path.'),
                    self::f('hours', 'Not modified for (hours)', '26', self::INT, '', true, 'A whole number of hours.'),
                ],
                'body' => "check directory {{name}} with path {{path}}\n"
                    . "    if does not exist then alert\n"
                    . "    if timestamp > {{hours}} hours then alert\n",
            ],

            // --- programs and network -----------------------------------------------
            'program' => [
                'category' => 'program',
                'label' => 'Custom script',
                'description' => 'Run a script and alert when its exit status is not 0; its output appears in the alert (e.g. certificate expiry, queue length).',
                'fields' => [
                    self::nameField('check-script'),
                    self::f('path', 'Script', AgentChecks::scriptPath('check_something.sh'), '^/[^"\\\\\r\n]{0,250}$', 'Absolute path of the executable, followed by its arguments if needed. After adding the check you can attach the script itself, installed in ' . AgentChecks::scriptDir() . '/.', true, 'An absolute path, without quotes or backslashes.'),
                    self::f('timeout', 'Timeout (seconds)', '30', self::INT, '', true, 'A whole number.'),
                    self::f('every', 'Run every (minutes)', '', '^([1-9]|[1-5][0-9])$', 'Leave empty to run at every cycle.', false, 'Minutes, 1 to 59, or empty.'),
                ],
                'body' => "check program {{name}} with path \"{{path}}\" with timeout {{timeout}} seconds\n"
                    . "    ?every every \"{{cron}}\"\n"
                    . "    if status != 0 then alert\n",
            ],
            'network' => [
                'category' => 'program',
                'label' => 'Network interface',
                'description' => 'Link state and saturation of a network interface.',
                'fields' => [
                    self::nameField('eth0'),
                    self::f('iface', 'Interface', 'eth0', '^[A-Za-z0-9._@-]{1,15}$', '', true, 'An interface name, e.g. eth0 or ens18.'),
                    self::f('saturation', 'Saturation above (%)', '90', self::PERCENT, '', true, 'A percentage (1-100).'),
                ],
                'body' => "check network {{name}} with interface {{iface}}\n"
                    . "    if failed link then alert\n"
                    . "    if changed link then alert\n"
                    . "    if saturation > {{saturation}}% for 3 cycles then alert\n",
            ],

            // --- Monit settings -----------------------------------------------------------
            'monit-bmon' => [
                'category' => 'global',
                'label' => 'Reporting to Bmon',
                'description' => 'set mmonit (reports to bmon-collector, with credentials for actions) and set httpd (actions from Bmon-web). Remove any other "set httpd" or "set mmonit" from the main monitrc.',
                'filename' => '00-bmon',
                'fields' => [
                    self::f('collector', 'Collector URL', self::collectorUrl(),
                        '^https?://[^"\\\\\s]+$', 'With the user and password of the collector (defaults from Admin → Configuration).', true, 'An http(s) URL without spaces or quotes.'),
                    $port('2812'),
                    self::f('pemfile', 'HTTPS certificate (pemfile)', self::monitrcDir() . '/monit.pem', '^(/[A-Za-z0-9._/-]{1,200})?$',
                        'Private key + certificate issued by your CA (see README, "HTTPS to Monit"). Empty = plain HTTP.', false,
                        'An absolute path, or empty for plain HTTP.'),
                    self::f('listen', 'httpd listens on', '0.0.0.0', self::ADDRESS, 'Address reachable from the Bmon-web server.', true, 'An IP address or host name.'),
                    self::f('allow', 'Allow Bmon-web from', (string) Config::get('agentconf.web_address', '203.0.113.5'),
                        '^[A-Za-z0-9.:/-]{1,64}$', 'Address of the Bmon-web server (or a network, e.g. 10.0.0.0/8).', true, 'An address or network.'),
                    self::f('user', 'httpd user', 'admin', '^[A-Za-z0-9._-]{1,32}$', '', true, 'Letters, digits, dot, dash, underscore.'),
                    self::f('password', 'httpd password', bin2hex(random_bytes(12)), '^[A-Za-z0-9._~+=-]{8,64}$',
                        'Generated randomly. Bmon-web learns it from the reports.', true, '8-64 letters, digits or ._~+=-'),
                ],
                'body' => "set mmonit {{collector}}\n"
                    . "set httpd port {{port}} and\n"
                    . "{{ssl_clause}}"
                    . "    use address {{listen}}\n"
                    . "    allow {{allow}}\n"
                    . "    allow {{user}}:\"{{password}}\"\n",
            ],

            'bmon-update' => [
                'category' => 'global',
                'label' => 'Bmon auto-update',
                'description' => 'Installs the configuration published in AgentConf by itself: every few cycles (and at once with "Update now") it downloads the new version, signed with the httpd password, replaces the Bmon files, checks them with monit -t (restoring the previous ones on error) and reloads Monit. Install this once by hand with the zip.',
                'fields' => [
                    self::nameField('bmon-update'),
                    self::f('url', 'Bmon-web URL', rtrim((string) Config::get('app.url', ''), '/') ?: 'https://bmon.example.com',
                        '^https?://[A-Za-z0-9.:\\[\\]-]+(/[A-Za-z0-9._~/-]*)?$', 'Address of this server as the agent reaches it (no trailing slash).', true,
                        'An http(s) URL without spaces, quotes or query.'),
                    self::f('every', 'Ask every (minutes)', '5', '^([1-9]|[1-5][0-9])$', '"Update now" does not wait for it.', true, 'Minutes, 1 to 59.'),
                    self::f('cacert', 'CA of Bmon-web (optional)', '', '^(/[A-Za-z0-9._/@+-]{1,200})?$',
                        'PEM file on the agent to verify the HTTPS certificate of Bmon-web with, if it is not issued by a public CA. Empty = system CAs.', false,
                        'An absolute path, or empty.'),
                ],
                'script' => ['name' => AgentUpdate::SCRIPT, 'file' => AgentUpdate::SCRIPT],
                'body' => "check program {{name}} with path \"{{command}}\" with timeout 300 seconds\n"
                    . "    every \"{{cron}}\"\n"
                    . "    start program = \"{{command}} --now\" with timeout 300 seconds\n"
                    . "    if status != 0 then alert\n",
            ],

            // --- custom -------------------------------------------------------------
            'custom' => [
                'category' => 'custom',
                'label' => 'Custom',
                'description' => 'Start from an empty check and write it yourself (Monit syntax).',
                'fields' => [self::nameField('custom')],
                'body' => "check process {{name}} matching \"{{name}}\"\n"
                    . "    if does not exist then alert\n",
            ],
        ];
    }

    /**
     * Derived values used in some bodies (e.g. "protocol http " only when a protocol is chosen).
     */
    public static function derive(string $key, array $values): array
    {
        if (isset($values['protocol'])) {
            $values['protocol_clause'] = $values['protocol'] !== '' ? 'protocol ' . $values['protocol'] . ' ' : 'type tcp ';
        }
        // "every N cycles" is deprecated by newer Monit: cron style, in minutes, as an
        // explicit list ("*/N" is not understood by Monit 5.33 and older)
        if (($values['every'] ?? '') !== '') {
            $n = max(1, (int) $values['every']);
            $values['cron'] = ($n === 1 ? '*' : implode(',', range(0, 59, $n))) . ' * * * *';
        }
        if ($key === 'bmon-update' && isset($values['url'])) {
            $values['command'] = AgentChecks::scriptPath(AgentUpdate::SCRIPT) . ' --url ' . rtrim($values['url'], '/')
                . ' --conf-dir ' . AgentChecks::monitDir() . ' --monitrc ' . self::monitrcDir() . '/monitrc'
                . (($bin = trim((string) Config::get('agentconf.agent_monit', 'monit'))) !== '' && $bin !== 'monit' ? ' --monit ' . $bin : '')
                . (($values['cacert'] ?? '') !== '' ? ' --cacert ' . $values['cacert'] : '');
        }
        if (array_key_exists('pemfile', $values)) {
            $values['ssl_clause'] = $values['pemfile'] !== '' ? "    with ssl {\n        pemfile: " . $values['pemfile'] . "\n    }\n" : '';
        }
        if (array_key_exists('content', $values)) {
            $values['content_clause'] = $values['content'] !== '' ? 'content = "' . $values['content'] . '" ' : '';
        }
        return $values;
    }
}
