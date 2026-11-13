<?php

declare(strict_types=1);

namespace Bmon\Web;

use Bmon\Web\Controllers\AdminController;
use Bmon\Web\Controllers\AgentConfController;
use Bmon\Web\Controllers\AgentUpdateController;
use Bmon\Web\Controllers\CustomTemplatesController;
use Bmon\Web\Controllers\GroupChecksController;
use Bmon\Web\Controllers\InstallerController;
use Bmon\Web\Controllers\AuthController;
use Bmon\Web\Controllers\DashboardController;
use Bmon\Web\Controllers\InventoryController;
use Bmon\Web\Controllers\ProfileController;
use Bmon\Web\Controllers\StatusController;

final class App
{
    public static function routes(): Router
    {
        $r = new Router();

        $r->get('/login', [AuthController::class, 'showLogin'], 'guest');
        $r->post('/login', [AuthController::class, 'login'], 'guest');
        $r->post('/logout', [AuthController::class, 'logout'], 'auth');

        $r->get('/', [DashboardController::class, 'index']);

        $r->get('/status', [StatusController::class, 'index']);
        $r->get('/status/{id}', [StatusController::class, 'host']);
        $r->get('/status/{id}/monit-check', [StatusController::class, 'monitCheck']);
        $r->post('/status/{id}/action', [StatusController::class, 'action'], 'admin');
        $r->post('/status/{id}/name', [StatusController::class, 'rename'], 'admin');
        $r->post('/status/{id}/notes', [StatusController::class, 'notes'], 'admin');
        $r->post('/status/{id}/monit-address', [StatusController::class, 'monitAddress'], 'admin');
        $r->post('/status/{id}/delete', [StatusController::class, 'delete'], 'admin');
        $r->post('/status/{id}/inventory', [InventoryController::class, 'assign'], 'admin');
        $r->post('/status/{id}/inventory/{itemId}/delete', [InventoryController::class, 'unassign'], 'admin');

        $r->get('/inventory', [InventoryController::class, 'index']);
        $r->get('/inventory/new', [InventoryController::class, 'newItem'], 'admin');
        $r->post('/inventory', [InventoryController::class, 'create'], 'admin');
        $r->get('/inventory/{id}', [InventoryController::class, 'show']);
        $r->post('/inventory/{id}', [InventoryController::class, 'update'], 'admin');
        $r->post('/inventory/{id}/delete', [InventoryController::class, 'delete'], 'admin');
        // pre-0.6.0 URLs
        $r->get('/labels', [InventoryController::class, 'legacy']);
        $r->get('/labels/{id}', [InventoryController::class, 'legacy']);

        $r->get('/profile', [ProfileController::class, 'show']);
        $r->post('/profile', [ProfileController::class, 'update']);
        $r->post('/profile/password', [ProfileController::class, 'changePassword']);

        $r->get('/agentconf', [AgentConfController::class, 'index'], 'admin');
        // custom templates and group checks (before /agentconf/{id}: the ids are numeric anyway)
        $r->get('/agentconf/templates', [CustomTemplatesController::class, 'index'], 'admin');
        $r->get('/agentconf/templates/new', [CustomTemplatesController::class, 'newForm'], 'admin');
        $r->post('/agentconf/templates/new', [CustomTemplatesController::class, 'create'], 'admin');
        $r->get('/agentconf/templates/{id}', [CustomTemplatesController::class, 'edit'], 'admin');
        $r->post('/agentconf/templates/{id}', [CustomTemplatesController::class, 'update'], 'admin');
        $r->post('/agentconf/templates/{id}/delete', [CustomTemplatesController::class, 'delete'], 'admin');
        $r->get('/agentconf/installer', [InstallerController::class, 'index'], 'admin');
        $r->get('/agentconf/installer/new', [InstallerController::class, 'newForm'], 'admin');
        $r->post('/agentconf/installer/new', [InstallerController::class, 'create'], 'admin');
        $r->get('/agentconf/installer/{id}', [InstallerController::class, 'edit'], 'admin');
        $r->post('/agentconf/installer/{id}', [InstallerController::class, 'update'], 'admin');
        $r->post('/agentconf/installer/{id}/relink', [InstallerController::class, 'regenerate'], 'admin');
        $r->post('/agentconf/installer/{id}/delete', [InstallerController::class, 'delete'], 'admin');
        $r->get('/pkg/{token}', [InstallerController::class, 'download'], 'public');
        $r->get('/agentconf/groups', [GroupChecksController::class, 'index'], 'admin');
        $r->get('/agentconf/groups/new', [GroupChecksController::class, 'newGroup'], 'admin');
        $r->post('/agentconf/groups/new', [GroupChecksController::class, 'createGroup'], 'admin');
        $r->get('/agentconf/groups/{id}', [GroupChecksController::class, 'show'], 'admin');
        $r->post('/agentconf/groups/{id}', [GroupChecksController::class, 'updateGroup'], 'admin');
        $r->post('/agentconf/groups/{id}/delete', [GroupChecksController::class, 'deleteGroup'], 'admin');
        $r->get('/agentconf/groups/{id}/new', [GroupChecksController::class, 'newCheck'], 'admin');
        $r->post('/agentconf/groups/{id}/new', [GroupChecksController::class, 'create'], 'admin');
        $r->get('/agentconf/groups/{id}/checks/{checkId}', [GroupChecksController::class, 'edit'], 'admin');
        $r->post('/agentconf/groups/{id}/checks/{checkId}', [GroupChecksController::class, 'update'], 'admin');
        $r->post('/agentconf/groups/{id}/checks/{checkId}/delete', [GroupChecksController::class, 'delete'], 'admin');
        $r->get('/agentconf/{id}', [AgentConfController::class, 'host'], 'admin');
        $r->get('/agentconf/{id}/new', [AgentConfController::class, 'newCheck'], 'admin');
        $r->post('/agentconf/{id}/new', [AgentConfController::class, 'create'], 'admin');
        $r->get('/agentconf/{id}/checks/{checkId}', [AgentConfController::class, 'edit'], 'admin');
        $r->post('/agentconf/{id}/checks/{checkId}', [AgentConfController::class, 'update'], 'admin');
        $r->post('/agentconf/{id}/checks/{checkId}/delete', [AgentConfController::class, 'delete'], 'admin');
        $r->get('/agentconf/{id}/download', [AgentConfController::class, 'download'], 'admin');
        $r->post('/agentconf/{id}/link', [AgentConfController::class, 'createLink'], 'admin');
        $r->post('/agentconf/{id}/link/revoke', [AgentConfController::class, 'revokeLink'], 'admin');
        $r->post('/agentconf/{id}/publish', [AgentConfController::class, 'publish'], 'admin');
        $r->get('/agentconf/{id}/update-status', [AgentConfController::class, 'updateStatus'], 'admin');
        $r->post('/agentconf/{id}/required', [AgentConfController::class, 'addRequired'], 'admin');
        $r->get('/agentconf/{id}/add-group', [AgentConfController::class, 'addGroupForm'], 'admin');
        $r->post('/agentconf/{id}/add-group', [AgentConfController::class, 'addGroup'], 'admin');
        $r->post('/agentconf/{id}/update-now', [AgentConfController::class, 'updateNow'], 'admin');
        $r->get('/dl/{token}', [AgentConfController::class, 'publicDownload'], 'public');
        // bmon-update.sh on the agents (signed requests, no session)
        $r->get('/agent/v1/config', [AgentUpdateController::class, 'config'], 'public');
        $r->post('/agent/v1/result', [AgentUpdateController::class, 'result'], 'public');

        $r->get('/admin/users', [AdminController::class, 'users'], 'admin');
        $r->get('/admin/users/new', [AdminController::class, 'newUser'], 'admin');
        $r->post('/admin/users', [AdminController::class, 'createUser'], 'admin');
        $r->get('/admin/users/{id}', [AdminController::class, 'editUser'], 'admin');
        $r->post('/admin/users/{id}', [AdminController::class, 'updateUser'], 'admin');
        $r->post('/admin/users/{id}/delete', [AdminController::class, 'deleteUser'], 'admin');
        $r->get('/admin/audit', [AdminController::class, 'audit'], 'admin');
        $r->get('/admin/groups', [AdminController::class, 'groups'], 'admin');
        $r->get('/admin/groups/new', [AdminController::class, 'newGroup'], 'admin');
        $r->post('/admin/groups', [AdminController::class, 'createGroup'], 'admin');
        $r->get('/admin/groups/{id}', [AdminController::class, 'editGroup'], 'admin');
        $r->post('/admin/groups/{id}', [AdminController::class, 'updateGroup'], 'admin');
        $r->post('/admin/groups/{id}/delete', [AdminController::class, 'deleteGroup'], 'admin');
        $r->get('/admin/config', [AdminController::class, 'config'], 'admin');
        $r->post('/admin/config', [AdminController::class, 'saveConfig'], 'admin');
        $r->post('/admin/config/reset', [AdminController::class, 'resetConfig'], 'admin');

        return $r;
    }

