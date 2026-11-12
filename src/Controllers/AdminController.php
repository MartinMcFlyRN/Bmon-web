<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\HttpException;
use Bmon\Web\Password;
use Bmon\Web\ServiceGroups;
use Bmon\Web\Settings;
use Bmon\Web\Users;
use Bmon\Web\View;

/** Admin → Users and Admin → Audit log. */
final class AdminController
{
    public function users(): string
    {
        return View::render('admin/users', [
            'title' => 'Users',
            'nav' => 'admin',
            'users' => Users::all(),
        ]);
    }

    public function newUser(array $errors = [], array $form = []): string
    {
        return View::render('admin/user_form', [
            'title' => 'New user',
            'nav' => 'admin',
            'isNew' => true,
            'form' => $form + ['username' => '', 'full_name' => '', 'email' => '', 'role' => 'user', 'is_active' => 1],
            'errors' => $errors,
        ]);
    }

    public function createUser(): string
    {
        $in = $this->input();
        $errors = Users::validate($in);
        $errors += $this->passwordErrors($in, true);
        if ($errors) {
            http_response_code(422);
            return $this->newUser($errors, $in);
        }
        $id = Users::create($in['username'], $in['password'], $in['role'], $in['full_name'], $in['email'], (bool) $in['is_active']);
        Audit::log('user.create', "{$in['username']} (id $id, role {$in['role']})");
        flash('success', "User {$in['username']} created.");
        redirect('/admin/users');
    }

    public function editUser(int $id, array $errors = [], ?array $form = null): string
    {
        $target = $this->target($id);
        return View::render('admin/user_form', [
            'title' => 'Edit user ' . $target['username'],
            'nav' => 'admin',
            'isNew' => false,
            'target' => $target,
            'isSelf' => $id === (int) Auth::user()['id'],
            'form' => $form ?? $target,
            'errors' => $errors,
        ]);
    }

    public function updateUser(int $id): string
    {
        $target = $this->target($id);
        $me = Auth::user();
        $in = $this->input();
        $errors = Users::validate($in, $id);
        $errors += $this->passwordErrors($in, false);

        $losesAdmin = $target['role'] === 'admin' && (int) $target['is_active'] === 1
            && ($in['role'] !== 'admin' || !$in['is_active']);
        if ($id === (int) $me['id'] && ($in['role'] !== $target['role'] || !$in['is_active'])) {
            $errors['role'] = 'You cannot change your own role or disable your own account.';
        } elseif ($losesAdmin && Users::countActiveAdmins() <= 1) {
            $errors['role'] = 'This is the last active administrator.';
        }
        if ($errors) {
            http_response_code(422);
            return $this->editUser($id, $errors, $in + $target);
        }

        Users::update($id, [
            'username'  => $in['username'],
            'full_name' => $in['full_name'],
            'email'     => $in['email'],
            'role'      => $in['role'],
            'is_active' => $in['is_active'],
        ]);
        $changes = [];
        foreach (['username', 'role', 'is_active'] as $f) {
            if ((string) $in[$f] !== (string) $target[$f]) {
                $changes[] = "$f: {$target[$f]} → {$in[$f]}";
            }
        }
        if ($in['password'] !== '') {
            Users::setPassword($id, $in['password']);
            $changes[] = 'password reset';
            if ($id === (int) $me['id']) {
                Auth::refresh();
            }
        }
        Audit::log('user.update', "{$in['username']} (id $id)" . ($changes ? ': ' . implode(', ', $changes) : ''));
        flash('success', "User {$in['username']} saved.");
        redirect('/admin/users');
    }

    public function deleteUser(int $id): string
    {
        $target = $this->target($id);
        if ($id === (int) Auth::user()['id']) {
            flash('error', 'You cannot delete your own account.');
            redirect('/admin/users/' . $id);
        }
        if ($target['role'] === 'admin' && (int) $target['is_active'] === 1 && Users::countActiveAdmins() <= 1) {
            flash('error', 'You cannot delete the last active administrator.');
            redirect('/admin/users/' . $id);
        }
        Users::delete($id);
        Audit::log('user.delete', "{$target['username']} (id $id)");
        flash('success', "User {$target['username']} deleted.");
        redirect('/admin/users');
    }

    public function audit(): string
    {
        return View::render('admin/audit', [
            'title' => 'Audit log',
            'nav' => 'admin',
            'entries' => Audit::recent(300),
        ]);
    }

    // ----------------------------------------------------------------------

    private function target(int $id): array
    {
        return Users::find($id) ?? throw new HttpException(404, 'User not found.');
    }

