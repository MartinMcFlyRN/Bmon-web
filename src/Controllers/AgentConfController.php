<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\AgentChecks;
use Bmon\Web\AgentRequired;
use Bmon\Web\AgentRequiredException;
use Bmon\Web\AgentTemplates;
use Bmon\Web\AgentUpdate;
use Bmon\Web\CheckGroups;
use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\DownloadLinks;
use Bmon\Web\HttpException;
use Bmon\Web\Monit;
use Bmon\Web\MonitClient;
use Bmon\Web\ServiceGroups;
use Bmon\Web\View;

/**
 * AgentConf (administrators): Monit checks prepared for each agent, created from
 * templates, editable, validated with `monit -t` and downloaded as a zip.
 */
class AgentConfController
{
    /** Sortable columns of the agent list => default direction. */
    private const SORTS = ['agent' => 'asc', 'address' => 'asc', 'monit' => 'desc', 'services' => 'desc',
        'checks' => 'desc', 'update' => 'desc', 'seen' => 'desc'];

    /** Auto-update state of an agent in the list: [key, label, badge class, rank for sorting]. */
    public static function updateBadge(?array $u): array
    {
        return match (true) {
            $u === null => ['none', '—', '', 0],
            $u['last_status'] === 'rolled_back' && $u['installed_version'] !== $u['version'] => ['rolled_back', 'Rolled back', 'badge-fail', 4],
            $u['last_poll_at'] === null => ['never', 'Not asked yet', 'badge-warn', 3],
            $u['installed_version'] === $u['version'] => ['ok', 'Up to date', 'badge-ok', 1],
            default => ['pending', 'Pending', 'badge-warn', 2],
        };
    }

    public function index(): string
    {
        // offline agents are listed too (their zip can be installed by hand); ?active=1 hides them
        $all = ($_GET['active'] ?? '') !== '1';
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
        $updates = AgentUpdate::overview();

        $sort = array_key_exists($_GET['sort'] ?? '', self::SORTS) ? $_GET['sort'] : 'agent';
        $dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : self::SORTS[$sort];
        $key = static fn (array $a): mixed => match ($sort) {
            'agent' => mb_strtolower((string) $a['hostname']),
            'address' => ($ip = @inet_pton((string) $a['remote_addr'])) !== false ? bin2hex($ip) : (string) $a['remote_addr'],
            'monit' => (string) $a['monit_version'],
            'services' => (int) $a['services_total'],
            'checks' => (int) ($counts[(int) $a['id']]['enabled'] ?? -1),
            'update' => self::updateBadge($updates[(int) $a['id']] ?? null)[3],
            'seen' => (string) $a['last_seen'],
        };
        usort($agents, static function (array $a, array $b) use ($key, $sort, $dir): int {
            $c = $sort === 'monit' ? version_compare($key($a), $key($b)) : $key($a) <=> $key($b);
            $c = $dir === 'asc' ? $c : -$c;
            return $c ?: strnatcasecmp((string) $a['hostname'], (string) $b['hostname']);
        });

        $offline = (int) Db::value('SELECT COUNT(*) FROM ' . Db::collector('hosts') . ' WHERE offline_since IS NOT NULL');
        return View::render('agentconf/index', [
            'title' => 'Agent', 'nav' => 'agentconf',
            'agents' => $agents, 'counts' => $counts, 'updates' => $updates, 'all' => $all, 'offlineCount' => $offline,
            'q' => $q, 'sort' => $sort, 'dir' => $dir,
        ]);
    }