    public static function run(): void
    {
        try {
            Config::load();
            Settings::apply();
            ini_set('display_errors', Config::get('app.debug') ? '1' : '0');
            self::securityHeaders();

            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $method = $method === 'HEAD' ? 'GET' : $method;
            [$handler, $access, $params] = self::routes()->match($method, self::path());

            // 'public': no session, no sign-in, no CSRF (download links, signed agent requests)
            if ($access === 'public') {
                [$class, $action] = $handler;
                echo (new $class())->$action(...$params);
                return;
            }
            Session::start();

            $user = Auth::user();
            if ($access !== 'guest' && $user === null) {
                if ($method === 'GET') {
                    $_SESSION['after_login'] = self::path();
                }
                redirect('/login');
            }
            if ($method === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::iniBytes((string) ini_get('post_max_size'))) {
                // PHP dropped the whole request body (uploads too big)
                flash('error', 'The upload is too big for PHP (post_max_size = ' . ini_get('post_max_size') . ', upload_max_filesize = '
                    . ini_get('upload_max_filesize') . '). Raise them (public/.user.ini or php.ini) and nginx client_max_body_size.');
                redirect(self::path());
            }
            if ($method === 'POST' && !Csrf::valid()) {
                if ($access === 'guest') {
                    // typically a login form left open past the session lifetime
                    flash('error', 'The form has expired. Please try again.');
                    redirect(self::path());
                }
                throw new HttpException(400, 'The form has expired. Reload the page and try again.');
            }
            if ($access === 'admin' && $user['role'] !== 'admin') {
                throw new HttpException(403, 'This page is reserved to administrators.');
            }

            [$class, $action] = $handler;
            echo (new $class())->$action(...$params);
        } catch (HttpException $e) {
            self::error($e->status, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('bmon-web: ' . $e);
            self::error(500, Config::get('app.debug') ? (string) $e : 'Internal error. Details are in the PHP error log.');
        }
    }

    /** Request path relative to app.base_path, without query string. */
    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = rtrim((string) Config::get('app.base_path'), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function securityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header_remove('X-Powered-By');
    }

    private static function error(int $status, string $message): void
    {
        http_response_code($status);
        $titles = [400 => 'Bad request', 403 => 'Forbidden', 404 => 'Not found', 405 => 'Method not allowed', 500 => 'Error'];
        try {
            $layout = session_status() === PHP_SESSION_ACTIVE && Auth::user() !== null ? 'layout' : 'layout_guest';
            echo View::render('error', ['title' => $titles[$status] ?? 'Error', 'status' => $status, 'message' => $message], $layout);
        } catch (\Throwable) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "$status " . ($titles[$status] ?? 'Error') . "\n";
        }
    }

    /** "32M" -> bytes. */
    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        } ?: PHP_INT_MAX;
    }
}
