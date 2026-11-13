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
 * Agent → Group Checks: sets of checks to copy into agents in one go
 * ("Add group checks" on the agent page). The checks of a group are handled by
 * the same code as the checks of an agent (newCheck, create, edit, update,
 * delete of AgentConfController), with the group as scope.
 */
final class GroupChecksController extends AgentConfController
{
    protected function scope(int $id): array
    {
        $g = $this->group($id);
        return [
            'kind' => 'group', 'sid' => CheckGroups::sid($id), 'id' => $id, 'base' => "/agentconf/groups/$id",
            'name' => $g['name'], 'monit_hostname' => '$HOST', 'host' => null, 'group' => $g,
            'crumbs' => [['/agentconf', 'Agent'], ['/agentconf/groups', 'Group Checks'], ["/agentconf/groups/$id", $g['name']]],
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
            'form' => $form ?? ['name' => '', 'description' => ''], 'errors' => $errors,
        ]);
    }

    public function createGroup(): string
    {
        $f = $this->groupForm();
        $f['slug'] = CheckGroups::freeSlug($f['name']);
        $errors = CheckGroups::validate($f['name'], $f['slug'], $f['description'], null);
        if ($errors) {
            http_response_code(422);
            return $this->newGroup($errors, $f);
        }
        $id = CheckGroups::create($f['name'], $f['slug'], $f['description'], (string) Auth::user()['username']);
        Audit::log('agentconf.cgroup_create', "{$f['name']} (id $id)");
        flash('success', "Group “{$f['name']}” created. Add its checks; then, on an agent, “Add group checks” copies them into it.");
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
        return View::render('agentconf/groups/show', [
            'title' => $g['name'] . ' · Group Checks', 'nav' => 'agentconf',
            'scope' => $sc, 'group' => $g, 'form' => $form ?? $g, 'errors' => $errors,
            'checks' => $checks, 'groupsByCheck' => ServiceGroups::namesByCheck($sc['sid']),
            'syntax' => $enabled ? AgentChecks::syntaxCheck($enabled) : ['ok' => null, 'output' => ''],
            'monitAvailable' => AgentChecks::monitBinary() !== null,
        ]);
    }

    public function updateGroup(int $id): string
    {
        $g = $this->group($id);
        $f = $this->groupForm();
        $f['slug'] = $g['slug'];
        $errors = CheckGroups::validate($f['name'], $f['slug'], $f['description'], $id);
        if ($errors) {
            http_response_code(422);
            return $this->show($id, $errors, $f);
        }
        CheckGroups::update($id, $f['name'], $f['slug'], $f['description'], (string) Auth::user()['username']);
        Audit::log('agentconf.cgroup_update', "{$f['name']} (id $id)" . ($g['name'] !== $f['name'] ? " (was {$g['name']})" : ''));
        flash('success', 'Group saved.');
        redirect("/agentconf/groups/$id");
    }

    public function deleteGroup(int $id): never
    {
        $g = $this->group($id);
        CheckGroups::delete($id);
        Audit::log('agentconf.cgroup_delete', "{$g['name']} (id $id)");
        flash('success', "Group “{$g['name']}” deleted. The checks already copied into agents stay.");
        redirect('/agentconf/groups');
    }

    private function groupForm(): array
    {
        $s = static fn (string $k): string => trim(preg_replace('/\s+/', ' ', is_string($_POST[$k] ?? null) ? $_POST[$k] : ''));
        return ['name' => $s('name'), 'description' => $s('description')];
    }
}
