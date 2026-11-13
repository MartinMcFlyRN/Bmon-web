<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\AgentChecks;
use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\CheckGroups;
use Bmon\Web\Db;
use Bmon\Web\HttpException;
use Bmon\Web\ServiceGroups;
use Bmon\Web\View;

/**
 * AgentConf → Group Checks. The checks of a group are handled by the same code
 * as the checks of an agent (newCheck, create, edit, update, delete of
 * AgentConfController), with the group as scope.
 */
final class GroupChecksController extends AgentConfController
{
    protected function scope(int $id): array
    {
        $g = $this->group($id);
        return [
            'kind' => 'group', 'sid' => CheckGroups::sid($id), 'id' => $id, 'base' => "/agentconf/groups/$id",
            'name' => $g['name'], 'monit_hostname' => '$HOST', 'host' => null, 'group' => $g,
            'crumbs' => [['/agentconf', 'AgentConf'], ['/agentconf/groups', 'Group Checks'], ["/agentconf/groups/$id", $g['name']]],
            'back' => 'Back to the group', 'what' => 'this group',
        ];
    }

    private function group(int $id): array
    {
        return CheckGroups::find($id) ?? throw new HttpException(404, 'Group not found.');
    }

    public function index(): string
    {
        return View::render('agentconf/groups/index', ['title' => 'Group Checks', 'nav' => 'agentconf', 'groups' => CheckGroups::all()]);
    }

    public function newGroup(array $errors = [], ?array $form = null): string
    {
        return View::render('agentconf/groups/new', [
            'title' => 'New group · Group Checks', 'nav' => 'agentconf',
            'form' => $form ?? ['name' => '', 'slug' => '', 'description' => ''], 'errors' => $errors,
        ]);
    }

    public function createGroup(): string
    {
        $f = $this->groupForm();
        if ($f['slug'] === '' && $f['name'] !== '') {
            $f['slug'] = CheckGroups::slugify($f['name']);
        }
        $errors = CheckGroups::validate($f['name'], $f['slug'], $f['description'], null);
        if ($errors) {
            http_response_code(422);
            return $this->newGroup($errors, $f);
        }
        $id = CheckGroups::create($f['name'], $f['slug'], $f['description'], (string) Auth::user()['username']);
        Audit::log('agentconf.cgroup_create', "{$f['name']} (id $id, prefix {$f['slug']})");
        flash('success', "Group “{$f['name']}” created. Add its checks, then assign it to the agents.");
        redirect("/agentconf/groups/$id");
    }

    public function show(int $id, array $errors = [], ?array $form = null): string
    {
        $sc = $this->scope($id);
        $g = $sc['group'];
        $checks = AgentChecks::forHost($sc['sid']);
        foreach ($checks as &$c) {
            $c['services'] = AgentChecks::definedServices($c['config'], '$HOST');
        }
        unset($c);
        $enabled = AgentChecks::enabledSet($sc['sid']);
        $hostIds = CheckGroups::hostIds($id);
        $hosts = $hostIds ? Db::all(
            'SELECT h.id, ' . Db::hostName() . ' AS hostname, h.remote_addr, h.offline_since FROM ' . Db::collector('hosts') . ' h '
            . Db::hostMetaJoin() . ' WHERE h.id IN (' . implode(',', $hostIds) . ') ORDER BY hostname') : [];
        $others = Db::all(
            'SELECT h.id, ' . Db::hostName() . ' AS hostname FROM ' . Db::collector('hosts') . ' h ' . Db::hostMetaJoin()
            . ($hostIds ? ' WHERE h.id NOT IN (' . implode(',', $hostIds) . ')' : '') . ' ORDER BY hostname');
        return View::render('agentconf/groups/show', [
            'title' => $g['name'] . ' · Group Checks', 'nav' => 'agentconf',
            'scope' => $sc, 'group' => $g, 'form' => $form ?? $g, 'errors' => $errors,
            'checks' => $checks, 'groupsByCheck' => ServiceGroups::namesByCheck($sc['sid']),
            'syntax' => $enabled ? AgentChecks::syntaxCheck($enabled) : ['ok' => null, 'output' => ''],
            'hosts' => $hosts, 'otherHosts' => $others,
            'monitAvailable' => AgentChecks::monitBinary() !== null,
        ]);
    }

    public function updateGroup(int $id): string
    {
        $g = $this->group($id);
        $f = $this->groupForm();
        $errors = CheckGroups::validate($f['name'], $f['slug'], $f['description'], $id);
        if ($errors) {
            http_response_code(422);
            return $this->show($id, $errors, $f);
        }
        CheckGroups::update($id, $f['name'], $f['slug'], $f['description'], (string) Auth::user()['username']);
        Audit::log('agentconf.cgroup_update', "{$f['name']} (id $id)" . ($g['name'] !== $f['name'] ? " (was {$g['name']})" : '')
            . ($g['slug'] !== $f['slug'] ? ", prefix {$g['slug']} → {$f['slug']}" : ''));
        flash('success', 'Group saved.' . ($g['slug'] !== $f['slug'] ? ' The files of its checks are renamed on the agents with the next zip or publication.' : ''));
        redirect("/agentconf/groups/$id");
    }

    public function deleteGroup(int $id): never
    {
        $g = $this->group($id);
        $n = count(CheckGroups::hostIds($id));
        CheckGroups::delete($id);
        Audit::log('agentconf.cgroup_delete', "{$g['name']} (id $id), was assigned to $n agents");
        flash('success', "Group “{$g['name']}” deleted with its checks. They go away from its $n agent" . ($n === 1 ? '' : 's') . ' with the next zip or publication.');
        redirect('/agentconf/groups');
    }

    public function assignHost(int $id): never
    {
        $g = $this->group($id);
        $hostId = (int) ($_POST['host_id'] ?? 0);
        $host = Db::host($hostId);
        if ($host === null) {
            flash('error', 'Choose an agent.');
            redirect("/agentconf/groups/$id");
        }
        if (CheckGroups::assign($id, $hostId, (string) Auth::user()['username'])) {
            Audit::log('agentconf.group_assign', "{$host['hostname']} (id $hostId): group {$g['name']}");
        }
        $conf = AgentChecks::conflicts($hostId);
        flash($conf ? 'error' : 'success', "“{$host['hostname']}” now gets the checks of this group." . ($conf ? ' But: ' . implode('; ', $conf) . '.' : ''));
        redirect("/agentconf/groups/$id");
    }

    public function unassignHost(int $id, int $hostId): never
    {
        $g = $this->group($id);
        $host = Db::host($hostId);
        if (CheckGroups::unassign($id, $hostId)) {
            Audit::log('agentconf.group_unassign', ($host['hostname'] ?? "host $hostId") . " (id $hostId): group {$g['name']}");
        }
        flash('success', 'Agent removed from the group: its checks go away from it with the next zip or publication.');
        redirect("/agentconf/groups/$id");
    }

    private function groupForm(): array
    {
        $s = static fn (string $k): string => trim(preg_replace('/\s+/', ' ', is_string($_POST[$k] ?? null) ? $_POST[$k] : ''));
        return ['name' => $s('name'), 'slug' => strtolower($s('slug')), 'description' => $s('description')];
    }
}
