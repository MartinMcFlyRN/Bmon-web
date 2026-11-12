<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * AgentConf: Monit check definitions kept in Bmon-web for each agent (host),
 * their syntax validation with `monit -t`, and the zip to install them.
 *
 * Each check is a monitrc snippet; in the zip it becomes conf.d/bmon-<name>.conf.
 */
final class AgentChecks
{
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';
    private const MAX_CONFIG = 20000;

    public static function forHost(int $hostId): array
    {
        return Db::all('SELECT * FROM agent_checks WHERE host_id = ? ORDER BY name', [$hostId]);
    }

    public static function find(int $hostId, int $id): ?array
    {
        return Db::one('SELECT * FROM agent_checks WHERE host_id = ? AND id = ?', [$hostId, $id]);
    }

    /** Number of checks per host id. */
    public static function counts(): array
    {
        $out = [];
        foreach (Db::all('SELECT host_id, COUNT(*) AS n, SUM(enabled) AS enabled FROM agent_checks GROUP BY host_id') as $r) {
            $out[(int) $r['host_id']] = $r;
        }
        return $out;
    }

    public static function create(int $hostId, string $name, string $template, string $config, bool $enabled, string $by): int
    {
        $now = now_utc();
        return Db::insert(
            'INSERT INTO agent_checks (host_id, name, template, config, enabled, created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$hostId, $name, $template, self::normalize($config), $enabled ? 1 : 0, $now, $now, $by]
        );
    }

