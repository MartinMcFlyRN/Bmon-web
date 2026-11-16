<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\Auth;
use Bmon\Web\Config;
use Bmon\Web\Totp;
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

        $next = $_SESSION['after_login'] ?? '/';
        $error = Auth::attempt($username, $password);
        if ($error === null && Auth::user() !== null) {
            // remembered computer: signed in with the password
            redirect(is_string($next) && preg_match('#^/(?!/)[^\s\\\\]*$#', $next) ? $next : '/');
        }
        if ($error === null) {
            $_SESSION['after_login'] = $next;   // the session was reset: keep where to go
            redirect('/login/2fa');
        }
        http_response_code(401);
        return View::render('auth/login', ['title' => 'Sign in', 'username' => $username, 'error' => $error], 'layout_guest');
    }

    /** GET /login/2fa: the code, or the set-up of the authenticator app at the first sign-in. */
    public function showSecondFactor(?string $error = null): string
    {
        $user = Auth::pending();
        if ($user === null) {
            Auth::cancelPending();
            flash('info', 'Sign in again.');
            redirect('/login');
        }
        $setup = $user['totp_enabled_at'] === null;
        $secret = $setup ? Auth::pendingSecret() : null;
        return View::render('auth/second_factor', [
            'title' => $setup ? 'Set up two-factor authentication' : 'Two-factor authentication',
            'user' => $user, 'setup' => $setup, 'error' => $error, 'rememberDays' => \Bmon\Web\TrustedDevices::days(),
            'secret' => $secret,
            'uri' => $secret !== null ? Totp::uri($secret, $user['username'] . '@' . self::site(), 'Bmon') : null,
        ], 'layout_guest');
    }

    /** POST /login/2fa */
    public function secondFactor(): string
    {
        if (isset($_POST['cancel'])) {
            Auth::cancelPending();
            redirect('/login');
        }
        if (Auth::pending() === null) {
            Auth::cancelPending();
            flash('info', 'The sign-in took too long: sign in again.');
            redirect('/login');
        }
        $error = Auth::secondFactor(is_string($_POST['code'] ?? null) ? $_POST['code'] : '', ($_POST['remember'] ?? '') === '1');
        if ($error === null) {
            $next = $_SESSION['after_login'] ?? '/';
            unset($_SESSION['after_login']);
            // only local paths, never "//host" or schemes
            redirect(is_string($next) && preg_match('#^/(?!/)[^\s\\\\]*$#', $next) ? $next : '/');
        }
        if (Auth::pending() === null) {
            flash('error', $error);
            redirect('/login');
        }
        http_response_code(401);
        return $this->showSecondFactor($error);
    }

    /** Host name shown in the authenticator app next to the user. */
    private static function site(): string
    {
        $host = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'bmon-web');
        return preg_replace('/[^A-Za-z0-9.-]/', '', (string) $host) ?: 'bmon-web';
    }

    public function logout(): never
    {
        Auth::logout();
        flash('info', 'You have been signed out.');
        redirect('/login');
    }
}
