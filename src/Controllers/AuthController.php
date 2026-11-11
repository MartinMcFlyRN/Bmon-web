<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Auth;
use Bmon\Web\View;

final class AuthController
{
    public function showLogin(): string
    {
        if (Auth::user() !== null) {
            redirect('/');
        }
        return View::render('auth/login', ['title' => 'Sign in', 'username' => ''], 'layout_guest');
    }

    public function login(): string
    {
        $username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        $error = Auth::attempt($username, $password);
        if ($error === null) {
            $next = $_SESSION['after_login'] ?? '/';
            unset($_SESSION['after_login']);
            // only local paths, never "//host" or schemes
            redirect(is_string($next) && preg_match('#^/(?!/)[^\s\\\\]*$#', $next) ? $next : '/');
        }
        http_response_code(401);
        return View::render('auth/login', ['title' => 'Sign in', 'username' => $username, 'error' => $error], 'layout_guest');
    }

    public function logout(): never
    {
        Auth::logout();
        flash('info', 'You have been signed out.');
        redirect('/login');
    }
}
