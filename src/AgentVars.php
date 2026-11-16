<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Variables that Bmon-web fills in the checks of an agent: {{agent.name}},
 * {{agent.ip1}}, {{bmon.url}}… They are kept as written in checks, Group Checks
 * and Custom Templates, and replaced with the values of each agent when its zip
 * is built (download, deploy) and when its checks are validated with monit -t.
 * A deploy is refused while a variable has no value for the agent.
 */
final class AgentVars
{
    public const PATTERN = '/\{\{\s*((?:agent|bmon)\.[a-z0-9_]+)\s*\}\}/';
    /** How many numbered IP variables are listed in the help (more work if the agent has them). */
    private const LISTED = 3;

    /** name => description, for the help boxes. */
    public static function describe(): array
    {
        return [
            'agent.name' => 'host name of the agent, as Monit reports it (like $HOST in Monit)',
            'agent.display_name' => 'name of the agent in Bmon (the custom name if set; it may contain spaces)',
            'agent.id' => 'id of the agent in Bmon',
            'agent.monit_id' => 'Monit ID of the agent',
            'agent.report_ip' => 'address the reports of the agent come from',
            'agent.ip1, agent.ip2, …' => 'public IPv4 addresses of the agent, in interface order (from the OS information check; if it reports none, ip1 is the report address when that is public)',
            'agent.ipv6_1, agent.ipv6_2, …' => 'public IPv6 addresses of the agent',
            'agent.private_ip1, agent.private_ip2, …' => 'private IPv4 addresses of the agent (10/8, 172.16/12, 192.168/16, 100.64/10)',
            'agent.monit_address' => 'address Bmon-web uses for the Monit httpd of the agent',
            'agent.os_id, agent.os_version, agent.arch' => 'operating system, from the OS information check',
            'bmon.url' => 'address of Bmon-web (Admin → Configuration, app.url)',
            'bmon.web_address' => 'address Bmon-web connects from (Admin → Configuration → Bmon-web address)',
        ];
    }

    /** Values for an agent (row of Db::host). */
    public static function forHost(array $host): array
    {
        $os = self::os((int) $host['id']);
        $v = [
            'agent.name' => (string) ($host['monit_hostname'] ?? $host['hostname']),
            'agent.display_name' => (string) $host['hostname'],
            'agent.id' => (string) $host['id'],
            'agent.monit_id' => (string) ($host['monit_id'] ?? ''),
            'agent.report_ip' => (string) ($host['remote_addr'] ?? ''),
            'agent.monit_address' => (string) (($host['monit_address'] ?? '') !== '' ? $host['monit_address'] : ($host['httpd_address'] ?? '')),
            'agent.os_id' => $os['id'] ?? '',
            'agent.os_version' => $os['version'] ?? '',
            'agent.arch' => $os['arch'] ?? '',
        ];
        [$pub4, $pub6, $priv4] = self::classify(array_filter(explode(',', $os['ips'] ?? '')));
        if ($pub4 === [] && self::isPublic4((string) ($host['remote_addr'] ?? ''))) {
            $pub4 = [(string) $host['remote_addr']];
        }
        foreach (array_values($pub4) as $i => $ip) {
            $v['agent.ip' . ($i + 1)] = $ip;
        }
        foreach (array_values($pub6) as $i => $ip) {
            $v['agent.ipv6_' . ($i + 1)] = $ip;
        }
        foreach (array_values($priv4) as $i => $ip) {
            $v['agent.private_ip' . ($i + 1)] = $ip;
        }
        return array_filter($v, static fn ($x) => $x !== '') + self::bmon();
    }

    /** Example values, to validate checks of Group Checks and Custom Templates. */
    public static function sample(): array
    {
        $v = [
            'agent.name' => 'agent-name', 'agent.display_name' => 'Agent name', 'agent.id' => '1', 'agent.monit_id' => str_repeat('0', 32),
            'agent.report_ip' => '192.0.2.10', 'agent.monit_address' => '192.0.2.10',
            'agent.os_id' => 'debian', 'agent.os_version' => '12', 'agent.arch' => 'x86_64',
        ];
        for ($i = 1; $i <= 9; $i++) {
            $v["agent.ip$i"] = '192.0.2.' . (10 + $i);
            $v["agent.ipv6_$i"] = "2001:db8::$i";
            $v["agent.private_ip$i"] = "10.0.0.$i";
        }
        return $v + self::bmon();
    }

