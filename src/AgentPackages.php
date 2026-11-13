<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Agent → Installer: packages to set up Monit on a new server. A package holds
 * the Monit binary and the HTTPS certificate (monit.pem, key + certificate);
 * its permanent link /pkg/<token> gives a zip to extract in the install
 * directory (e.g. /usr/local/monit):
 *
 *   bin/monit                    the uploaded binary
 *   conf/monitrc                 daemon, log, pid/state files, id file <dir>/monit.id, event queue, include conf.d
 *   conf/monit.pem               the uploaded certificate
 *   conf.d/bmon-00-bmon.conf     Reporting to Bmon (set mmonit + set httpd with ssl)
 *   conf.d/bmon-bmon-update.conf Bmon auto-update
 *   conf.d/bmon-os-info.conf     OS information
 *   script/bmon-update.sh, script/bmon-osinfo.sh
 *
 * The checks are rendered at download time from the required templates, with
 * the paths of the install directory, so they always follow the current
 * templates and Configuration. The private key and the httpd password are
 * stored encrypted with monit.credentials_key.
 */
final class AgentPackages
{
    public const MAX_BINARY = 32 * 1024 * 1024;

    public static function all(): array
    {
        return Db::all('SELECT id, name, base_dir, monit_bin_name, LENGTH(monit_bin) AS monit_bin_size, monit_bin_sha256, monit_bin_info,
                               pem_info, httpd_user, httpd_port, token, downloads, last_download_at, last_download_ip, updated_at, updated_by
                          FROM agent_packages ORDER BY name');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM agent_packages WHERE id = ?', [$id]);
    }