    private function input(): array
    {
        $s = static fn (string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        return [
            'username'  => trim($s('username')),
            'full_name' => trim($s('full_name')),
            'email'     => trim($s('email')),
            'role'      => $s('role'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'password'  => $s('password'),
            'password_confirm' => $s('password_confirm'),
        ];
    }

    private function passwordErrors(array $in, bool $required): array
    {
        if ($in['password'] === '' && !$required) {
            return [];
        }
        if (($err = Password::policyError($in['password'], $in['username'])) !== null) {
            return ['password' => $err];
        }
        if (!hash_equals($in['password'], $in['password_confirm'])) {
            return ['password_confirm' => 'The two passwords do not match.'];
        }
        return [];
    }

    // --- Admin → Configuration --------------------------------------------------

    public function config(array $errors = [], array $submitted = []): string
    {
        return View::render('admin/config', [
            'title' => 'Configuration',
            'nav' => 'admin',
            'fields' => Settings::fields(),
            'stored' => Settings::stored(),
            'errors' => $errors,
            'submitted' => $submitted,
            'configFile' => \Bmon\Web\Config::locate(),
        ]);
    }

    public function saveConfig(): string
    {
        [$errors, $changed] = Settings::save($_POST, (string) Auth::user()['username']);
        if ($errors) {
            http_response_code(422);
            flash('error', 'Nothing was saved: fix the highlighted fields.');
            return $this->config($errors, $_POST);
        }
        if ($changed) {
            Audit::log('config.update', implode(', ', $changed));
        }
        flash('success', $changed ? 'Configuration saved (' . count($changed) . ' change' . (count($changed) === 1 ? '' : 's') . ').' : 'Nothing changed.');
        redirect('/admin/config');
    }

    public function resetConfig(): never
    {
        $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
        if (Settings::reset($key)) {
            Audit::log('config.reset', $key);
            flash('success', "“{$key}” follows config.php / the default again.");
        }
        redirect('/admin/config#' . str_replace('.', '-', $key));
    }

    // --- Admin → Service groups -------------------------------------------------

    public function groups(): string
    {
        return View::render('admin/groups', [
            'title' => 'Service groups', 'nav' => 'admin',
            'groups' => ServiceGroups::allWithUsage(),
            'reported' => ServiceGroups::reportedNotDefined(),
        ]);
    }

    public function newGroup(array $errors = [], ?array $form = null): string
    {
        $prefill = is_string($_GET['name'] ?? null) && preg_match(ServiceGroups::NAME_PATTERN, $_GET['name']) ? $_GET['name'] : '';
        return View::render('admin/group_form', [
            'title' => 'New service group', 'nav' => 'admin', 'isNew' => true,
            'form' => $form ?? ['name' => $prefill, 'description' => ''], 'errors' => $errors, 'checks' => [],
        ]);
    }

    public function createGroup(): string
    {
        [$name, $description] = $this->groupInput();
        if ($errors = ServiceGroups::validate($name, $description)) {
            http_response_code(422);
            return $this->newGroup($errors, ['name' => $name, 'description' => $description]);
        }
        $id = ServiceGroups::create($name, $description);
        Audit::log('group.create', "$name (id $id)");
        flash('success', "Service group “{$name}” created.");
        redirect('/admin/groups');
    }

    public function editGroup(int $id, array $errors = [], ?array $form = null): string
    {
        $group = ServiceGroups::find($id) ?? throw new HttpException(404, 'Service group not found.');
        return View::render('admin/group_form', [
            'title' => 'Service group ' . $group['name'], 'nav' => 'admin', 'isNew' => false,
            'group' => $group, 'form' => $form ?? $group, 'errors' => $errors, 'checks' => ServiceGroups::checks($id),
        ]);
    }

    public function updateGroup(int $id): string
    {
        $group = ServiceGroups::find($id) ?? throw new HttpException(404, 'Service group not found.');
        [$name, $description] = $this->groupInput();
        if ($errors = ServiceGroups::validate($name, $description, $id)) {
            http_response_code(422);
            return $this->editGroup($id, $errors, ['name' => $name, 'description' => $description]);
        }
        ServiceGroups::update($id, $name, $description);
        $n = count(ServiceGroups::checks($id));
        Audit::log('group.update', "{$group['name']} (id $id)" . ($name !== $group['name'] ? " → $name" : ''));
        flash('success', "Service group “{$name}” saved." . ($name !== $group['name'] && $n
            ? ($n === 1 ? ' The check using it gets' : " The $n checks using it get") . ' the new name in the next zip.' : ''));
        redirect('/admin/groups');
    }

    public function deleteGroup(int $id): never
    {
        $group = ServiceGroups::find($id) ?? throw new HttpException(404, 'Service group not found.');
        $n = count(ServiceGroups::checks($id));
        ServiceGroups::delete($id);
        Audit::log('group.delete', "{$group['name']} (id $id), removed from $n check(s)");
        flash('success', "Service group “{$group['name']}” deleted" . ($n ? " and removed from $n check" . ($n === 1 ? '' : 's') : '') . '.');
        redirect('/admin/groups');
    }

    private function groupInput(): array
    {
        $s = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
        return [$s('name'), str_replace("\r\n", "\n", $s('description'))];
    }
}
