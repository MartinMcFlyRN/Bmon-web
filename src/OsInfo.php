<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Operating system reported by the "OS information" check of AgentConf
 * (resources/agentconf/bmon-osinfo.sh): the output of a Monit "check program"
 * that starts with "BMON_OS", e.g.
 *
 *   BMON_OS pretty="Debian GNU/Linux 12 (bookworm)" id="debian" version="12" codename="bookworm" kernel="…" arch="x86_64"
 */
final class OsInfo
{
    public const MARKER = 'BMON_OS ';

    /** @return array{pretty:string,id:string,version:string,codename:string,kernel:string,arch:string}|null */
    public static function parse(?string $output): ?array
    {
        $output = trim((string) $output);
        if (!str_starts_with($output, self::MARKER)) {
            return null;
        }
        $out = ['pretty' => '', 'id' => '', 'version' => '', 'codename' => '', 'kernel' => '', 'arch' => ''];
        preg_match_all('/\b(pretty|id|version|codename|kernel|arch)="([^"]*)"/', $output, $m, PREG_SET_ORDER);
        foreach ($m as [, $k, $v]) {
            $out[$k] = mb_substr($v, 0, 120);
        }
        return $out['pretty'] !== '' || $out['id'] !== '' ? $out : null;
    }

    /** From the services of a host (rows with decoded `data`). */
    public static function fromServices(array $services): ?array
    {
        foreach ($services as $s) {
            if ((int) $s['type'] === 7 && ($info = self::parse($s['data']['program']['output'] ?? null)) !== null) {
                return $info;
            }
        }
        return null;
    }

    /** SQL expression: the BMON_OS output of host alias $h (NULL if none). */
    public static function sql(string $h = 'h'): string
    {
        $out = "JSON_UNQUOTE(JSON_EXTRACT(o.data, '$.program.output'))";
        return '(SELECT ' . $out . ' FROM ' . Db::collector('services') . " o
                 WHERE o.host_id = $h.id AND o.type = 7 AND $out LIKE 'BMON\\_OS %' LIMIT 1)";
    }
}