    public static function update(int $id, string $name, string $config, bool $enabled, string $by): void
    {
        Db::exec('UPDATE agent_checks SET name = ?, config = ?, enabled = ?, updated_at = ?, updated_by = ? WHERE id = ?',
            [$name, self::normalize($config), $enabled ? 1 : 0, now_utc(), $by, $id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM agent_checks WHERE id = ?', [$id]);
    }

    /** Unix newlines, no trailing spaces, one final newline. */
    public static function normalize(string $config): string
    {
        $config = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $config);
        $config = preg_replace('/[ ]+$/m', '', $config);
        return rtrim($config) . "\n";
    }

    /** File name of a check in the zip / on the agent. */
    public static function fileName(string $name): string
    {
        return 'bmon-' . $name . '.conf';
    }

    /**
     * Names of the services a snippet defines ("check <type> <name>"), with $HOST resolved.
     * @return list<string>
     */
    public static function definedServices(string $config, string $hostname): array
    {
        preg_match_all('/^\s*check\s+(?:system|process|file|fifo|filesystem|directory|host|network|program)\s+("[^"]+"|\S+)/mi', $config, $m);
        return array_map(static fn ($n) => str_replace('$HOST', $hostname, trim($n, '"')), $m[1]);
    }

    /**
     * Checks done before saving.
     * @return array<string,string> field => error
     */
    public static function validate(int $hostId, string $name, string $config, ?int $id = null): array
    {
        $errors = [];
        if (!preg_match(self::NAME_PATTERN, $name)) {
            $errors['name'] = 'Use letters, digits, dot, dash or underscore (max 64), starting with a letter or digit.';
        } else {
            $other = Db::one('SELECT id FROM agent_checks WHERE host_id = ? AND name = ?', [$hostId, $name]);
            if ($other !== null && (int) $other['id'] !== $id) {
                $errors['name'] = 'This agent already has a check with this name.';
            }
        }
        if (trim($config) === '') {
            $errors['config'] = 'The configuration is empty.';
        } elseif (strlen($config) > self::MAX_CONFIG) {
            $errors['config'] = 'The configuration is too long (max ' . self::MAX_CONFIG . ' characters).';
        } elseif (preg_match('/^\s*include\s/mi', $config)) {
            $errors['config'] = '"include" is not allowed: every file of the zip must be self-contained.';
        }
        return $errors;
    }

    // --- syntax check with monit -t ---------------------------------------------

    /** Path of the monit binary used for validation, or null if not available. */
    public static function monitBinary(): ?string
    {
        static $bin = false;
        if ($bin !== false) {
            return $bin;
        }
        if (!function_exists('proc_open')) {
            return $bin = null;
        }
        $configured = trim((string) Config::get('agentconf.monit_binary', ''));
        if ($configured === 'none') {
            return $bin = null;
        }
        if ($configured !== '') {
            return $bin = $configured;
        }
        $found = trim((string) @shell_exec('command -v monit 2>/dev/null'));
        return $bin = ($found !== '' ? $found : null);
    }

    /**
     * Runs `monit -t` on a set of snippets, as they would be installed together.
     * @param array<string,string> $snippets name => config
     * @return array{ok:bool|null,output:string} ok null = monit not available
     */
    public static function syntaxCheck(array $snippets): array
    {
        $bin = self::monitBinary();
        if ($bin === null) {
            return ['ok' => null, 'output' => ''];
        }
        $dir = sys_get_temp_dir() . '/bmon-agentconf-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir . '/conf.d', 0700, true)) {
            return ['ok' => null, 'output' => 'cannot create a temporary directory'];
        }
        try {
            foreach ($snippets as $name => $config) {
                file_put_contents("$dir/conf.d/" . self::fileName($name), $config);
            }
            $rc = "set daemon 60\nset idfile $dir/monit.id\nset statefile $dir/monit.state\ninclude $dir/conf.d/*\n";
            file_put_contents("$dir/monitrc", $rc);
            chmod("$dir/monitrc", 0600);

            $proc = proc_open([$bin, '-t', '-c', "$dir/monitrc"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir,
                ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $dir]);
            if (!is_resource($proc)) {
                return ['ok' => null, 'output' => 'cannot run monit'];
            }
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            // show the file names of the zip, not the temporary paths
            $out = str_replace(["$dir/conf.d/", "$dir/"], '', trim($out));
            $out = preg_replace('/^Control file syntax OK\s*$/m', '', $out);
            // an incomplete statement at the end of a file is reported on the include line
            $out = preg_replace('/^monitrc:\d+: /m', 'end of a file (incomplete statement?): ', $out);
            return ['ok' => $code === 0, 'output' => trim($out)];
        } finally {
            foreach (glob("$dir/conf.d/*") ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob("$dir/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir("$dir/conf.d");
            @rmdir($dir);
        }
    }

    /**
     * Syntax check of the agent's enabled checks with one check added or replaced.
     * A disabled check is checked on its own.
     */
    public static function checkWith(int $hostId, ?int $id, string $name, string $config, bool $enabled): array
    {
        $set = [];
        if ($enabled) {
            foreach (self::forHost($hostId) as $c) {
                if ((int) $c['enabled'] && (int) $c['id'] !== $id) {
                    $set[$c['name']] = $c['config'];
                }
            }
        }
        $set[$name] = self::normalize($config);
        return self::syntaxCheck($set);
    }

    // --- zip --------------------------------------------------------------------

    /** Zip with the enabled checks of a host and a README with the instructions. */
    public static function zip(array $host): string
    {
        $checks = array_filter(self::forHost((int) $host['id']), static fn ($c) => (int) $c['enabled']);
        $zip = new ZipWriter();
        $files = [];
        foreach ($checks as $c) {
            $file = self::fileName($c['name']);
            $files[] = $file;
            $header = "# Generated by Bmon-web AgentConf for {$host['hostname']} — do not edit here,\n"
                . "# change it in Bmon-web and download the zip again.\n"
                . '# Check: ' . $c['name'] . ' · template: ' . $c['template'] . ' · updated ' . fmt_dt($c['updated_at'], 'Y-m-d H:i') . "\n\n";
            $zip->add('conf.d/' . $file, $header . $c['config'], strtotime($c['updated_at'] . ' UTC') ?: null);
        }
        $zip->add('README.txt', self::readme($host, $files));
        return $zip->bytes();
    }

    public static function zipName(array $host): string
    {
        return 'bmon-agentconf-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $host['hostname']) . '-' . gmdate('Ymd-His') . '.zip';
    }

    private static function readme(array $host, array $files): string
    {
        $list = $files ? '  ' . implode("\n  ", $files) : '  (no enabled checks)';
        $when = fmt_dt(now_utc(), 'Y-m-d H:i T');
        return <<<TXT
        Monit configuration for {$host['hostname']}
        Generated by Bmon-web AgentConf on $when

        Files (conf.d/):
        $list

        Install on the host (Debian/Ubuntu: monitrc includes /etc/monit/conf.d/*):

          # remove the files of a previous zip, so that deleted checks go away
          rm -f /etc/monit/conf.d/bmon-*.conf
          unzip -o -j this-file.zip 'conf.d/*' -d /etc/monit/conf.d/
          chmod 600 /etc/monit/conf.d/bmon-*.conf
          monit -t && monit reload

        Other systems: copy the files where your monitrc includes them, e.g. add
          include /etc/monit.d/*
        to monitrc.

        "monit -t" checks the syntax before reloading: if it reports an error,
        nothing changes until you fix it (in Bmon-web) and reload again.

        TXT;
    }
}
