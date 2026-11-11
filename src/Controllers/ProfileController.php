<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Password;
use Bmon\Web\Users;
use Bmon\Web\View;

/** The signed-in user's own account: name, e-mail, password. */
final class ProfileController
{
    public function show(array $errors = [], ?array $form = null): string
    {
        $user = Auth::user();
        return View::render('profile', [
            'title' => 'My account',
            'nav' => 'profile',
            'form' => $form ?? $user,
            'errors' => $errors,
        ]);
    }

    public function update(): string
    {
        $user = Auth::user();
        $in = [
            'username'  => $user['username'],
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email'     => trim((string) ($_POST['email'] ?? '')),
        ];
        $errors = Users::validate($in, (int) $user['id']);
        if ($errors) {
            http_response_code(422);
            return $this->show($errors, $in + $user);
        }
        Users::update((int) $user['id'], ['full_name' => $in['full_name'], 'email' => $in['email']]);
        Audit::log('profile.update');
        flash('success', 'Your details have been saved.');
        redirect('/profile');
    }

    public function changePassword(): string
    {
        $user = Auth::user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];
        if (!Password::verify($current, $user['password_hash'])) {
            $errors['current_password'] = 'The current password is not correct.';
        } elseif (($policy = Password::policyError($new, $user['username'])) !== null) {
            $errors['new_password'] = $policy;
        } elseif (!hash_equals($new, $confirm)) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        } elseif (Password::verify($new, $user['password_hash'])) {
            $errors['new_password'] = 'The new password must be different from the current one.';
        }
        if ($errors) {
            Audit::log('password.change_failed', implode(', ', array_keys($errors)));
            http_response_code(422);
            return $this->show($errors);
        }

        Users::setPassword((int) $user['id'], $new);
        Auth::refresh();
        Audit::log('password.change', 'other sessions ended');
        flash('success', 'Password changed. Your other sessions have been signed out.');
        redirect('/profile');
    }
}
