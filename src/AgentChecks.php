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
    public const SCRIPT_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/';
    public const SCRIPTS_SUBDIR = 'bmon-scripts';   // folder of the scripts inside the zip (and default on the agents)
    public const MANIFEST = '.bmon-files';         // list of the scripts installed by Bmon, in the script directory
    private const MAX_CONFIG = 20000;
    private const MAX_SCRIPT = 200000;

    /** Directory of the Monit include files on the agents (Debian/Ubuntu default). */
    public static function monitDir(): string
    {
        return rtrim((string) Config::get('agentconf.monit_dir', '/etc/monit/conf.d'), '/') ?: '/etc/monit/conf.d';
    }

    /**
     * Directory of the attached scripts on the agents: agentconf.script_dir, or by
     * default <monit_dir>/bmon-scripts (a subdirectory, ignored by "include conf.d/*").
     */
    public static function scriptDir(): string
    {
        $dir = rtrim(trim((string) Config::get('agentconf.script_dir', '')), '/');
        if ($dir === '' || self::configProblem() !== null) {
            return self::monitDir() . '/' . self::SCRIPTS_SUBDIR;
        }
        return $dir;
    }

    /** Why agentconf.script_dir cannot be used (null = fine or not set). */
    public static function configProblem(): ?string
    {
        $dir = rtrim(trim((string) Config::get('agentconf.script_dir', '')), '/');
        if ($dir === '') {
            return null;
        }
        if (!preg_match('#^/[A-Za-z0-9._/@+-]+$#', $dir) || str_contains($dir, '/../') || str_ends_with($dir, '/..')) {
            return "agentconf.script_dir “{$dir}” is not a plain absolute path: using " . self::monitDir() . '/' . self::SCRIPTS_SUBDIR . '.';
        }
        if ($dir === self::monitDir()) {
            return "agentconf.script_dir cannot be the Monit include directory itself (Monit would read the scripts as configuration): using "
                . self::monitDir() . '/' . self::SCRIPTS_SUBDIR . '.';
        }
        return null;
    }

    public static function scriptPath(string $scriptName): string
    {
        return self::scriptDir() . '/' . $scriptName;
    }

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

    public static function update(int $id, string $name, string $config, bool $enabled, string $by,
                                  string $scriptName = '', string $script = ''): void
    {
        $script = $scriptName === '' ? null : self::normalizeScript($script);
        Db::exec('UPDATE agent_checks SET name = ?, config = ?, script_name = ?, script = ?, enabled = ?, updated_at = ?, updated_by = ?
                   WHERE id = ?',
            [$name, self::normalize($config), $scriptName, $script, $enabled ? 1 : 0, now_utc(), $by, $id]);
    }

    /** Scripts keep their tabs; only the line endings are fixed (CRLF breaks sh). */
    public static function normalizeScript(string $script): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $script)) . "\n";
    }

    /**
     * Checks on the attached script (both fields empty = no script).
     * @return array<string,string> field => error
     */
    public static function validateScript(int $hostId, string $scriptName, string $script, ?int $id): array
    {
        if ($scriptName === '' && trim($script) === '') {
            return [];
        }
        if ($scriptName === '') {
            return ['script_name' => 'Give the script a file name (or empty the script to remove it).'];
        }
        if (!preg_match(self::SCRIPT_NAME_PATTERN, $scriptName)) {
            return ['script_name' => 'Use letters, digits, dot, dash or underscore (max 100), e.g. check_queue.sh.'];
        }
        if (trim($script) === '') {
            return ['script' => 'The script is empty.'];
        }
        if (strlen($script) > self::MAX_SCRIPT) {
            return ['script' => 'The script is too long (max ' . self::MAX_SCRIPT . ' bytes).'];
        }
        $other = Db::one('SELECT id, name FROM agent_checks WHERE host_id = ? AND script_name = ? AND id <> ?',
            [$hostId, $scriptName, (int) $id]);
        if ($other !== null) {
            return ['script_name' => "Check “{$other['name']}” of this agent already has a script with this file name."];
        }
        return [];
    }

    /**
     * Syntax check of a shell script ("sh -n", or "bash -n" for bash scripts).
     * @return array{ok:bool|null,output:string} ok null = not a shell script or no shell here
     */
    public static function scriptSyntax(string $script): array
    {
        $first = strtok($script, "\n") ?: '';
        $shell = match (true) {
            !str_starts_with($first, '#!') => 'sh',
            (bool) preg_match('#^\#!\s*(/usr)?/bin/(env\s+)?bash\b#', $first) => 'bash',
            (bool) preg_match('#^\#!\s*(/usr)?/bin/(env\s+)?(sh|dash)\b#', $first) => 'sh',
            default => null,   // python, perl, ...: not checked
        };
        if ($shell === null || !function_exists('proc_open')) {
            return ['ok' => null, 'output' => ''];
        }
        $bin = trim((string) @shell_exec('command -v ' . $shell . ' 2>/dev/null'));
        if ($bin === '') {
            return ['ok' => null, 'output' => ''];
        }
        $proc = proc_open([$bin, '-n'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin']);
        if (!is_resource($proc)) {
            return ['ok' => null, 'output' => ''];
        }
        fwrite($pipes[0], self::normalizeScript($script));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $out = preg_replace('#^' . preg_quote($bin, '#') . ':\s*#m', '', trim($out));
        return ['ok' => $code === 0, 'output' => "$shell -n: " . ($out !== '' ? $out : 'exit status ' . $code)];
    }

    public static function setScript(int $id, string $scriptName, string $script): void
    {
        Db::exec('UPDATE agent_checks SET script_name = ?, script = ? WHERE id = ?',
            [$scriptName, $scriptName === '' ? null : self::normalizeScript($script), $id]);
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

    /**
     * Enabled checks whose configuration uses a file of the script directory that
     * no enabled check of the agent attaches (Monit on the agent would refuse it).
     * @param array $checks rows of agent_checks; $override = [id, name, config, script_name, enabled] being saved
     * @return array<string,list<string>> check name => missing script paths
     */
    public static function missingScripts(array $checks, ?array $override = null): array
    {
        $rows = [];
        foreach ($checks as $c) {
            $rows[(int) $c['id']] = $c;
        }
        if ($override !== null) {
            $rows[(int) ($override['id'] ?? 0)] = $override + ($rows[(int) ($override['id'] ?? 0)] ?? []);
        }
        $attached = [];
        foreach ($rows as $c) {
            if ((int) $c['enabled'] && ($c['script_name'] ?? '') !== '') {
                $attached[$c['script_name']] = true;
            }
        }
        $dir = preg_quote(self::scriptDir() . '/', '#');
        $missing = [];
        foreach ($rows as $c) {
            if (!(int) $c['enabled']) {
                continue;
            }
            preg_match_all('#' . $dir . '([A-Za-z0-9][A-Za-z0-9._-]*)#', (string) $c['config'], $m);
            foreach (array_unique($m[1]) as $file) {
                if (!isset($attached[$file])) {
                    $missing[$c['name']][] = self::scriptPath($file);
                }
            }
        }
        return $missing;
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
            // Programs exist on the agents, not on this server: monit -t refuses a
            // "check program" whose executable is missing, so for the check only it
            // points to a stub (same line, so line numbers in the messages still match).
            $stub = "$dir/program-stub";
            file_put_contents($stub, "#!/bin/sh\nexit 0\n");
            chmod($stub, 0755);
            foreach ($snippets as $name => $config) {
                $config = preg_replace_callback(
                    '/^(\s*check\s+program\s+(?:"[^"]*"|\S+)\s+with\s+path\s+)(?:"(\S+)([^"]*)"|(\S+))/mi',
                    static fn ($m) => $m[1] . (isset($m[4]) && $m[4] !== '' ? $stub : '"' . $stub . $m[3] . '"'),
                    $config
                );
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
            // start/stop/exec programs exist on the agents, not here: not an error
            $out = preg_replace('/^.*: Program does not exist: .*$\n?/m', '', $out);
            $out = preg_replace('/^\s*(New Monit id: .*|Stored in .*)$\n?/m', '', $out);
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
    public static function checkWith(int $hostId, ?int $id, string $name, string $config, bool $enabled, array $groupNames = []): array
    {
        $set = $enabled ? self::enabledSet($hostId, $id) : [];
        $set[$name] = ServiceGroups::apply(self::normalize($config), $groupNames);
        return self::syntaxCheck($set);
    }

    /**
     * Enabled checks of a host as installed by the zip (name => configuration
     * with its "group" lines), optionally without one check.
     */
    public static function enabledSet(int $hostId, ?int $exceptId = null): array
    {
        $groups = ServiceGroups::namesByCheck($hostId);
        $set = [];
        foreach (self::forHost($hostId) as $c) {
            if ((int) $c['enabled'] && (int) $c['id'] !== $exceptId) {
                $set[$c['name']] = ServiceGroups::apply($c['config'], $groups[(int) $c['id']] ?? []);
            }
        }
        return $set;
    }

    // --- zip --------------------------------------------------------------------

    /**
     * Zip with the enabled checks of a host, their scripts and a README:
     *   bmon-<name>.conf           -> <monit_dir>/
     *   bmon-scripts/<script>      -> <monit_dir>/bmon-scripts/ (0755)
     *   README.txt                 (not to be copied: Monit would read it)
     */
    public static function zip(array $host): string
    {
        $checks = array_filter(self::forHost((int) $host['id']), static fn ($c) => (int) $c['enabled']);
        $groups = ServiceGroups::namesByCheck((int) $host['id']);
        $zip = new ZipWriter();
        $files = [];
        $scripts = [];
        foreach ($checks as $c) {
            $time = strtotime($c['updated_at'] . ' UTC') ?: null;
            $file = self::fileName($c['name']);
            $files[] = $file;
            $header = "# Generated by Bmon-web AgentConf for {$host['hostname']} — do not edit here,\n"
                . "# change it in Bmon-web and download the zip again.\n"
                . '# Check: ' . $c['name'] . ' · created from template: ' . $c['template'] . ' · updated ' . fmt_dt($c['updated_at'], 'Y-m-d H:i') . "\n"
                . ($c['script_name'] !== '' ? '# Script: ' . self::scriptPath($c['script_name']) . "\n" : '')
                . (!empty($groups[(int) $c['id']]) ? '# Service groups: ' . implode(', ', $groups[(int) $c['id']]) . "\n" : '')
                . "\n";
            $zip->add($file, $header . ServiceGroups::apply($c['config'], $groups[(int) $c['id']] ?? []), $time);
            if ($c['script_name'] !== '' && $c['script'] !== null) {
                $zip->add(self::SCRIPTS_SUBDIR . '/' . $c['script_name'], $c['script'], $time, 0755);
                $scripts[] = self::SCRIPTS_SUBDIR . '/' . $c['script_name'];
            }
        }
        // list of the scripts installed by this zip: the next install removes exactly
        // these files from the script directory, and nothing else
        $zip->add(self::SCRIPTS_SUBDIR . '/' . self::MANIFEST,
            implode("\n", array_map(static fn ($p) => basename($p), $scripts)) . ($scripts ? "\n" : ''));
        $zip->add('README.txt', self::readme($host, $files, $scripts));
        return $zip->bytes();
    }

    public static function zipName(array $host): string
    {
        return 'bmon-agentconf-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $host['hostname']) . '-' . gmdate('Ymd-His') . '.zip';
    }

    /** Commands that install a downloaded zip on the agent (as root). */
    public static function installSteps(string $zip): string
    {
        $dir = self::monitDir();
        $sdir = self::scriptDir();
        $sub = self::SCRIPTS_SUBDIR;
        $man = self::MANIFEST;
        return "rm -f $dir/bmon-*.conf\n"
            . "unzip -q -o $zip -x README.txt '$sub/*' -d $dir/\n"
            . "chmod 600 $dir/bmon-*.conf\n"
            . "mkdir -p $sdir\n"
            . "[ -f $sdir/$man ] && (cd $sdir && xargs -r rm -f < $man)\n"
            . "unzip -q -o -j $zip '$sub/*' -d $sdir/\n"
            . "monit -t && monit reload\n";
    }

    private static function readme(array $host, array $files, array $scripts): string
    {
        $dir = self::monitDir();
        $sdir = self::scriptDir();
        $sub = self::SCRIPTS_SUBDIR;
        $man = self::MANIFEST;
        $list = $files ? '  ' . implode("\n  ", $files) : '  (no enabled checks)';
        $slist = $scripts ? '  ' . implode("\n  ", array_map(static fn ($p) => $sdir . '/' . basename($p), $scripts)) : '  (none)';
        $when = fmt_dt(now_utc(), 'Y-m-d H:i T');
        return <<<TXT
        Monit configuration for {$host['hostname']}
        Generated by Bmon-web AgentConf on $when

        Checks (to $dir/):
        $list

        Scripts used by the checks (to $sdir/):
        $slist

        Install on the host, as root (monitrc must include $dir/*,
        the default on Debian/Ubuntu):

          # 1. checks: remove those of a previous zip, so that deleted checks go away
          rm -f $dir/bmon-*.conf
          unzip -o this-file.zip -x README.txt '$sub/*' -d $dir/
          chmod 600 $dir/bmon-*.conf

          # 2. scripts: remove only the ones a previous zip installed (listed in $man)
          mkdir -p $sdir
          [ -f $sdir/$man ] && (cd $sdir && xargs -r rm -f < $man)
          unzip -o -j this-file.zip '$sub/*' -d $sdir/

          # 3. check and apply
          monit -t && monit reload

        The scripts are installed with mode 0755. They must not be placed directly
        in $dir/: Monit would read them as configuration. Do not copy
        README.txt into $dir/ either.

        "monit -t" checks the syntax before reloading: if it reports an error,
        nothing changes until you fix it (in Bmon-web) and reload again.

        TXT;
    }
}
