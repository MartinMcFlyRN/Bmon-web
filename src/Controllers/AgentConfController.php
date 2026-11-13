<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;
use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\DownloadLinks;
use Bmon\Web\HttpException;
use Bmon\Web\Monit;
use Bmon\Web\ServiceGroups;
use Bmon\Web\View;

/**
 * AgentConf (administrators): Monit checks prepared for each agent, created from
 * templates, editable, validated with `monit -t` and downloaded as a zip.
 */
final class AgentConfController
{
    /** Sortable columns of the agent list => default direction. */
    private const SORTS = ['agent' => 'asc', 'address' => 'asc', 'monit' => 'desc', 'services' => 'desc',
        'checks' => 'desc', 'seen' => 'desc'];

    public function index(): string
    {
        $all = ($_GET['all'] ?? '') === '1';
        $q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        $where = [];
        $params = [];
        if (!$all) {
            $where[] = 'h.offline_since IS NULL';
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(h.hostname LIKE ? OR hm.display_name LIKE ? OR h.remote_addr LIKE ? OR h.os_name LIKE ? OR h.monit_version LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $agents = Db::all(
            'SELECT h.id, ' . Db::hostName() . ' AS hostname, h.remote_addr, h.monit_version, h.os_name, h.os_release,
                    h.services_total, h.last_seen, h.offline_since
               FROM ' . Db::collector('hosts') . ' h ' . Db::hostMetaJoin()
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY hostname',
            $params
        );
        $counts = AgentChecks::counts();

        $sort = array_key_exists($_GET['sort'] ?? '', self::SORTS) ? $_GET['sort'] : 'agent';
        $dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : self::SORTS[$sort];
        $key = static fn (array $a): mixed => match ($sort) {
            'agent' => mb_strtolower((string) $a['hostname']),
            'address' => ($ip = @inet_pton((string) $a['remote_addr'])) !== false ? bin2hex($ip) : (string) $a['remote_addr'],
            'monit' => (string) $a['monit_version'],
            'services' => (int) $a['services_total'],
            'checks' => (int) ($counts[(int) $a['id']]['enabled'] ?? -1),
            'seen' => (string) $a['last_seen'],
        };
        usort($agents, static function (array $a, array $b) use ($key, $sort, $dir): int {
            $c = $sort === 'monit' ? version_compare($key($a), $key($b)) : $key($a) <=> $key($b);
            $c = $dir === 'asc' ? $c : -$c;
            return $c ?: strnatcasecmp((string) $a['hostname'], (string) $b['hostname']);
        });

        $offline = (int) Db::value('SELECT COUNT(*) FROM ' . Db::collector('hosts') . ' WHERE offline_since IS NOT NULL');
        return View::render('agentconf/index', [
            'title' => 'AgentConf', 'nav' => 'agentconf',
            'agents' => $agents, 'counts' => $counts, 'all' => $all, 'offlineCount' => $offline,
            'q' => $q, 'sort' => $sort, 'dir' => $dir,
        ]);
    }

    public function host(int $id, ?array $newLink = null): string
    {
        $host = $this->hostRow($id);
        $checks = AgentChecks::forHost($id);
        $managed = [];
        foreach ($checks as &$c) {
            $c['services'] = AgentChecks::definedServices($c['config'], $host['monit_hostname']);
            foreach ($c['services'] as $svc) {
                $managed[$svc] = $c;
            }
        }
        unset($c);

        $enabled = AgentChecks::enabledSet($id);
        $syntax = $enabled ? AgentChecks::syntaxCheck($enabled) : ['ok' => null, 'output' => ''];

        return View::render('agentconf/host', [
            'title' => 'AgentConf · ' . $host['hostname'], 'nav' => 'agentconf',
            'host' => $host, 'checks' => $checks, 'managed' => $managed,
            'missingScripts' => AgentChecks::missingScripts($checks),
            'groupsByCheck' => ServiceGroups::namesByCheck($id),
            'reported' => $this->reported($id), 'syntax' => $syntax,
            'link' => DownloadLinks::active($id), 'newLink' => $newLink,
            'monitAvailable' => AgentChecks::monitBinary() !== null,
        ]);
    }

    /** Template gallery, or the form of the chosen template (?template=key). */
    public function newCheck(int $id, array $errors = [], ?array $values = null, ?array $syntax = null): string
    {
        $host = $this->hostRow($id);
        $key = is_string($_REQUEST['template'] ?? null) ? $_REQUEST['template'] : '';
        $tpl = AgentTemplates::get($key);

        if ($tpl !== null && $values === null) {
            $values = [];
            foreach ($tpl['fields'] as $f) {
                $values[$f['key']] = $f['default'];
            }
            // created from a service reported by Monit: keep its name
            $from = is_string($_GET['name'] ?? null) ? $_GET['name'] : '';
            if ($from !== '' && isset($values['name']) && preg_match(AgentChecks::NAME_PATTERN, $from)) {
                $values['name'] = $from;
            }
        }
        return View::render('agentconf/new', [
            'title' => 'New check · ' . $host['hostname'], 'nav' => 'agentconf',
            'host' => $host, 'templateKey' => $tpl !== null ? $key : '', 'tpl' => $tpl,
            'values' => $values ?? [], 'errors' => $errors, 'syntax' => $syntax,
            'fromReported' => is_string($_GET['name'] ?? null) ? $_GET['name'] : '',
            'existing' => array_column(AgentChecks::forHost($id), 'name'),
            'allGroups' => ServiceGroups::all(),
            'selectedGroups' => array_map('intval', (array) ($_POST['groups'] ?? [])),
        ]);
    }

    public function create(int $id): string
    {
        $host = $this->hostRow($id);
        $key = is_string($_POST['template'] ?? null) ? $_POST['template'] : '';
        $tpl = AgentTemplates::get($key) ?? throw new HttpException(400, 'Unknown template.');

        [$values, $errors] = AgentTemplates::values($tpl, $_POST);
        $name = $values['name'] ?? ($tpl['filename'] ?? $key);
        $config = AgentTemplates::render($tpl, AgentTemplates::derive($key, $values));
        $tplScript = AgentTemplates::script($tpl);
        if (!$errors) {
            $errors = AgentChecks::validate($id, $name, $config)
                + ($tplScript ? AgentChecks::validateScript($id, $tplScript['name'], $tplScript['content'], null) : []);
            if (isset($errors['script_name'])) {
                $errors['name'] = 'This agent already has the script ' . $tplScript['name'] . ' (another "' . $tpl['label'] . '" check?).';
                unset($errors['script_name']);
            }
        }
        $syntax = null;
        $groupIds = array_map('intval', array_filter((array) ($_POST['groups'] ?? []), 'is_scalar'));
        if (!$errors) {
            $syntax = AgentChecks::checkWith($id, null, $name, $config, true, ServiceGroups::namesFor($groupIds));
            if ($syntax['ok'] === false && !isset($_POST['force'])) {
                $errors['_syntax'] = 'monit -t rejected the configuration.';
            }
        }
        if ($errors) {
            http_response_code(422);
            $_REQUEST['template'] = $key;
            return $this->newCheck($id, $errors, $values, $syntax);
        }

        $checkId = AgentChecks::create($id, $name, $key, $config, true, (string) Auth::user()['username']);
        ServiceGroups::setForCheck($checkId, $groupIds);
        if ($tplScript) {
            AgentChecks::setScript($checkId, $tplScript['name'], $tplScript['content']);
        }
        $gNames = ServiceGroups::namesFor($groupIds);
        Audit::log('agentconf.create', "{$host['hostname']} (id $id): $name (template $key)" . ($gNames ? ', groups ' . implode(', ', $gNames) : ''));
        flash('success', "Check “{$name}” added. You can now edit its configuration." . $this->syntaxNote($syntax));
        redirect("/agentconf/$id/checks/$checkId");
    }

    public function edit(int $id, int $checkId, array $errors = [], ?array $form = null, ?array $syntax = null, ?array $scriptSyntax = null): string
    {
        $host = $this->hostRow($id);
        $check = $this->check($id, $checkId);
        return View::render('agentconf/edit', [
            'title' => $check['name'] . ' · ' . $host['hostname'], 'nav' => 'agentconf',
            'host' => $host, 'check' => $check, 'form' => $form ?? $check, 'errors' => $errors,
            'syntax' => $syntax, 'scriptSyntax' => $scriptSyntax, 'tplInfo' => AgentTemplates::get($check['template']),
            'scriptDir' => AgentChecks::scriptDir(), 'monitDir' => AgentChecks::monitDir(),
            'allGroups' => ServiceGroups::all(),
            'selectedGroups' => $form !== null && isset($form['groups']) ? $form['groups'] : ServiceGroups::idsForCheck($checkId),
            'services' => AgentChecks::definedServices($form['config'] ?? $check['config'], $host['monit_hostname']),
            'monitAvailable' => AgentChecks::monitBinary() !== null,
        ]);
    }

    public function update(int $id, int $checkId): string
    {
        $host = $this->hostRow($id);
        $check = $this->check($id, $checkId);
        $str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        $form = [
            'name' => trim($str('name')),
            'config' => $str('config'),
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
            'script_name' => trim($str('script_name')),
            'script' => $str('script'),
            'groups' => array_map('intval', array_filter((array) ($_POST['groups'] ?? []), 'is_scalar')),
        ];
        if (trim($form['script']) === '' && isset($_POST['remove_script'])) {
            $form['script_name'] = '';
        }
        $errors = AgentChecks::validate($id, $form['name'], $form['config'], $checkId)
            + AgentChecks::validateScript($id, $form['script_name'], $form['script'], $checkId);
        $syntax = null;
        $scriptSyntax = null;
        if (!$errors) {
            $syntax = AgentChecks::checkWith($id, $checkId, $form['name'], $form['config'], (bool) $form['enabled'],
                ServiceGroups::namesFor($form['groups']));
            if ($syntax['ok'] === false && !isset($_POST['force'])) {
                $errors['_syntax'] = 'monit -t rejected the configuration.';
            }
            if ($form['script_name'] !== '') {
                $scriptSyntax = AgentChecks::scriptSyntax($form['script']);
                if ($scriptSyntax['ok'] === false && !isset($_POST['force'])) {
                    $errors['_script'] = 'The shell rejected the script.';
                }
            }
        }
        if ($errors) {
            http_response_code(422);
            return $this->edit($id, $checkId, $errors, $form + $check, $syntax, $scriptSyntax);
        }

        $oldGroups = ServiceGroups::namesFor(ServiceGroups::idsForCheck($checkId));
        AgentChecks::update($checkId, $form['name'], $form['config'], (bool) $form['enabled'], (string) Auth::user()['username'],
            $form['script_name'], $form['script']);
        ServiceGroups::setForCheck($checkId, $form['groups']);
        $newGroups = ServiceGroups::namesFor($form['groups']);
        $what = $form['name'] !== $check['name'] ? "{$check['name']} → {$form['name']}" : $form['name'];
        $scriptNote = match (true) {
            $form['script_name'] === '' && $check['script_name'] !== '' => ', script removed',
            $form['script_name'] !== '' && ($form['script_name'] !== $check['script_name']
                || AgentChecks::normalizeScript($form['script']) !== (string) $check['script']) => ", script {$form['script_name']}",
            default => '',
        };
        $groupNote = $oldGroups !== $newGroups ? ', groups: ' . ($newGroups ? implode(', ', $newGroups) : 'none') : '';
        Audit::log('agentconf.update', "{$host['hostname']} (id $id): $what" . ($form['enabled'] ? '' : ' (disabled)') . $scriptNote . $groupNote);
        $warn = $form['script_name'] !== '' && !str_starts_with(ltrim($form['script']), '#!')
            ? ' Note: the script has no "#!" line; add "#!/bin/sh" so that Monit can run it.' : '';
        $missing = AgentChecks::missingScripts(AgentChecks::forHost($id))[$form['name']] ?? [];
        if ($missing) {
            $warn .= ' Warning: the configuration uses ' . implode(', ', $missing)
                . ', but no check of this agent attaches it: Monit on the agent will refuse the configuration.';
        }
        flash('success', "Check “{$form['name']}” saved." . $this->syntaxNote($syntax) . $warn);
        redirect("/agentconf/$id");
    }

    public function delete(int $id, int $checkId): never
    {
        $host = $this->hostRow($id);
        $check = $this->check($id, $checkId);
        AgentChecks::delete($checkId);
        Audit::log('agentconf.delete', "{$host['hostname']} (id $id): {$check['name']}");
        flash('success', "Check “{$check['name']}” deleted. It goes away from the host when you install the next zip.");
        redirect("/agentconf/$id");
    }

    public function download(int $id): never
    {
        $host = $this->hostRow($id);
        $bytes = AgentChecks::zip($host);
        Audit::log('agentconf.download', "{$host['hostname']} (id $id)");
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . AgentChecks::zipName($host) . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    // --- download links (no sign-in, for the agents) ----------------------------

    /**
     * POST /agentconf/{id}/link: new link (revokes the previous ones). Answers JSON
     * to app.js; without JavaScript, the agent page with the link in the dialog.
     */
    public function createLink(int $id): string
    {
        $host = $this->hostRow($id);
        $link = DownloadLinks::create($id, (string) Auth::user()['username']);
        Audit::log('agentconf.link_create', "{$host['hostname']} (id $id), expires " . fmt_dt($link['expires_at'], 'Y-m-d H:i'));
        $payload = [
            'url' => $link['url'],
            'expires' => fmt_dt($link['expires_at'], 'Y-m-d H:i'),
            'commands' => DownloadLinks::installCommands($link['url']),
        ];
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $this->host($id, $payload);
    }

    public function revokeLink(int $id): never
    {
        $host = $this->hostRow($id);
        $n = DownloadLinks::revokeHost($id);
        if ($n > 0) {
            Audit::log('agentconf.link_revoke', "{$host['hostname']} (id $id)");
        }
        flash('success', $n > 0 ? 'Download link revoked: it no longer works.' : 'There was no active download link.');
        redirect("/agentconf/$id");
    }

    /** GET /dl/{token}: the zip, without signing in. Plain-text errors, for curl. */
    public function publicDownload(string $token): never
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        [$state, $row] = DownloadLinks::resolve($token);
        $host = $state === 'ok'
            ? Db::host((int) $row['host_id'])
            : null;
        if ($state !== 'ok' || $host === null) {
            http_response_code($state === 'expired' || $state === 'revoked' ? 410 : 404);
            header('Content-Type: text/plain; charset=utf-8');
            echo match ($state) {
                'expired' => "This download link has expired: create a new one in Bmon-web (AgentConf).\n",
                'revoked' => "This download link has been revoked: create a new one in Bmon-web (AgentConf).\n",
                default => "Unknown download link.\n",
            };
            exit;
        }
        $hostFull = $this->hostRow((int) $host['id']);
        $bytes = AgentChecks::zip($hostFull);
        $ip = client_ip();
        DownloadLinks::markUsed((int) $row['id'], $ip);
        Audit::log('agentconf.link_download', "{$host['hostname']} (id {$host['id']}) from $ip",
            ['id' => null, 'username' => 'link:' . $row['created_by']]);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . AgentChecks::zipName($hostFull) . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    // -------------------------------------------------------------------------

    private function hostRow(int $id): array
    {
        return Db::host($id) ?? throw new HttpException(404, 'Agent not found.');
    }

    private function check(int $id, int $checkId): array
    {
        return AgentChecks::find($id, $checkId) ?? throw new HttpException(404, 'Check not found.');
    }

    /** Services Monit reports for the host (its current configuration, whoever wrote it). */
    private function reported(int $id): array
    {
        return Db::all(
            'SELECT name, type, status, monitor FROM ' . Db::collector('services') . '
              WHERE host_id = ? ORDER BY FIELD(type, ' . implode(',', Monit::TYPE_ORDER) . '), name',
            [$id]
        );
    }

    private function syntaxNote(?array $syntax): string
    {
        return match ($syntax['ok'] ?? null) {
            true => ' monit -t: syntax OK.',
            false => ' Saved although monit -t reported errors.',
            default => ' (not checked: monit is not installed on this server)',
        };
    }
}
