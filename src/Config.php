<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Configuration loader. The config file is a PHP file returning an array,
 * merged over the defaults below. Lookup order:
 *   1. $BMON_WEB_CONFIG
 *   2. /etc/bmon-web/config.php
 *   3. <project>/config/config.php
 */
final class Config
{
    private static ?array $values = null;
    /** defaults + config.php, before the settings of Admin → Configuration */
    private static ?array $fileValues = null;
    /** keys present in config.php */
    private static array $fileRaw = [];
    /** keys overridden from the database (Admin → Configuration) */
    private static array $dbKeys = [];

    public static function defaults(): array
    {
        return [
            'app' => [
                'name'      => 'Bmon',
                'base_path' => '',              // e.g. '/bmon' when not served at the vhost root
                'timezone'  => 'Europe/Rome',   // display timezone (the database stores UTC)
                'debug'     => false,
                'url'       => '',              // public URL, e.g. https://bmon.example.com (links in Slack reports)
            ],
            'db' => [
                'dsn'      => 'mysql:host=localhost;dbname=bmon_web;charset=utf8mb4',
                'user'     => 'bmon_web',
                'password' => '',
            ],
            // Database written by bmon-collector, on the same MySQL/MariaDB server.
            // The web user only needs SELECT on it.
            'collector_db' => 'bmon_collector',
            // Actions (start/stop/...) sent to the Monit httpd of the hosts.
            'monit' => [
                'credentials_key' => '',    // = BMON_CREDENTIALS_KEY of bmon-collector, if set there
                'tls_verify'      => false, // verify the certificate of Monit httpd (HTTPS)
                'ca_cert'         => '',    // PEM of the CA that issues the Monit certificates ('' = system CAs)
                'tls_verify_name' => true,  // the certificate must name the address Bmon connects to
                'require_tls'     => false, // refuse to talk to Monit httpd over plain HTTP
                'timeout'         => 10,    // seconds
            ],
            // AgentConf: Monit configurations prepared for the agents
            'agentconf' => [
                'monit_binary'  => '',      // path of monit for "monit -t" checks; '' = search PATH, 'none' = no checks
                'monit_dir'     => '/etc/monit/conf.d',  // where the zip is installed on the agents
                'script_dir'    => '',      // where the attached scripts go on the agents; '' = <monit_dir>/bmon-scripts
                'link_ttl_hours' => 24,     // lifetime of the download links (no sign-in) of the zips
                'collector_url' => '',      // default of the "Reporting to Bmon" template (set mmonit)
                'web_address'   => '',      // default "allow" address of Bmon-web in that template
            ],
            // bin/bmon-web report:slack
            'slack' => [
                'webhook_url' => '',        // Incoming Webhook of the channel
                'title'       => 'Active alerts summary:',
                'no_alerts'   => 'No active alerts.',
                'proxy'       => '',        // e.g. http://proxy.example.com:3128
                'timeout'     => 10,
            ],
            'session' => [
                'name'             => 'bmon_session',
                'secure'           => true,     // cookie sent over HTTPS only
                'idle_timeout'     => 3600,     // seconds without requests before logout
                'absolute_timeout' => 43200,    // maximum session length in seconds
            ],
            'security' => [
                'password_min_length' => 10,
                'login_window'        => 900,   // seconds
                'login_max_per_ip'    => 20,    // failed logins per IP in the window
                'login_max_per_user'  => 5,     // failed logins per username+IP in the window
                // Proxies allowed to set X-Forwarded-For / X-Real-IP (CIDR list).
                // Empty when nginx talks to PHP-FPM directly.
                'trusted_proxies'     => [],
            ],
        ];
    }

    public static function load(?string $path = null): void
    {
        $path ??= self::locate();
        $values = self::defaults();
        if ($path !== null) {
            $file = require $path;
            if (!is_array($file)) {
                throw new \RuntimeException("Config file $path must return an array");
            }
            $values = array_replace_recursive($values, $file);
            // lists are replaced, not merged by index
            if (isset($file['security']['trusted_proxies'])) {
                $values['security']['trusted_proxies'] = $file['security']['trusted_proxies'];
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $values['collector_db'])) {
            throw new \RuntimeException('collector_db must be a plain database name');
        }
        self::$values = self::$fileValues = $values;
        self::$fileRaw = isset($file) && is_array($file) ? $file : [];
        self::$dbKeys = [];
    }

    /** Sets a value (used for the settings stored in the database). */
    public static function set(string $key, mixed $value): void
    {
        if (self::$values === null) {
            self::load();
        }
        $ref = &self::$values;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = $ref[$part] ?? [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        self::$dbKeys[$key] = true;
    }

    /** Value without the database settings: config.php, or the default. */
    public static function inherited(string $key): mixed
    {
        return self::lookup(self::$fileValues ?? [], $key);
    }

    /** Where the current value comes from: database | file | default */
    public static function source(string $key): string
    {
        if (isset(self::$dbKeys[$key])) {
            return 'database';
        }
        return self::lookup(self::$fileRaw, $key, "\0missing") !== "\0missing" ? 'file' : 'default';
    }

    public static function defaultValue(string $key): mixed
    {
        return self::lookup(self::defaults(), $key);
    }

    private static function lookup(array $v, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }

    public static function locate(): ?string
    {
        $candidates = [
            getenv('BMON_WEB_CONFIG') ?: null,
            '/etc/bmon-web/config.php',
            BMON_ROOT . '/config/config.php',
        ];
        foreach ($candidates as $c) {
            if ($c !== null && is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /** Dot-notation lookup: Config::get('session.idle_timeout'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            self::load();
        }
        $v = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }
}
