<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\HttpException;
use Bmon\Web\Password;
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
}