    public static function byToken(string $token): ?array
    {
        return Db::one('SELECT * FROM agent_packages WHERE token = ?', [$token]);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // --- uploaded files -------------------------------------------------------------

    /**
     * Checks the Monit binary: an ELF executable.
     * @return array{ok:bool,error?:string,info?:string}
     */
    public static function checkBinary(string $bytes): array
    {
        if (strlen($bytes) < 1024 || !str_starts_with($bytes, "\x7fELF")) {
            return ['ok' => false, 'error' => 'This is not a Linux executable (ELF): upload the monit binary, e.g. from the bin directory of the Monit tarball.'];
        }
        $class = ord($bytes[4]) === 2 ? '64-bit' : '32-bit';
        $little = ord($bytes[5]) === 1;
        $machine = unpack($little ? 'v' : 'n', substr($bytes, 18, 2))[1];
        $arch = [0x3E => 'x86-64', 0x03 => 'x86', 0xB7 => 'aarch64', 0x28 => 'arm', 0xF3 => 'riscv', 0x15 => 'ppc64'][$machine] ?? sprintf('machine 0x%x', $machine);
        $type = unpack($little ? 'v' : 'n', substr($bytes, 16, 2))[1];
        if (!in_array($type, [2, 3], true)) {
            return ['ok' => false, 'error' => 'This ELF file is not an executable.'];
        }
        return ['ok' => true, 'info' => "ELF $class $arch"];
    }

    /**
     * Checks monit.pem: one private key and its certificate (plus any chain).
     * @return array{ok:bool,error?:string,info?:string}
     */
    public static function checkPem(string $pem): array
    {
        $pem = str_replace("\r\n", "\n", $pem);
        if (!preg_match('/-----BEGIN (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |EC |ENCRYPTED )?PRIVATE KEY-----/s', $pem, $k)) {
            return ['ok' => false, 'error' => 'No private key in the file: monit.pem must contain the key and the certificate (cat host.key host.crt > monit.pem).'];
        }
        if (str_contains($k[0], 'ENCRYPTED')) {
            return ['ok' => false, 'error' => 'The private key is protected by a passphrase: Monit cannot use it. Export it without passphrase (openssl rsa -in key -out key).'];
        }
        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $c)) {
            return ['ok' => false, 'error' => 'No certificate in the file: monit.pem must contain the key and the certificate.'];
        }
        $cert = @openssl_x509_read($c[0]);
        $key = @openssl_pkey_get_private($k[0]);
        if ($cert === false || $key === false) {
            return ['ok' => false, 'error' => 'The key or the certificate cannot be read.'];
        }
        if (!openssl_x509_check_private_key($cert, $key)) {
            return ['ok' => false, 'error' => 'The private key does not belong to the (first) certificate of the file.'];
        }
        $info = openssl_x509_parse($cert);
        $cn = $info['subject']['CN'] ?? '?';
        $issuer = $info['issuer']['CN'] ?? ($info['issuer']['O'] ?? '?');
        $until = (int) $info['validTo_time_t'];
        return ['ok' => true, 'info' => 'CN=' . (is_array($cn) ? implode(', ', $cn) : $cn) . ' · issued by ' . (is_array($issuer) ? implode(', ', $issuer) : $issuer)
            . ' · valid until ' . gmdate('Y-m-d', $until) . ($until < time() ? ' (EXPIRED)' : '')
            . (!empty($info['extensions']['subjectAltName']) ? ' · ' . $info['extensions']['subjectAltName'] : '')];
    }

    // --- secrets ----------------------------------------------------------------------

    private static function key(): ?string
    {
        $key = base64_decode((string) Config::get('monit.credentials_key', ''), true);
        return $key !== false && strlen($key) === 32 ? $key : null;
    }

    /** Encrypted with monit.credentials_key when set ("aes256gcm:…"), else "plain:…". */
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
        $key = self::key() ?? throw new \RuntimeException('The package secrets are encrypted: set monit.credentials_key in the Bmon-web configuration.');
        $raw = base64_decode(substr($stored, 10), true);
        $plain = $raw === false ? false : openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, -16), $context);
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt the package secrets: monit.credentials_key changed.');
        }
        return $plain;
    }

    public static function encrypted(): bool
    {
        return self::key() !== null;
    }

    // --- the zip ------------------------------------------------------------------------

    /** Paths inside the install directory. */
    public static function layout(string $base): array
    {
        return [
            'monit' => "$base/bin/monit", 'monitrc' => "$base/conf/monitrc", 'pem' => "$base/conf/monit.pem",
            'conf_dir' => "$base/conf.d", 'script_dir' => "$base/script", 'rc_dir' => "$base/conf",
        ];
    }

    /** Settings of Admin → Configuration that do not match the layout of a package (they drive later updates). */
    public static function mismatches(string $base): array
    {
        $l = self::layout($base);
        $want = [
            'agentconf.monit_dir' => ['Monit include directory on the agents', $l['conf_dir']],
            'agentconf.script_dir' => ['Script directory on the agents', $l['script_dir']],
            'agentconf.monitrc_dir' => ['Monit directory on the agents', $l['rc_dir']],
            'agentconf.agent_monit' => ['Monit binary on the agents', $l['monit']],
        ];
        $out = [];
        foreach ($want as $k => [$label, $v]) {
            if (rtrim((string) Config::get($k, ''), '/') !== $v) {
                $out[$k] = [$label, $v, (string) Config::get($k, '')];
            }
        }
        return $out;
    }

    public static function monitrc(string $base): string
    {
        return "# Generated by Bmon-web (Agent → Installer)\n"
            . "set daemon  60\n"
            . "set log syslog\n"
            . "set pidfile /var/run/monit.pid\n"
            . "set statefile $base/.monit.state\n"
            . "set idfile $base/monit.id\n"
            . "set mailserver localhost\n"
            . "set eventqueue\n"
            . "     basedir $base\n"
            . "     slots 100\n"
            . "include $base/conf.d/*.conf\n";
    }

    /**
     * The three required checks rendered for the install directory.
     * @return array{checks:list<array{file:string,config:string}>,scripts:list<array{name:string,content:string}>,errors:list<string>}
     */
    public static function checks(array $pkg): array
    {
        $l = self::layout($pkg['base_dir']);
        $override = [
            'agentconf.monit_dir' => $l['conf_dir'], 'agentconf.script_dir' => $l['script_dir'],
            'agentconf.monitrc_dir' => $l['rc_dir'], 'agentconf.agent_monit' => $l['monit'],
        ];
        $saved = [];
        foreach ($override as $k => $v) {
            $saved[$k] = Config::get($k);
            Config::set($k, $v);
        }
        try {
            $templates = AgentTemplates::fresh();
            $values = [
                'monit-bmon' => ['user' => $pkg['httpd_user'], 'password' => self::open($pkg['httpd_password'], 'pkg-httpd-' . $pkg['id']),
                    'port' => (string) $pkg['httpd_port'], 'pemfile' => $l['pem']],
                'bmon-update' => [],
                'os-info' => [],
            ];
            $out = ['checks' => [], 'scripts' => [], 'errors' => []];
            foreach (AgentRequired::TEMPLATES as $key) {
                $tpl = $templates[$key];
                $input = [];
                foreach ($tpl['fields'] as $f) {
                    $input[$f['key']] = (string) $f['default'];
                }
                $input = array_merge($input, $values[$key] ?? []);
                [$v, $errors] = AgentTemplates::values($tpl, $input);
                if ($errors) {
                    $out['errors'][] = '“' . $tpl['label'] . '”: ' . implode('; ', array_map(static fn ($k, $e) => "$k: $e", array_keys($errors), $errors));
                    continue;
                }
                $config = AgentTemplates::render($tpl, AgentTemplates::derive($key, $v));
                $name = $v['name'] ?? ($tpl['filename'] ?? $key);
                $out['checks'][] = ['file' => AgentChecks::fileName($name), 'name' => $name, 'config' => $config];
                if ($script = AgentTemplates::script($tpl)) {
                    $out['scripts'][] = $script;
                }
            }
            return $out;
        } finally {
            foreach ($saved as $k => $v) {
                Config::set($k, $v);
            }
        }
    }

    /** The zip: paths relative to the install directory. */
    public static function zip(array $pkg): string
    {
        $c = self::checks($pkg);
        if ($c['errors']) {
            throw new \RuntimeException('The package cannot be built: ' . implode(' ', $c['errors']));
        }
        $zip = new ZipWriter();
        $zip->add('bin/monit', (string) $pkg['monit_bin'], null, 0755);
        $zip->add('conf/monitrc', self::monitrc($pkg['base_dir']), null, 0600);
        $zip->add('conf/monit.pem', self::open($pkg['pem'], 'pkg-pem-' . $pkg['id']), null, 0600);
        foreach ($c['checks'] as $ch) {
            $zip->add('conf.d/' . $ch['file'], "# Generated by Bmon-web (Agent → Installer, package {$pkg['name']})\n\n" . $ch['config'], null, 0600);
        }
        foreach ($c['scripts'] as $s) {
            $zip->add('script/' . $s['name'], AgentChecks::normalizeScript($s['content']), null, 0755);
        }
        return $zip->bytes();
    }

    /** Commands to install a package on a new server. */
    public static function installCommands(array $pkg, string $url): string
    {
        $b = $pkg['base_dir'];
        return "# as root on the new server\n"
            . "mkdir -p $b\n"
            . "cd $b && wget -q -O bmon-pkg.zip '$url' && unzip -o bmon-pkg.zip && rm bmon-pkg.zip\n"
            . "chmod 600 $b/conf/monitrc $b/conf/monit.pem\n"
            . "$b/bin/monit -c $b/conf/monitrc -t\n"
            . "$b/bin/monit -c $b/conf/monitrc\n"
            . "echo \"@reboot $b/bin/monit -c $b/conf/monitrc > /dev/null 2>&1\" >> /var/spool/cron/crontabs/root\n";
    }

    public static function save(?int $id, array $f, ?string $binary, ?string $binName, ?string $binInfo, ?string $pem, ?string $pemInfo, string $by): int
    {
        $now = now_utc();
        if ($id === null) {
            $id = Db::insert(
                'INSERT INTO agent_packages (name, base_dir, monit_bin, monit_bin_name, monit_bin_sha256, monit_bin_info, pem, pem_info,
                                             httpd_user, httpd_password, httpd_port, token, created_at, updated_at, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$f['name'], $f['base_dir'], $binary, $binName, hash('sha256', (string) $binary), $binInfo, '', $pemInfo,
                    $f['httpd_user'], '', $f['httpd_port'], self::newToken(), $now, $now, $by]
            );
        } else {
            Db::exec('UPDATE agent_packages SET name = ?, base_dir = ?, httpd_user = ?, httpd_port = ?, updated_at = ?, updated_by = ? WHERE id = ?',
                [$f['name'], $f['base_dir'], $f['httpd_user'], $f['httpd_port'], $now, $by, $id]);
            if ($binary !== null) {
                Db::exec('UPDATE agent_packages SET monit_bin = ?, monit_bin_name = ?, monit_bin_sha256 = ?, monit_bin_info = ? WHERE id = ?',
                    [$binary, $binName, hash('sha256', $binary), $binInfo, $id]);
            }
            if ($pem !== null) {
                Db::exec('UPDATE agent_packages SET pem_info = ? WHERE id = ?', [$pemInfo, $id]);
            }
        }
        // secrets are bound to the package id
        if ($pem !== null) {
            Db::exec('UPDATE agent_packages SET pem = ? WHERE id = ?', [self::seal($pem, "pkg-pem-$id"), $id]);
        }
        if ($f['httpd_password'] !== '') {
            Db::exec('UPDATE agent_packages SET httpd_password = ? WHERE id = ?', [self::seal($f['httpd_password'], "pkg-httpd-$id"), $id]);
        }
        return $id;
    }
}