    private static function bmon(): array
    {
        return array_filter([
            'bmon.url' => rtrim((string) Config::get('app.url', ''), '/'),
            'bmon.web_address' => (string) Config::get('agentconf.web_address', ''),
        ], static fn ($x) => $x !== '');
    }

    /**
     * Replaces the variables of a text.
     * @return array{0:string,1:list<string>} text, variables without a value (left as written)
     */
    public static function expand(string $text, array $vars): array
    {
        $missing = [];
        $out = preg_replace_callback(self::PATTERN, static function ($m) use ($vars, &$missing) {
            if (isset($vars[$m[1]])) {
                return $vars[$m[1]];
            }
            $missing[$m[1]] = true;
            return $m[0];
        }, $text);
        return [$out, array_keys($missing)];
    }

    /** Variable names used in a text that Bmon-web does not know at all (typos). */
    public static function unknown(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);
        $known = '/^(agent\.(name|display_name|id|monit_id|report_ip|monit_address|os_id|os_version|arch|ip[1-9][0-9]?|ipv6_[1-9][0-9]?|private_ip[1-9][0-9]?)|bmon\.(url|web_address))$/';
        return array_values(array_unique(array_filter($m[1], static fn ($n) => !preg_match($known, $n))));
    }

    /** "name" => [variables without a value] for the enabled checks of an agent. */
    public static function missingForHost(array $host): array
    {
        $vars = self::forHost($host);
        $out = [];
        foreach (AgentChecks::forHost((int) $host['id']) as $c) {
            if ((int) $c['enabled']) {
                [, $miss] = self::expand($c['config'], $vars);
                if ($miss) {
                    $out[$c['name']] = $miss;
                }
            }
        }
        return $out;
    }

    /** Values for the help box of an agent (numbered IPs: as many as it has, at least LISTED). */
    public static function table(?array $host): array
    {
        $vars = $host !== null ? self::forHost($host) : [];
        $rows = [];
        foreach (['agent.name', 'agent.display_name', 'agent.id', 'agent.monit_id', 'agent.report_ip'] as $k) {
            $rows[$k] = $vars[$k] ?? null;
        }
        foreach (['ip', 'ipv6_', 'private_ip'] as $p) {
            for ($i = 1; $i <= self::LISTED || isset($vars["agent.$p$i"]); $i++) {
                $rows["agent.$p$i"] = $vars["agent.$p$i"] ?? null;
            }
        }
        foreach (['agent.monit_address', 'agent.os_id', 'agent.os_version', 'agent.arch', 'bmon.url', 'bmon.web_address'] as $k) {
            $rows[$k] = $host !== null ? ($vars[$k] ?? null) : (self::bmon()[$k] ?? null);
        }
        return $rows;
    }

    /** OS information of an agent (with "ips"), or null. */
    private static function os(int $hostId): ?array
    {
        $out = Db::value('SELECT ' . OsInfo::sql('h') . ' FROM ' . Db::collector('hosts') . ' h WHERE h.id = ?', [$hostId]);
        return OsInfo::parse(is_string($out) ? $out : null);
    }

    /** @return array{0:list<string>,1:list<string>,2:list<string>} public v4, public v6, private v4 */
    private static function classify(array $ips): array
    {
        $pub4 = $pub6 = $priv4 = [];
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                if (self::isPublic4($ip)) {
                    $pub4[] = $ip;
                } elseif (self::isPrivate4($ip)) {
                    $priv4[] = $ip;
                }
            } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $pub6[] = $ip;
            }
        }
        return [array_values(array_unique($pub4)), array_values(array_unique($pub6)), array_values(array_unique($priv4))];
    }

    private static function isPublic4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !self::inCidr($ip, '100.64.0.0', 10)    // carrier-grade NAT
            && !self::inCidr($ip, '192.0.2.0', 24) && !self::inCidr($ip, '198.51.100.0', 24) && !self::inCidr($ip, '203.0.113.0', 24)   // documentation
            && !self::inCidr($ip, '198.18.0.0', 15) && !self::inCidr($ip, '192.0.0.0', 24);    // benchmarking, IETF
    }

    private static function isPrivate4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (self::inCidr($ip, '10.0.0.0', 8) || self::inCidr($ip, '172.16.0.0', 12) || self::inCidr($ip, '192.168.0.0', 16) || self::inCidr($ip, '100.64.0.0', 10));
    }

    private static function inCidr(string $ip, string $net, int $bits): bool
    {
        $mask = -1 << (32 - $bits);
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }
}
