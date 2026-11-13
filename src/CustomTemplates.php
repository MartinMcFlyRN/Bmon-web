<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Agent → Custom Templates: templates written by the administrators, with
 * the same syntax as the built-in ones (body with {{field}} and ?field lines,
 * fields with a type, optional attached script). They appear in "Add check"
 * (for agents and Group Checks) as "custom-<id>"; checks are copies, so editing
 * or deleting a template does not change the checks made from it.
 */
final class CustomTemplates
{
    public const CATEGORY = 'mine';
    public const KEY_PREFIX = 'custom-';
    public const MAX_FIELDS = 20;

    /** Field type => [label, pattern (as in AgentTemplates), error]. */
    public const TYPES = [
        'text' => ['Text (no quotes)', '^[^"\\\\\r\n]{1,200}$', 'Up to 200 characters, no quotes or backslashes.'],
        'integer' => ['Whole number', '^[0-9]{1,9}$', 'A whole number.'],
        'decimal' => ['Number', '^[0-9]{1,6}(\.[0-9]{1,3})?$', 'A number, e.g. 80 or 2.5.'],
        'name' => ['Name (letters, digits, . _ -)', '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$', 'Letters, digits, dot, dash, underscore.'],
        'path' => ['Absolute path', '^/[A-Za-z0-9._/@+:-]*$', 'An absolute path without spaces.'],
        'address' => ['Host name or IP address', '^[A-Za-z0-9.:-]{1,253}$', 'A host name or an IP address.'],
        'url' => ['URL', '^https?://[^"\\\\\s]+$', 'An http(s) URL without spaces or quotes.'],
        'secret' => ['Password / token (no spaces)', '^[^"\\\\\s]{1,128}$', 'Up to 128 characters, no spaces or quotes.'],
    ];

    public static function all(): array
    {
        return Db::all('SELECT * FROM custom_templates ORDER BY label');
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM custom_templates WHERE id = ?', [$id]);
    }

    private static ?array $cache = null;

    /** As AgentTemplates entries, key => template (cached for the request). */
    public static function asTemplates(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        try {
            $rows = self::all();
        } catch (\PDOException) {
            return [];   // table not there yet (migration not run)
        }
        foreach ($rows as $r) {
            $out[self::KEY_PREFIX . $r['id']] = self::toTemplate($r);
        }
        return self::$cache = $out;
    }

    public static function toTemplate(array $r): array
    {
        $fields = [[
            'key' => 'name', 'label' => 'Check name', 'default' => $r['filename'],
            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$', 'required' => true,
            'help' => 'File name on the agent: bmon-<name>.conf', 'error' => 'Letters, digits, dot, dash or underscore.',
        ]];
        foreach (self::decodeFields((string) $r['fields']) as $f) {
            $t = self::TYPES[$f['type']] ?? self::TYPES['text'];
            $fields[] = [
                'key' => $f['key'], 'label' => $f['label'], 'default' => $f['default'], 'pattern' => $t[1],
                'required' => $f['required'], 'help' => $f['help'], 'error' => $t[2],
            ];
        }
        return [
            'category' => self::CATEGORY,
            'label' => $r['label'],
            'description' => $r['description'] !== '' ? $r['description'] : 'Custom template.',
            'fields' => $fields,
            'body' => $r['body'],
            'custom_id' => (int) $r['id'],
            'script' => $r['script_name'] !== '' ? ['name' => $r['script_name'], 'content' => (string) $r['script']] : null,
        ];
    }

