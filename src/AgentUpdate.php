<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * AgentConf auto-update: configurations published for the agents, and the
 * signed requests of bmon-update.sh (the "Bmon auto-update" check) that
 * download them and report the result.
 *
 * Every request is signed with HMAC-SHA256, keyed with the password of Monit
 * httpd ("allow user:password" of the Bmon configuration installed on the
 * agent). Bmon-web knows it from the collector (as Monit reports it) and from
 * the AgentConf checks, so changing it in a check also changes the key once
 * the agent has installed it. The zip is signed back with the same key.
 */
final class AgentUpdate
{
    public const SCRIPT = 'bmon-update.sh';
    public const PROTO = 'bmon-update/1';
    private const MAX_SKEW = 300;       // seconds between the agent clock and ours
    private const KEEP_RELEASES = 5;    // per agent

    // --- releases ---------------------------------------------------------------

    /** Latest published release of an agent, without the zip. */
    public static function latest(int $hostId): ?array
    {
        return Db::one(
            'SELECT id, host_id, version, fingerprint, size, checks, created_by, created_at
               FROM agent_releases WHERE host_id = ? ORDER BY id DESC LIMIT 1',
            [$hostId]
        );
    }

    /** host id => [version, fingerprint, installed, status, poll] for the agent list. */
    public static function overview(): array
    {
        $rows = Db::all(
            'SELECT r.host_id, r.version, r.fingerprint, s.installed_version, s.last_status, s.last_poll_at
               FROM agent_releases r
               JOIN (SELECT host_id, MAX(id) AS id FROM agent_releases GROUP BY host_id) m ON m.id = r.id
               LEFT JOIN agent_update_state s ON s.host_id = r.host_id'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['host_id']] = $r;
        }
        return $out;
    }

    public static function state(int $hostId): ?array
    {
        return Db::one('SELECT * FROM agent_update_state WHERE host_id = ?', [$hostId]);
    }

    /**
     * Publishes the current enabled checks of an agent: the exact zip it will
     * download. Refused when nothing changed or monit -t rejects the set.
     * @return array{ok:bool,error?:string,release?:array}
     */
    public static function publish(array $host, string $by): array
    {
        $id = (int) $host['id'];
        if (($why = AgentRequired::refusal($id)) !== null) {
            return ['ok' => false, 'error' => $why];
        }
        $enabled = AgentChecks::enabledSet($id);
        if ($enabled === []) {
            return ['ok' => false, 'error' => 'There are no enabled checks to publish: the agent would remove all its Bmon checks, including the auto-update itself.'];
        }
        $syntax = AgentChecks::syntaxCheck($enabled);
        if ($syntax['ok'] === false) {
            return ['ok' => false, 'error' => 'monit -t rejects the configuration, fix it before publishing: ' . $syntax['output']];
        }
        $fingerprint = AgentChecks::fingerprint($host);
        $latest = self::latest($id);
        if ($latest !== null && hash_equals($latest['fingerprint'], $fingerprint)) {
            return ['ok' => false, 'error' => 'Nothing changed since the last publication.'];
        }
        $zip = AgentChecks::zip($host);
        $version = hash('sha256', $zip);
        Db::exec(
            'INSERT INTO agent_releases (host_id, version, fingerprint, zip, size, checks, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $version, $fingerprint, $zip, strlen($zip), count($enabled), $by, now_utc()]
        );
        // keep the last few, for the record (the zip of the older ones is not needed)
        $keep = Db::all('SELECT id FROM agent_releases WHERE host_id = ? ORDER BY id DESC LIMIT ' . self::KEEP_RELEASES, [$id]);
        if (count($keep) === self::KEEP_RELEASES) {
            Db::exec('DELETE FROM agent_releases WHERE host_id = ? AND id < ?', [$id, (int) end($keep)['id']]);
        }
        return ['ok' => true, 'release' => self::latest($id)];
    }

    /**
     * Problems of the current (enabled) configuration for the auto-update.
     * @return list<string>
     */
    public static function warnings(int $hostId): array
    {
        $checks = array_filter(AgentChecks::effective($hostId), static fn ($c) => (int) $c['enabled']);
        $out = [];
        if (!array_filter($checks, static fn ($c) => $c['script_name'] === self::SCRIPT)) {
            $out[] = 'No enabled "Bmon auto-update" check: once installed, this configuration will not update itself any more.';
        }
        $creds = [];
        foreach ($checks as $c) {
            $creds = array_merge($creds, self::httpdCredentials($c['config']));
        }
        if ($creds === []) {
            $out[] = 'No "set httpd … allow user:password" in the checks: the auto-update signs its requests with that password, so it would fall back to the one in monitrc.';
        }
        return $out;
    }

    /** Service name of the enabled auto-update check (for "Update now"), or null. */
    public static function updateService(array $host): ?string
    {
        foreach (AgentChecks::effective((int) $host['id']) as $c) {
            if ((int) $c['enabled'] && $c['script_name'] === self::SCRIPT) {
                $names = AgentChecks::definedServices($c['config'], (string) ($host['monit_hostname'] ?? $host['hostname']));
                return $names[0] ?? null;
            }
        }
        return null;
    }

    /**
     * "allow user:password" pairs of a configuration that has "set httpd",
     * the way bmon-update.sh reads them.
     * @return list<array{0:string,1:string}>
     */
    public static function httpdCredentials(string $config): array
    {
        if (!preg_match('/^\s*set\s+httpd\b/mi', $config)) {
            return [];
        }
        preg_match_all('/^\s*allow\s+([A-Za-z0-9._-]+):(?:"([^"]*)"|([^\s"]+))/mi', $config, $m, PREG_SET_ORDER);
        return array_map(static fn ($x) => [$x[1], ($x[2] ?? '') !== '' ? $x[2] : ($x[3] ?? '')], $m);
    }