    public function host(int $id, ?array $newLink = null): string
    {
        $host = $this->hostRow($id);
        $checks = AgentChecks::forHost($id);
        if ($checks === [] && Auth::isAdmin()) {
            // a new agent gets the required checks at once
            try {
                $added = AgentRequired::addMissing($host, (string) Auth::user()['username']);
                if ($added) {
                    Audit::log('agentconf.required_add', "{$host['hostname']} (id $id): " . implode(', ', $added) . ' (automatically)');
                    flash('success', 'Required checks added automatically: ' . implode(', ', $added) . '. Review them, then download or deploy.');
                }
            } catch (AgentRequiredException $e) {
                if ($e->added) {
                    Audit::log('agentconf.required_add', "{$host['hostname']} (id $id): " . implode(', ', $e->added) . ' (automatically)');
                    flash('success', 'Required checks added automatically: ' . implode(', ', $e->added) . '.');
                }
                flash('error', $e->getMessage());
            }
            $checks = AgentChecks::forHost($id);
        }
        $managed = [];
        foreach ($checks as &$c) {
            $c['services'] = AgentChecks::definedServices($c['config'], $host['monit_hostname']);
            $c['url'] = "/agentconf/$id/checks/{$c['id']}";
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
            'enabledCount' => count(array_filter($checks, static fn ($c) => (int) $c['enabled'])),
            'hasGroups' => CheckGroups::all() !== [],
            'reported' => $this->reported($id), 'syntax' => $syntax,
            'link' => DownloadLinks::active($id), 'newLink' => $newLink,
            'monitAvailable' => AgentChecks::monitBinary() !== null,
            'update' => $this->updateInfo($host),
            'requiredMissing' => AgentRequired::missing($id),
        ]);
    }

    /** GET /agentconf/{id}/add-group: the groups, with their checks, to copy into the agent. */
    public function addGroupForm(int $id): string
    {
        $host = $this->hostRow($id);
        $groups = CheckGroups::all();
        foreach ($groups as &$g) {
            $g['items'] = AgentChecks::forHost(CheckGroups::sid((int) $g['id']));
            $g['clashes'] = CheckGroups::clashes((int) $g['id'], $id);
        }
        unset($g);
        return View::render('agentconf/add_group', [
            'title' => 'Add group checks · ' . $host['hostname'], 'nav' => 'agentconf', 'host' => $host, 'groups' => $groups,
        ]);
    }

    /** POST /agentconf/{id}/add-group: copies all the checks of a group into the agent (all or nothing). */
    public function addGroup(int $id): never
    {
        $host = $this->hostRow($id);
        $g = CheckGroups::find((int) ($_POST['group_id'] ?? 0)) ?? throw new HttpException(404, 'Group not found.');
        $r = CheckGroups::copyToHost((int) $g['id'], $id, (string) Auth::user()['username']);
        if ($r['clashes']) {
            flash('error', 'Nothing added from “' . $g['name'] . '”: ' . count($r['clashes']) . ' of its checks are already on this agent — '
                . implode('; ', array_map(static fn ($n, $why) => "$n ($why)", array_keys($r['clashes']), $r['clashes']))
                . '. Delete or rename them on the agent, then add the group again.');
            redirect("/agentconf/$id/add-group");
        }
        if (!$r['added']) {
            flash('error', 'The group “' . $g['name'] . '” has no checks.');
            redirect("/agentconf/$id/add-group");
        }
        Audit::log('agentconf.group_copy', "{$host['hostname']} (id $id): from group {$g['name']}: " . implode(', ', $r['added']));
        flash('success', 'Added from “' . $g['name'] . '”: ' . implode(', ', $r['added']) . '. They are independent copies: edit them here.');
        redirect("/agentconf/$id");
    }

    /** POST /agentconf/{id}/required: adds (or enables) the missing required checks. */
    public function addRequired(int $id): never
    {
        $host = $this->hostRow($id);
        try {
            $added = AgentRequired::addMissing($host, (string) Auth::user()['username']);
        } catch (AgentRequiredException $e) {
            if ($e->added) {
                Audit::log('agentconf.required_add', "{$host['hostname']} (id $id): " . implode(', ', $e->added));
                flash('success', 'Required checks added: ' . implode(', ', $e->added) . '.');
            }
            flash('error', $e->getMessage());
            redirect("/agentconf/$id");
        }
        if ($added) {
            Audit::log('agentconf.required_add', "{$host['hostname']} (id $id): " . implode(', ', $added));
        }
        flash('success', $added ? 'Required checks added: ' . implode(', ', $added) . '.' : 'All required checks are already there.');
        redirect("/agentconf/$id");
    }

    /** Template gallery, or the form of the chosen template (?template=key). */
    public function newCheck(int $id, array $errors = [], ?array $values = null, ?array $syntax = null): string
    {
        $sc = $this->scope($id);
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
            'title' => 'New check · ' . $sc['name'], 'nav' => 'agentconf',
            'scope' => $sc, 'templateKey' => $tpl !== null ? $key : '', 'tpl' => $tpl,
            'values' => $values ?? [], 'errors' => $errors, 'syntax' => $syntax,
            'fromReported' => is_string($_GET['name'] ?? null) ? $_GET['name'] : '',
            'existing' => array_column(AgentChecks::forHost($sc['sid']), 'name'),
            'allGroups' => ServiceGroups::all(),
            'selectedGroups' => array_map('intval', (array) ($_POST['groups'] ?? [])),
        ]);
    }

    public function create(int $id): string
    {
        $sc = $this->scope($id);
        $sid = $sc['sid'];
        $key = is_string($_POST['template'] ?? null) ? $_POST['template'] : '';
        $tpl = AgentTemplates::get($key) ?? throw new HttpException(400, 'Unknown template.');

        [$values, $errors] = AgentTemplates::values($tpl, $_POST);
        $name = $values['name'] ?? ($tpl['filename'] ?? $key);
        $config = AgentTemplates::render($tpl, AgentTemplates::derive($key, $values));
        $tplScript = AgentTemplates::script($tpl);
        if (!$errors) {
            $errors = AgentChecks::validate($sid, $name, $config)
                + ($tplScript ? AgentChecks::validateScript($sid, $tplScript['name'], $tplScript['content'], null) : []);
            if (isset($errors['name']) && str_contains($errors['name'], 'already')) {
                $errors['name'] = ucfirst($sc['what']) . ' already has a check with this name.';
            }
            if (isset($errors['script_name'])) {
                $errors['name'] = ucfirst($sc['what']) . ' already has the script ' . $tplScript['name'] . ' (another "' . $tpl['label'] . '" check?).';
                unset($errors['script_name']);
            }
        }
        $syntax = null;
        $groupIds = array_map('intval', array_filter((array) ($_POST['groups'] ?? []), 'is_scalar'));
        if (!$errors) {
            $syntax = AgentChecks::checkWith($sid, null, $name, $config, true, ServiceGroups::namesFor($groupIds));
            if ($syntax['ok'] === false && !isset($_POST['force'])) {
                $errors['_syntax'] = 'monit -t rejected the configuration.';
            }
        }
        if ($errors) {
            http_response_code(422);
            $_REQUEST['template'] = $key;
            return $this->newCheck($id, $errors, $values, $syntax);
        }

        $checkId = AgentChecks::create($sid, $name, $key, $config, true, (string) Auth::user()['username']);
        ServiceGroups::setForCheck($checkId, $groupIds);
        if ($tplScript) {
            AgentChecks::setScript($checkId, $tplScript['name'], $tplScript['content']);
        }
        $gNames = ServiceGroups::namesFor($groupIds);
        Audit::log('agentconf.create', $this->auditWho($sc) . ": $name (template $key)" . ($gNames ? ', groups ' . implode(', ', $gNames) : ''));
        flash('success', "Check “{$name}” added. You can now edit its configuration." . $this->syntaxNote($syntax));
        redirect($sc['base'] . "/checks/$checkId");
    }

    public function edit(int $id, int $checkId, array $errors = [], ?array $form = null, ?array $syntax = null, ?array $scriptSyntax = null): string
    {
        $sc = $this->scope($id);
        $check = $this->check($sc, $checkId);
        return View::render('agentconf/edit', [
            'title' => $check['name'] . ' · ' . $sc['name'], 'nav' => 'agentconf',
            'scope' => $sc, 'check' => $check, 'form' => $form ?? $check, 'errors' => $errors,
            'syntax' => $syntax, 'scriptSyntax' => $scriptSyntax, 'tplInfo' => AgentTemplates::get($check['template']),
            'scriptDir' => AgentChecks::scriptDir(), 'monitDir' => AgentChecks::monitDir(),
            'allGroups' => ServiceGroups::all(),
            'selectedGroups' => $form !== null && isset($form['groups']) ? $form['groups'] : ServiceGroups::idsForCheck($checkId),
            'services' => AgentChecks::definedServices($form['config'] ?? $check['config'], $sc['monit_hostname']),
            'monitAvailable' => AgentChecks::monitBinary() !== null,
        ]);
    }

    public function update(int $id, int $checkId): string
    {
        $sc = $this->scope($id);
        $sid = $sc['sid'];
        $check = $this->check($sc, $checkId);
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
        $errors = AgentChecks::validate($sid, $form['name'], $form['config'], $checkId)
            + AgentChecks::validateScript($sid, $form['script_name'], $form['script'], $checkId);
        $syntax = null;
        $scriptSyntax = null;
        if (!$errors) {
            $syntax = AgentChecks::checkWith($sid, $checkId, $form['name'], $form['config'], (bool) $form['enabled'],
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
        Audit::log('agentconf.update', $this->auditWho($sc) . ": $what" . ($form['enabled'] ? '' : ' (disabled)') . $scriptNote . $groupNote);
        $warn = $form['script_name'] !== '' && !str_starts_with(ltrim($form['script']), '#!')
            ? ' Note: the script has no "#!" line; add "#!/bin/sh" so that Monit can run it.' : '';
        $missing = AgentChecks::missingScripts(AgentChecks::forHost($sid))[$form['name']] ?? [];
        if ($missing && $sc['kind'] === 'host') {
            $warn .= ' Warning: the configuration uses ' . implode(', ', $missing)
                . ', but no check of this agent attaches it: Monit on the agent will refuse the configuration.';
        }
        flash('success', "Check “{$form['name']}” saved." . $this->syntaxNote($syntax) . $warn);
        redirect($sc['base']);
    }

    public function delete(int $id, int $checkId): never
    {
        $sc = $this->scope($id);
        $check = $this->check($sc, $checkId);
        AgentChecks::delete($checkId);
        Audit::log('agentconf.delete', $this->auditWho($sc) . ": {$check['name']}");
        flash('success', "Check “{$check['name']}” deleted. It goes away from the " . ($sc['kind'] === 'host' ? 'host' : 'agents of the group')
            . ' with the next zip or publication.');
        redirect($sc['base']);
    }

    public function download(int $id): never
    {
        $host = $this->hostRow($id);
        if (($why = AgentRequired::refusal($id)) !== null) {
            flash('error', $why);
            redirect("/agentconf/$id");
        }
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
        if (($why = AgentRequired::refusal($id)) !== null) {
            if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
                http_response_code(409);
                header('Content-Type: application/json; charset=utf-8');
                return json_encode(['error' => $why], JSON_UNESCAPED_UNICODE);
            }
            flash('error', $why);
            redirect("/agentconf/$id");
        }
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

    // --- auto-update ----------------------------------------------------------------

    /** GET /agentconf/{id}/update-status: the status part of the Auto-update panel (HTML fragment). */
    public function updateStatus(int $id): string
    {
        $host = $this->hostRow($id);
        header('Cache-Control: no-store');
        return View::render('agentconf/_update_status', [
            'update' => $this->updateInfo($host),
            'enabledCount' => count(AgentChecks::enabledSet($id)),
        ], null);
    }

    /**
     * POST /agentconf/{id}/deploy ("Deploy configuration to host"): publishes the
     * enabled checks (if they changed) and runs the auto-update check of the agent
     * at once ("start" through Monit httpd). If the agent cannot be reached the
     * version stays published and the agent takes it at its next run.
     */
    public function deploy(int $id): never
    {
        $host = $this->hostRow($id);
        $by = (string) Auth::user()['username'];
        $r = AgentUpdate::publish($host, $by);
        if (!$r['ok'] && empty($r['unchanged'])) {
            flash('error', $r['error']);
            redirect("/agentconf/$id");
        }
        $release = $r['release'] ?? AgentUpdate::latest($id);
        if ($release === null) {
            flash('error', 'Nothing to deploy.');
            redirect("/agentconf/$id");
        }
        $short = substr($release['version'], 0, 12);
        if ($r['ok']) {
            Audit::log('agentconf.publish', "{$host['hostname']} (id $id) version $short ({$release['checks']} checks)");
        }
        $warn = AgentUpdate::warnings($id);
        $warnText = $warn ? ' Warning: ' . implode(' ', $warn) : '';
        $what = $r['ok'] ? "Version $short published" : "Version $short (unchanged)";

        $state = AgentUpdate::state($id);
        if (!$r['ok'] && $state !== null && $state['installed_version'] === $release['version']) {
            flash('success', "Nothing changed: the agent already runs version $short.");
            redirect("/agentconf/$id");
        }
        $service = AgentUpdate::updateService($host);
        $reason = match (true) {
            $service === null => 'it has no enabled "Bmon auto-update" check',
            $host['offline_since'] !== null => 'it is offline',
            !MonitClient::available($host) => 'Bmon-web cannot reach its Monit httpd',
            default => null,
        };
        if ($reason === null) {
            $a = MonitClient::action($host, $service, 'start');
            Audit::log('agentconf.deploy', "{$host['hostname']} (id $id) version $short, start $service: " . ($a['ok'] ? 'sent' : 'failed: ' . $a['message']));
            if ($a['ok']) {
                flash($warn ? 'error' : 'success', "$what and sent to the agent: Monit runs “{$service}” at its next cycle, the status below updates when it is installed." . $warnText);
                redirect("/agentconf/$id");
            }
            $reason = 'its Monit did not accept the request (' . $a['message'] . ')';
        }
        flash('error', "$what, but the agent was not contacted now: $reason. It installs the version at its next auto-update run." . $warnText);
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
        if (($why = AgentRequired::refusal((int) $host['id'])) !== null) {
            http_response_code(409);
            header('Content-Type: text/plain; charset=utf-8');
            echo $why, "\n";
            exit;
        }
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

    /** Data of the Auto-update panel of the agent page. */
    private function updateInfo(array $host): array
    {
        $id = (int) $host['id'];
        $release = AgentUpdate::latest($id);
        $state = AgentUpdate::state($id);
        $service = AgentUpdate::updateService($host);
        $reported = $service !== null && Db::value(
            'SELECT 1 FROM ' . Db::collector('services') . ' WHERE host_id = ? AND name = ?', [$id, $service]) !== null;
        return [
            'release' => $release,
            'state' => $state,
            'changed' => $release === null || !hash_equals($release['fingerprint'], AgentChecks::fingerprint($host)),
            'warnings' => AgentUpdate::warnings($id),
            'service' => $service,
            'serviceReported' => $reported,
            'canTrigger' => $reported && $host['offline_since'] === null && MonitClient::available($host),
        ];
    }

    protected function hostRow(int $id): array
    {
        return Db::host($id) ?? throw new HttpException(404, 'Agent not found.');
    }

    /**
     * Owner of the checks handled by newCheck/create/edit/update/delete: an agent
     * here, a check group in GroupChecksController. sid = host_id of agent_checks.
     */
    protected function scope(int $id): array
    {
        $host = $this->hostRow($id);
        return [
            'kind' => 'host', 'sid' => $id, 'id' => $id, 'base' => "/agentconf/$id",
            'name' => $host['hostname'], 'monit_hostname' => $host['monit_hostname'], 'host' => $host,
            'crumbs' => [['/agentconf', 'Agent'], ["/agentconf/$id", $host['hostname']]],
            'back' => 'Back to the agent', 'what' => 'this agent',
        ];
    }

    private function check(array $scope, int $checkId): array
    {
        return AgentChecks::find($scope['sid'], $checkId) ?? throw new HttpException(404, 'Check not found.');
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

    protected function auditWho(array $sc): string
    {
        return $sc['kind'] === 'host' ? "{$sc['name']} (id {$sc['id']})" : "group {$sc['name']} (id {$sc['id']})";
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
