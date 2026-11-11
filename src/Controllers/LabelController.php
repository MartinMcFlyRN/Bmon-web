<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\HttpException;
use Bmon\Web\Labels;
use Bmon\Web\View;

/**
 * Labels menu (list, create, edit, delete) and assignment of labels to hosts.
 * Everyone can view; changes are reserved to administrators (enforced by the routes).
 */
final class LabelController
{
    public function index(): string
    {
        return View::render('labels/index', [
            'title' => 'Labels',
            'nav' => 'labels',
            'labels' => Labels::allWithCounts(),
            'canEdit' => Auth::isAdmin(),
        ]);
    }

    public function newLabel(array $errors = [], array $form = ['name' => '', 'description' => '']): string
    {
        return View::render('labels/form', [
            'title' => 'New label', 'nav' => 'labels',
            'isNew' => true, 'form' => $form, 'errors' => $errors, 'hosts' => [],
        ]);
    }

    public function create(): string
    {
        [$name, $description] = $this->input();
        if ($errors = Labels::validate($name, $description)) {
            http_response_code(422);
            return $this->newLabel($errors, ['name' => $name, 'description' => $description]);
        }
        $id = Labels::create($name, $description);
        Audit::log('label.create', "$name (id $id)");
        flash('success', "Label “{$name}” created.");
        redirect('/labels');
    }

    public function show(int $id, array $errors = [], ?array $form = null): string
    {
        $label = $this->label($id);
        return View::render('labels/form', [
            'title' => 'Label ' . $label['name'], 'nav' => 'labels',
            'isNew' => false, 'label' => $label, 'form' => $form ?? $label, 'errors' => $errors,
            'hosts' => Labels::hosts($id), 'canEdit' => Auth::isAdmin(),
        ]);
    }

    public function update(int $id): string
    {
        $label = $this->label($id);
        [$name, $description] = $this->input();
        if ($errors = Labels::validate($name, $description, $id)) {
            http_response_code(422);
            return $this->show($id, $errors, ['name' => $name, 'description' => $description]);
        }
        Labels::update($id, $name, $description);
        Audit::log('label.update', "{$label['name']} (id $id)" . ($name !== $label['name'] ? " → $name" : ''));
        flash('success', "Label “{$name}” saved.");
        redirect('/labels');
    }

    public function delete(int $id): never
    {
        $label = $this->label($id);
        $count = count(Labels::hosts($id));
        Labels::delete($id);
        Audit::log('label.delete', "{$label['name']} (id $id), removed from $count host(s)");
        flash('success', "Label “{$label['name']}” deleted" . ($count ? " and removed from $count host" . ($count === 1 ? '' : 's') : '') . '.');
        redirect('/labels');
    }

    // --- host assignment (POST from the host page) ---------------------------

    public function assign(int $id): never
    {
        $host = $this->host($id);
        $labelId = (int) ($_POST['label_id'] ?? 0);
        $label = Labels::find($labelId);
        if ($label === null) {
            flash('error', 'Choose a label to add.');
        } elseif (Labels::assign($id, $labelId)) {
            Audit::log('host.label_add', "{$host['hostname']} (id $id): {$label['name']}");
            flash('success', "Label “{$label['name']}” added.");
        }
        redirect('/status/' . $id);
    }

    public function unassign(int $id, int $labelId): never
    {
        $host = $this->host($id);
        $label = Labels::find($labelId);
        if ($label !== null && Labels::unassign($id, $labelId)) {
            Audit::log('host.label_remove', "{$host['hostname']} (id $id): {$label['name']}");
            flash('success', "Label “{$label['name']}” removed.");
        }
        redirect('/status/' . $id);
    }

    // -------------------------------------------------------------------------

    private function input(): array
    {
        $s = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        return [$s('name'), str_replace("\r\n", "\n", $s('description'))];
    }

    private function label(int $id): array
    {
        return Labels::find($id) ?? throw new HttpException(404, 'Label not found.');
    }

    private function host(int $id): array
    {
        return Db::one('SELECT id, hostname FROM ' . Db::collector('hosts') . ' WHERE id = ?', [$id])
            ?? throw new HttpException(404, 'Host not found.');
    }
}
