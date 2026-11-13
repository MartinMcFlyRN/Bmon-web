<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Checks every agent must have, enabled, before its zip can be downloaded or
 * published: Reporting to Bmon (set mmonit + set httpd), Bmon auto-update and
 * OS information. A new agent gets them automatically; if one is deleted or
 * disabled, download and deploy are refused until it is back.
 */
final class AgentRequired
{
    public const TEMPLATES = ['monit-bmon', 'bmon-update', 'os-info'];

    /**
     * Required templates without an enabled check on the agent.
     * @return list<string> template keys
     */
    public static function missing(int $hostId): array
    {
        $have = [];
        foreach (AgentChecks::forHost($hostId) as $c) {
            if ((int) $c['enabled']) {
                $have[$c['template']] = true;
            }
        }
        return array_values(array_filter(self::TEMPLATES, static fn ($k) => !isset($have[$k])));
    }

    /** Labels of the missing checks, e.g. "Reporting to Bmon, OS information". */
    public static function labels(array $keys): string
    {
        return implode(', ', array_map(static fn ($k) => '“' . (AgentTemplates::get($k)['label'] ?? $k) . '”', $keys));
    }

    /** Message for a refused download or publish, or null when nothing is missing. */
    public static function refusal(int $hostId): ?string
    {
        $missing = self::missing($hostId);
        return $missing ? 'These checks are required and missing or disabled: ' . self::labels($missing)
            . '. Add them back (Agent → Add the required checks) before downloading or deploying.' : null;
    }

    /**
     * Adds the missing required checks: enables a disabled one of the same
     * template, otherwise creates it from the template defaults (for Reporting
     * to Bmon, with the httpd user, password, port and HTTPS the host reports).
     * @return list<string> names of the checks added or enabled
     */
    public static function addMissing(array $host, string $by): array
    {
        $id = (int) $host['id'];
        $done = [];
        $failed = [];
        foreach (self::missing($id) as $key) {
            $disabled = array_values(array_filter(AgentChecks::forHost($id), static fn ($c) => $c['template'] === $key));
            if ($disabled) {
                AgentChecks::setEnabled((int) $disabled[0]['id'], true, $by);
                $done[] = $disabled[0]['name'];
                continue;
            }
            $tpl = AgentTemplates::get($key);
            $input = [];
            foreach ($tpl['fields'] as $f) {
                $input[$f['key']] = (string) $f['default'];
            }
            $input = array_merge($input, self::fromHost($key, $host));
            [$values, $errors] = AgentTemplates::values($tpl, $input);
            if ($errors) {
                $labels = [];
                foreach ($tpl['fields'] as $f) {
                    if (isset($errors[$f['key']])) {
                        $labels[] = '“' . $f['label'] . '” (' . rtrim($errors[$f['key']], '.') . ')';
                    }
                }
                $failed[] = '“' . $tpl['label'] . '”: ' . implode(', ', $labels);
                continue;
            }
            $name = $values['name'] ?? ($tpl['filename'] ?? $key);
            if (AgentChecks::validate($id, $name, AgentTemplates::render($tpl, AgentTemplates::derive($key, $values))) !== []) {
                $name .= '-' . bin2hex(random_bytes(2));   // name taken by another check
            }
            $checkId = AgentChecks::create($id, $name, $key, AgentTemplates::render($tpl, AgentTemplates::derive($key, $values)), true, $by);
            if ($script = AgentTemplates::script($tpl)) {
                AgentChecks::setScript($checkId, $script['name'], $script['content']);
            }
            $done[] = $name;
        }
        if ($failed) {
            throw new AgentRequiredException($done, 'Could not add ' . implode('; ', $failed)
                . ': set its default in Admin → Configuration → AgentConf (e.g. "Bmon-web address") or add the check by hand.');
        }
        return $done;
    }

    /** Values of a template taken from what the host reports. */
    private static function fromHost(string $key, array $host): array
    {
        if ($key !== 'monit-bmon') {
            return [];
        }
        $out = [];
        if ((int) ($host['httpd_port'] ?? 0) > 0) {
            $out['port'] = (string) (int) $host['httpd_port'];
        }
        if (!(int) ($host['httpd_ssl'] ?? 0)) {
            $out['pemfile'] = '';   // plain HTTP today: a pemfile that is not there would make monit -t fail
        }
        try {
            [$user, $pass] = MonitClient::credentials($host);
            if (preg_match('/^[A-Za-z0-9._-]{1,32}$/', $user) && preg_match('/^[A-Za-z0-9._~+=-]{8,64}$/', $pass)) {
                $out['user'] = $user;     // keep the httpd credentials the agent already uses
                $out['password'] = $pass;
            }
        } catch (\RuntimeException) {
            // none stored: the template generates a password
        }
        return $out;
    }
}