    // --- signed requests of the agents --------------------------------------------

    /** HMAC of a request or response, as bmon-update.sh computes it. */
    public static function sign(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key);
    }

    /**
     * Checks a signed request: known Monit ID, fresh timestamp, address of the
     * reports (when required) and HMAC with one of the httpd passwords.
     * @return array{0:array,1:string} [host, key]
     * @throws HttpException 400/403 (the reason is kept for the agent page)
     */
    public static function authenticate(array $in, string $data): array
    {
        $monitId = (string) ($in['id'] ?? '');
        $user = (string) ($in['user'] ?? '');
        $ts = (string) ($in['ts'] ?? '');
        $sig = strtolower((string) ($in['sig'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $monitId) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $user)
            || !preg_match('/^\d{9,12}$/', $ts) || !preg_match('/^[0-9a-f]{64}$/', $sig)) {
            throw new HttpException(400, 'Malformed request.');
        }
        $row = Db::one('SELECT id FROM ' . Db::collector('hosts') . ' WHERE monit_id = ?', [$monitId]);
        $host = $row ? Db::host((int) $row['id']) : null;
        if ($host === null) {
            throw new HttpException(403, 'Unknown Monit ID: the host must report to bmon-collector first.');
        }
        $id = (int) $host['id'];
        $fail = static function (string $reason) use ($id): never {
            self::touchState($id, ['last_auth_error' => mb_substr($reason, 0, 255), 'last_auth_error_at' => now_utc()]);
            throw new HttpException(403, 'Not authorized: ' . $reason);
        };

        if (abs(time() - (int) $ts) > self::MAX_SKEW) {
            $fail('the clock of the agent differs by more than ' . self::MAX_SKEW . ' seconds from this server (' . gmdate('Y-m-d H:i:s', (int) $ts) . ' UTC).');
        }
        $ip = client_ip();
        if (Config::get('agentconf.update_check_ip', true) && $ip !== (string) $host['remote_addr']) {
            $fail("request from $ip, while the reports of this host come from {$host['remote_addr']}.");
        }
        $state = self::state($id);
        if ($state !== null && (int) $ts < (int) $state['last_ts']) {
            $fail('timestamp older than the previous request (replayed?).');
        }

        $key = null;
        foreach (self::keys($host, $user) as $candidate) {
            if (hash_equals(self::sign($candidate, $data), $sig)) {
                $key = $candidate;
                break;
            }
        }
        if ($key === null) {
            $fail("wrong signature: the httpd password of user \"$user\" on the agent is neither the one Monit reports nor the one of its AgentConf checks.");
        }
        self::touchState($id, ['last_ts' => (int) $ts, 'last_auth_error' => '']);
        return [$host, $key];
    }

    /** Candidate httpd passwords of a user: as Monit reports it, and as in the AgentConf checks. */
    private static function keys(array $host, string $user): array
    {
        $keys = [];
        try {
            [$u, $p] = MonitClient::credentials($host);
            if ($u === $user && $p !== '') {
                $keys[] = $p;
            }
        } catch (\RuntimeException) {
            // no stored credentials: the checks may still have them
        }
        foreach (AgentChecks::effective((int) $host['id']) as $c) {
            foreach (self::httpdCredentials($c['config']) as [$u, $p]) {
                if ($u === $user && $p !== '') {
                    $keys[] = $p;
                }
            }
        }
        return array_values(array_unique($keys));
    }

    /** GET /agent/v1/config data to sign. */
    public static function configData(array $in): string
    {
        return self::PROTO . '|config|' . ($in['id'] ?? '') . '|' . ($in['ts'] ?? '') . '|' . ($in['have'] ?? '');
    }

    /** POST /agent/v1/result data to sign (the message follows a newline). */
    public static function resultData(array $in): string
    {
        return self::PROTO . '|result|' . ($in['id'] ?? '') . '|' . ($in['ts'] ?? '') . '|' . ($in['version'] ?? '')
            . '|' . ($in['status'] ?? '') . "\n" . ($in['message'] ?? '');
    }

    /** Signature of the zip sent to the agent. */
    public static function zipSignature(string $key, string $monitId, string $ts, string $version): string
    {
        return self::sign($key, self::PROTO . "|zip|$monitId|$ts|$version");
    }

    public static function zipOf(int $releaseId): string
    {
        return (string) Db::value('SELECT zip FROM agent_releases WHERE id = ?', [$releaseId]);
    }

    public static function recordPoll(int $hostId, string $installed): void
    {
        self::touchState($hostId, [
            'last_poll_at' => now_utc(), 'last_poll_ip' => client_ip(),
            'installed_version' => preg_match('/^[0-9a-f]{64}$/', $installed) ? $installed : '',
        ]);
    }

    public static function recordResult(int $hostId, string $version, string $status, string $message): void
    {
        $fields = ['last_status' => $status, 'last_message' => mb_substr($message, 0, 20000), 'last_result_at' => now_utc()];
        if ($status === 'installed') {
            $fields['installed_version'] = $version;
        }
        self::touchState($hostId, $fields);
    }

    private static function touchState(int $hostId, array $fields): void
    {
        $cols = array_keys($fields);
        Db::exec(
            'INSERT INTO agent_update_state (host_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", $cols)),
            array_merge([$hostId], array_values($fields))
        );
    }
}
