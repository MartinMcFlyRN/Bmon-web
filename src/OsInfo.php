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

    /**
     * Logo of an OS id: public/assets/os/<id>.svg|png|webp provided by the
     * administrator (no logos are shipped: they are trademarks). Null if none.
     */
    public static function logoUrl(string $id): ?string
    {
        static $cache = [];
        $id = strtolower($id);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,40}$/', $id)) {
            return null;
        }
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = null;
            foreach (['svg', 'png', 'webp'] as $ext) {
                $file = BMON_ROOT . "/public/assets/os/$id.$ext";
                if (is_file($file)) {
                    $cache[$id] = url("/assets/os/$id.$ext") . '?v=' . filemtime($file);
                    break;
                }
            }
        }
        return $cache[$id];
    }

    /**
     * HTML of the OS icon: the logo if available, otherwise a neutral badge with
     * the initials and a colour derived from the id (not a brand colour).
     */
    public static function icon(array $os, string $size = 'md'): string
    {
        $label = $os['pretty'] !== '' ? $os['pretty'] : $os['id'];
        if (($src = self::logoUrl($os['id'])) !== null) {
            return '<img class="os-logo os-logo-' . e($size) . '" src="' . e($src) . '" alt="" title="' . e($label) . '">';
        }
        $base = $os['id'] !== '' ? $os['id'] : $label;
        $letters = preg_replace('/[^A-Za-z0-9]/', '', $base);
        $initials = mb_strtoupper(mb_substr($letters, 0, 1)) . mb_strtolower(mb_substr($letters, 1, 1));
        $hue = hexdec(substr(md5(strtolower($base)), 0, 4)) % 12;   // one of 12 neutral hues
        return '<span class="os-badge os-badge-' . e($size) . ' os-hue-' . $hue . '" title="' . e($label) . '" aria-hidden="true">'
            . e($initials !== '' ? $initials : '?') . '</span>';
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
