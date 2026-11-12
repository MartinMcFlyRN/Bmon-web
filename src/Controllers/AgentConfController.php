<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\AgentChecks;
use Bmon\Web\AgentTemplates;
use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\HttpException;
use Bmon\Web\Monit;
use Bmon\Web\View;

/**
 * AgentConf (administrators): Monit checks prepared for each agent, created from
 * templates, editable, validated with `monit -t` and downloaded as a zip.
 */
final class AgentConfController
{
    public function index(): string
    {
        $all = ($_GET['all'] ?? '') === '1';
        $agents = Db::all(
            'SELECT id, hostname, remote_addr, monit_version, os_name, os_release, services_total, last_seen, offline_since
               FROM ' . Db::collector('hosts')
            . ($all ? '' : ' WHERE offline_since IS NULL') . ' ORDER BY hostname'
        );
        $offline = (int) Db::value('SELECT COUNT(*) FROM ' . Db::collector('hosts') . ' WHERE offline_since IS NOT NULL');
        return View::render('agentconf/index', [
            'title' => 'AgentConf', 'nav' => 'agentconf',
            'agents' => $agents, 'counts' => AgentChecks::counts(), 'all' => $all, 'offlineCount' => $offline,
        ]);
    }

    public function host(int $id): string
    {
        $host = $this->hostRow($id);
        $checks = AgentChecks::forHost($id);
        $managed = [];
        foreach ($checks as &$c) {
            $c['services'] = AgentChecks::definedServices($c['config'], $host['hostname']);
            foreach ($c['services'] as $svc) {
                $managed[$svc] = $c;
            }
        }
        unset($c);

        $enabled = [];
        foreach ($checks as $c) {
            if ((int) $c['enabled']) {
                $enabled[$c['name']] = $c['config'];
            }
        }
        $syntax = $enabled ? AgentChecks::syntaxCheck($enabled) : ['ok' => null, 'output' => ''];

        return View::render('agentconf/host', [
            'title' => 'AgentConf · ' . $host['hostname'], 'nav' => 'agentconf',
            'host' => $host, 'checks' => $checks, 'managed' => $managed,
            'reported' => $this->reported($id), 'syntax' => $syntax,
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
        if (!$errors) {
            $errors = AgentChecks::validate($id, $name, $config);
        }
        $syntax = null;
        if (!$errors) {
            $syntax = AgentChecks::checkWith($id, null, $name, $config, true);
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
        Audit::log('agentconf.create', "{$host['hostname']} (id $id): $name (template $key)");
        flash('success', "Check “{$name}” added. You can now edit its configuration." . $this->syntaxNote($syntax));
        redirect("/agentconf/$id/checks/$checkId");
    }

    public function edit(int $id, int $checkId, array $errors = [], ?array $form = null, ?array $syntax = null): string
    {
        $host = $this->hostRow($id);
        $check = $this->check($id, $checkId);
        return View::render('agentconf/edit', [
            'title' => $check['name'] . ' · ' . $host['hostname'], 'nav' => 'agentconf',
            'host' => $host, 'check' => $check, 'form' => $form ?? $check, 'errors' => $errors,
            'syntax' => $syntax, 'template' => AgentTemplates::get($check['template']),
            'services' => AgentChecks::definedServices($form['config'] ?? $check['config'], $host['hostname']),
            'monitAvailable' => AgentChecks::monitBinary() !== null,
        ]);
    }

    public function update(int $id, int $checkId): string
    {
        $host = $this->hostRow($id);
        $check = $this->check($id, $checkId);
        $form = [
            'name' => trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : ''),
            'config' => is_string($_POST['config'] ?? null) ? $_POST['config'] : '',
            'enabled' => isset($_POST['enabled']) ? 1 : 0,
        ];
        $errors = AgentChecks::validate($id, $form['name'], $form['config'], $checkId);
        $syntax = null;
        if (!$errors) {
            $syntax = AgentChecks::checkWith($id, $checkId, $form['name'], $form['config'], (bool) $form['enabled']);
            if ($syntax['ok'] === false && !isset($_POST['force'])) {
                $errors['_syntax'] = 'monit -t rejected the configuration.';
            }
        }
        if ($errors) {
            http_response_code(422);
            return $this->edit($id, $checkId, $errors, $form + $check, $syntax);
        }

        AgentChecks::update($checkId, $form['name'], $form['config'], (bool) $form['enabled'], (string) Auth::user()['username']);
        $what = $form['name'] !== $check['name'] ? "{$check['name']} → {$form['name']}" : $form['name'];
        Audit::log('agentconf.update', "{$host['hostname']} (id $id): $what" . ($form['enabled'] ? '' : ' (disabled)'));
        flash('success', "Check “{$form['name']}” saved." . $this->syntaxNote($syntax));
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

    // -------------------------------------------------------------------------

    private function hostRow(int $id): array
    {
        return Db::one('SELECT id, hostname, remote_addr, monit_version, os_name, os_release, services_total,
                               last_seen, offline_since, control_file
                          FROM ' . Db::collector('hosts') . ' WHERE id = ?', [$id])
            ?? throw new HttpException(404, 'Agent not found.');
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