    /** @return list<array{key:string,label:string,type:string,default:string,required:bool,help:string}> */
    public static function decodeFields(string $json): array
    {
        $list = json_decode($json, true);
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    /** Fields from the form rows (field_key[], field_label[], …), empty rows dropped. */
    public static function fieldsFromPost(array $post): array
    {
        $col = static fn (string $k): array => array_values(array_map(
            static fn ($v) => is_string($v) ? $v : '', is_array($post[$k] ?? null) ? $post[$k] : []));
        $keys = $col('field_key');
        $labels = $col('field_label');
        $types = $col('field_type');
        $defaults = $col('field_default');
        $helps = $col('field_help');
        $required = $col('field_required');
        $out = [];
        foreach ($keys as $i => $k) {
            $k = trim($k);
            if ($k === '' && trim($labels[$i] ?? '') === '' && trim($defaults[$i] ?? '') === '') {
                continue;
            }
            $out[] = [
                'key' => $k, 'label' => trim($labels[$i] ?? ''), 'type' => $types[$i] ?? 'text',
                'default' => trim($defaults[$i] ?? ''), 'required' => ($required[$i] ?? '0') === '1',
                'help' => trim($helps[$i] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param array $t label, description, filename, fields (list), body, script_name, script
     * @return array<string,string> field => error
     */
    public static function validate(array $t, ?int $id): array
    {
        $e = [];
        if ($t['label'] === '' || mb_strlen($t['label']) > 80) {
            $e['label'] = 'Give it a name (max 80 characters).';
        } elseif (($o = Db::one('SELECT id FROM custom_templates WHERE label = ?', [$t['label']])) && (int) $o['id'] !== $id) {
            $e['label'] = 'Another custom template has this name.';
        }
        if (mb_strlen($t['description']) > 500) {
            $e['description'] = 'Max 500 characters.';
        }
        if (!preg_match(AgentChecks::NAME_PATTERN, $t['filename'])) {
            $e['filename'] = 'Letters, digits, dot, dash or underscore (max 64).';
        }
        if (count($t['fields']) > self::MAX_FIELDS) {
            $e['fields'] = 'At most ' . self::MAX_FIELDS . ' fields.';
        }
        $seen = ['name' => true];
        foreach ($t['fields'] as $i => $f) {
            $n = $i + 1;
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $f['key'])) {
                $e['fields'] = "Field $n: the key must be lowercase letters, digits or _ (starting with a letter), e.g. port.";
                break;
            }
            if (isset($seen[$f['key']])) {
                $e['fields'] = "Field $n: the key “{$f['key']}” is used twice" . ($f['key'] === 'name' ? ' (“name” is the check name, always there)' : '') . '.';
                break;
            }
            $seen[$f['key']] = true;
            if ($f['label'] === '') {
                $e['fields'] = "Field $n ({$f['key']}): give it a label.";
                break;
            }
            if (!isset(self::TYPES[$f['type']])) {
                $e['fields'] = "Field $n ({$f['key']}): unknown type.";
                break;
            }
            if ($f['default'] !== '' && !preg_match('#' . self::TYPES[$f['type']][1] . '#u', $f['default'])) {
                $e['fields'] = "Field $n ({$f['key']}): the default value is not valid for its type — " . self::TYPES[$f['type']][2];
                break;
            }
        }
        if (trim($t['body']) === '') {
            $e['body'] = 'The configuration is empty.';
        } elseif (strlen($t['body']) > 20000) {
            $e['body'] = 'Too long (max 20000 characters).';
        } elseif (preg_match('/^\s*include\s/mi', $t['body'])) {
            $e['body'] = '"include" is not allowed.';
        } else {
            preg_match_all('/\{\{([a-z_][a-z0-9_]*)\}\}|^\s*\?([a-z_][a-z0-9_]*)\s/m', $t['body'], $m);
            $unknown = array_diff(array_unique(array_filter(array_merge($m[1], $m[2]))), array_keys($seen));
            if ($unknown) {
                $e['body'] = 'Unknown fields in the configuration: ' . implode(', ', array_map(static fn ($k) => '{{' . $k . '}}', $unknown))
                    . '. Add them below or fix the name.';
            }
        }
        if (!isset($e['script_name']) && !isset($e['script'])) {
            $s = self::validateScript($t['script_name'], $t['script']);
            $e += $s;
        }
        return $e;
    }

    private static function validateScript(string $name, string $script): array
    {
        if ($name === '' && trim($script) === '') {
            return [];
        }
        if (!preg_match(AgentChecks::SCRIPT_NAME_PATTERN, $name)) {
            return ['script_name' => 'Give the script a file name: letters, digits, dot, dash or underscore, e.g. check_app.sh.'];
        }
        if (trim($script) === '') {
            return ['script' => 'The script is empty.'];
        }
        return [];
    }

    /** The configuration rendered with the default values (for preview and monit -t). */
    public static function preview(array $t): array
    {
        $tpl = self::toTemplate(array_merge($t, ['id' => 0, 'fields' => json_encode($t['fields'])]));
        $values = [];
        foreach ($tpl['fields'] as $f) {
            $values[$f['key']] = (string) $f['default'];
        }
        $config = AgentTemplates::render($tpl, $values);
        return ['config' => $config, 'syntax' => AgentChecks::syntaxCheck([($values['name'] ?: 'preview') => $config])];
    }

    public static function save(array $t, ?int $id, string $by): int
    {
        self::$cache = null;
        $now = now_utc();
        $script = $t['script_name'] === '' ? null : AgentChecks::normalizeScript($t['script']);
        $params = [$t['label'], $t['description'], $t['filename'], json_encode(array_values($t['fields']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            AgentChecks::normalize($t['body']), $t['script_name'], $script, $now, $by];
        if ($id === null) {
            return Db::insert('INSERT INTO custom_templates (label, description, filename, fields, body, script_name, script, updated_at, updated_by, created_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($params, [$now]));
        }
        Db::exec('UPDATE custom_templates SET label = ?, description = ?, filename = ?, fields = ?, body = ?, script_name = ?, script = ?,
                         updated_at = ?, updated_by = ? WHERE id = ?', array_merge($params, [$id]));
        return $id;
    }

    /** Copies a template as "<label> (copy)" (or "(copy 2)", …); returns the new id. */
    public static function clone(int $id, string $by): int
    {
        $r = self::find($id) ?? throw new \RuntimeException('Template not found.');
        $base = mb_substr($r['label'], 0, 66) . ' (copy';
        $label = $base . ')';
        for ($n = 2; Db::value('SELECT 1 FROM custom_templates WHERE label = ?', [$label]) !== null; $n++) {
            $label = "$base $n)";
        }
        self::$cache = null;
        $now = now_utc();
        return Db::insert(
            'INSERT INTO custom_templates (label, description, filename, fields, body, script_name, script, created_at, updated_at, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$label, $r['description'], $r['filename'], $r['fields'], $r['body'], $r['script_name'], $r['script'], $now, $now, $by]
        );
    }

    public static function delete(int $id): void
    {
        self::$cache = null;
        Db::exec('DELETE FROM custom_templates WHERE id = ?', [$id]);
    }

    /** How many checks (agents and groups) were made from a template. */
    public static function usage(int $id): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM agent_checks WHERE template = ?', [self::KEY_PREFIX . $id]);
    }
}
