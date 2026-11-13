<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\CustomTemplates;
use Bmon\Web\HttpException;
use Bmon\Web\View;

/** Agent → Custom Templates. */
final class CustomTemplatesController
{
    public function index(): string
    {
        $rows = CustomTemplates::all();
        foreach ($rows as &$r) {
            $r['nfields'] = count(CustomTemplates::decodeFields((string) $r['fields']));
            $r['usage'] = CustomTemplates::usage((int) $r['id']);
        }
        unset($r);
        return View::render('agentconf/templates/index', ['title' => 'Custom Templates', 'nav' => 'agentconf', 'templates' => $rows]);
    }

    public function newForm(): string
    {
        return $this->form(null, [
            'label' => '', 'description' => '', 'filename' => 'my-check',
            'fields' => [['key' => 'path', 'label' => 'Path', 'type' => 'path', 'default' => '/var/www', 'required' => true, 'help' => '']],
            'body' => "check directory {{name}} with path {{path}}\n    if does not exist then alert\n",
            'script_name' => '', 'script' => '',
        ]);
    }

    public function create(): string
    {
        $t = $this->fromPost();
        $errors = CustomTemplates::validate($t, null);
        if ($errors) {
            http_response_code(422);
            return $this->form(null, $t, $errors);
        }
        $id = CustomTemplates::save($t, null, (string) Auth::user()['username']);
        Audit::log('agentconf.template_create', "{$t['label']} (id $id)");
        $p = CustomTemplates::preview($t);
        flash($p['syntax']['ok'] === false ? 'error' : 'success', "Custom template “{$t['label']}” created: it is in Add check under “Custom templates”."
            . ($p['syntax']['ok'] === false ? ' Warning: with the default values monit -t rejects it — ' . $p['syntax']['output'] : ''));
        redirect("/agentconf/templates/$id");
    }

    public function edit(int $id): string
    {
        $r = CustomTemplates::find($id) ?? throw new HttpException(404, 'Template not found.');
        return $this->form($id, ['fields' => CustomTemplates::decodeFields((string) $r['fields']), 'script' => (string) $r['script']] + $r);
    }

    public function update(int $id): string
    {
        $old = CustomTemplates::find($id) ?? throw new HttpException(404, 'Template not found.');
        $t = $this->fromPost();
        $errors = CustomTemplates::validate($t, $id);
        if ($errors) {
            http_response_code(422);
            return $this->form($id, $t, $errors);
        }
        CustomTemplates::save($t, $id, (string) Auth::user()['username']);
        Audit::log('agentconf.template_update', "{$t['label']} (id $id)" . ($t['label'] !== $old['label'] ? " (was {$old['label']})" : ''));
        $p = CustomTemplates::preview($t);
        flash($p['syntax']['ok'] === false ? 'error' : 'success', "Custom template “{$t['label']}” saved. Checks already made from it do not change."
            . ($p['syntax']['ok'] === false ? ' Warning: with the default values monit -t rejects it — ' . $p['syntax']['output'] : ''));
        redirect("/agentconf/templates/$id");
    }

    public function clone(int $id): never
    {
        $r = CustomTemplates::find($id) ?? throw new HttpException(404, 'Template not found.');
        $new = CustomTemplates::clone($id, (string) Auth::user()['username']);
        $copy = CustomTemplates::find($new);
        Audit::log('agentconf.template_clone', "{$r['label']} (id $id) → {$copy['label']} (id $new)");
        flash('success', "“{$r['label']}” cloned as “{$copy['label']}”: rename it and change what you need.");
        redirect("/agentconf/templates/$new");
    }

    public function delete(int $id): never
    {
        $r = CustomTemplates::find($id) ?? throw new HttpException(404, 'Template not found.');
        CustomTemplates::delete($id);
        Audit::log('agentconf.template_delete', "{$r['label']} (id $id)");
        flash('success', "Custom template “{$r['label']}” deleted. The checks made from it are kept.");
        redirect('/agentconf/templates');
    }

    private function fromPost(): array
    {
        $s = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        return [
            'label' => trim(preg_replace('/\s+/', ' ', $s('label'))),
            'description' => trim(preg_replace('/\s+/', ' ', $s('description'))),
            'filename' => trim($s('filename')),
            'fields' => CustomTemplates::fieldsFromPost($_POST),
            'body' => str_replace("\r\n", "\n", $s('body')),
            'script_name' => trim($s('script_name')),
            'script' => str_replace("\r\n", "\n", $s('script')),
        ];
    }

    private function form(?int $id, array $t, array $errors = []): string
    {
        $preview = $errors === [] ? CustomTemplates::preview($t) : null;
        return View::render('agentconf/templates/form', [
            'title' => ($id === null ? 'New custom template' : $t['label']) . ' · Custom Templates', 'nav' => 'agentconf',
            'id' => $id, 't' => $t, 'errors' => $errors, 'preview' => $preview,
            'usage' => $id !== null ? CustomTemplates::usage($id) : 0,
        ]);
    }
}
