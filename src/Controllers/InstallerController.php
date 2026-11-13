<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\AgentChecks;
use Bmon\Web\AgentPackages;
use Bmon\Web\Audit;
use Bmon\Web\Auth;
use Bmon\Web\Db;
use Bmon\Web\HttpException;
use Bmon\Web\View;

/** Agent → Installer: packages to install Monit on new servers. */
final class InstallerController
{
    private const SYSTEM_DIRS = ['/', '/etc', '/usr', '/usr/local', '/usr/bin', '/usr/sbin', '/usr/local/bin', '/bin', '/sbin',
        '/var', '/var/lib', '/opt', '/home', '/root', '/tmp', '/boot', '/lib', '/srv'];

    public function index(): string
    {
        return View::render('agentconf/installer/index', [
            'title' => 'Installer', 'nav' => 'agentconf', 'packages' => AgentPackages::all(),
        ]);
    }

    public function newForm(): string
    {
        return $this->form(null, ['name' => '', 'base_dir' => '/usr/local/monit', 'httpd_user' => 'admin',
            'httpd_password' => bin2hex(random_bytes(12)), 'httpd_port' => '2812']);
    }

    public function create(): string
    {
        $f = $this->fromPost();
        [$errors, $bin, $pem] = $this->validate($f, null);
        if ($errors) {
            http_response_code(422);
            return $this->form(null, $f, $errors);
        }
        $id = AgentPackages::save(null, $f, $bin['bytes'], $bin['name'], $bin['info'], $pem['text'], $pem['info'], (string) Auth::user()['username']);
        Audit::log('agentconf.pkg_create', "{$f['name']} (id $id), {$f['base_dir']}, monit {$bin['info']} sha256 " . substr(hash('sha256', $bin['bytes']), 0, 12));
        flash('success', "Package “{$f['name']}” saved: copy its link below and run the commands on the new server.");
        redirect("/agentconf/installer/$id");
    }

    public function edit(int $id): string
    {
        $p = AgentPackages::find($id) ?? throw new HttpException(404, 'Package not found.');
        return $this->form($id, ['httpd_password' => '', 'httpd_port' => (string) $p['httpd_port']] + $p);
    }

    public function update(int $id): string
    {
        $p = AgentPackages::find($id) ?? throw new HttpException(404, 'Package not found.');
        $f = $this->fromPost();
        [$errors, $bin, $pem] = $this->validate($f, $id);
        if ($errors) {
            http_response_code(422);
            return $this->form($id, $f + $p, $errors);
        }
        AgentPackages::save($id, $f, $bin['bytes'] ?? null, $bin['name'] ?? null, $bin['info'] ?? null, $pem['text'] ?? null, $pem['info'] ?? null,
            (string) Auth::user()['username']);
        $what = array_filter([$bin ? 'new monit binary (' . $bin['info'] . ')' : '', $pem ? 'new certificate' : '', $f['httpd_password'] !== '' ? 'new httpd password' : '']);
        Audit::log('agentconf.pkg_update', "{$f['name']} (id $id)" . ($what ? ': ' . implode(', ', $what) : ''));
        flash('success', 'Package saved. The link stays the same; servers already installed are not changed.');
        redirect("/agentconf/installer/$id");
    }

    public function regenerate(int $id): never
    {
        $p = AgentPackages::find($id) ?? throw new HttpException(404, 'Package not found.');
        Db::exec('UPDATE agent_packages SET token = ? WHERE id = ?', [AgentPackages::newToken(), $id]);
        Audit::log('agentconf.pkg_relink', "{$p['name']} (id $id)");
        flash('success', 'New link created: the old one does not work any more.');
        redirect("/agentconf/installer/$id");
    }

    public function delete(int $id): never
    {
        $p = AgentPackages::find($id) ?? throw new HttpException(404, 'Package not found.');
        Db::exec('DELETE FROM agent_packages WHERE id = ?', [$id]);
        Audit::log('agentconf.pkg_delete', "{$p['name']} (id $id)");
        flash('success', "Package “{$p['name']}” deleted: its link does not work any more.");
        redirect('/agentconf/installer');
    }

    /** GET /pkg/{token}: the zip, without signing in (permanent link). */
    public function download(string $token): never
    {
        $p = AgentPackages::byToken($token);
        if ($p === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Unknown package link.\n";
            exit;
        }
        try {
            $zip = AgentPackages::zip($p);
        } catch (\RuntimeException $e) {
            http_response_code(409);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage(), "\n";
            exit;
        }
        Db::exec('UPDATE agent_packages SET downloads = downloads + 1, last_download_at = ?, last_download_ip = ? WHERE id = ?',
            [now_utc(), client_ip(), (int) $p['id']]);
        Audit::log('agentconf.pkg_download', "{$p['name']} (id {$p['id']}) from " . client_ip(), ['id' => null, 'username' => 'installer']);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="bmon-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $p['name']) . '.zip"');
        header('Content-Length: ' . strlen($zip));
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        echo $zip;
        exit;
    }

    private function fromPost(): array
    {
        $s = static fn (string $k): string => trim(is_string($_POST[$k] ?? null) ? $_POST[$k] : '');
        return ['name' => preg_replace('/\s+/', ' ', $s('name')), 'base_dir' => rtrim($s('base_dir'), '/'), 'httpd_user' => $s('httpd_user'),
            'httpd_password' => $s('httpd_password'), 'httpd_port' => $s('httpd_port')];
    }

    /** @return array{0:array<string,string>,1:?array,2:?array} errors, binary, pem */
    private function validate(array $f, ?int $id): array
    {
        $e = [];
        if ($f['name'] === '' || mb_strlen($f['name']) > 64) {
            $e['name'] = 'Give the package a name (max 64 characters), e.g. Debian x86-64.';
        } elseif (($o = Db::one('SELECT id FROM agent_packages WHERE name = ?', [$f['name']])) && (int) $o['id'] !== $id) {
            $e['name'] = 'Another package has this name.';
        }
        if (!preg_match('#^/[A-Za-z0-9._/-]{1,199}$#', $f['base_dir']) || str_contains($f['base_dir'], '..')
            || in_array($f['base_dir'], self::SYSTEM_DIRS, true)) {
            $e['base_dir'] = 'An absolute path used only by Monit, e.g. /usr/local/monit.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $f['httpd_user'])) {
            $e['httpd_user'] = 'Letters, digits, dot, dash, underscore (max 32).';
        }
        if (($f['httpd_password'] !== '' || $id === null) && !preg_match('/^[A-Za-z0-9._~+=-]{8,64}$/', $f['httpd_password'])) {
            $e['httpd_password'] = '8 to 64 letters, digits or . _ ~ + = -';
        }
        if (!ctype_digit($f['httpd_port']) || (int) $f['httpd_port'] < 1 || (int) $f['httpd_port'] > 65535) {
            $e['httpd_port'] = 'A port number (1-65535).';
        }
        $bin = $this->upload('monit_bin', $id === null, $e);
        if ($bin !== null) {
            $c = AgentPackages::checkBinary($bin['bytes']);
            $c['ok'] ? $bin['info'] = $c['info'] : $e['monit_bin'] = $c['error'];
        }
        $pemUp = $this->upload('pem', $id === null, $e);
        $pem = null;
        if ($pemUp !== null) {
            $c = AgentPackages::checkPem($pemUp['bytes']);
            $c['ok'] ? $pem = ['text' => str_replace("\r\n", "\n", $pemUp['bytes']), 'info' => $c['info']] : $e['pem'] = $c['error'];
        }
        return [$e, $e ? null : $bin, $e ? null : $pem];
    }

    /** An uploaded file, or null if none (an error is set when required or broken). */
    private function upload(string $field, bool $required, array &$e): ?array
    {
        $u = $_FILES[$field] ?? null;
        if (!is_array($u) || ($u['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                $e[$field] = 'Choose the file.';
            }
            return null;
        }
        if ($u['error'] === UPLOAD_ERR_INI_SIZE || $u['error'] === UPLOAD_ERR_FORM_SIZE) {
            $e[$field] = 'The file is bigger than upload_max_filesize (' . ini_get('upload_max_filesize') . ') of PHP.';
            return null;
        }
        if ($u['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($u['tmp_name'])) {
            $e[$field] = 'The upload failed (error ' . (int) $u['error'] . ').';
            return null;
        }
        if ($u['size'] > AgentPackages::MAX_BINARY) {
            $e[$field] = 'Too big (max 32 MB).';
            return null;
        }
        return ['bytes' => (string) file_get_contents($u['tmp_name']), 'name' => mb_substr(basename((string) $u['name']), 0, 200), 'info' => ''];
    }

    private function form(?int $id, array $p, array $errors = []): string
    {
        $built = null;
        $url = null;
        if ($id !== null && $errors === []) {
            $full = AgentPackages::find($id);
            try {
                $built = AgentPackages::checks($full);
                $built['syntax'] = $built['errors'] ? null : AgentChecks::syntaxCheck(array_column($built['checks'], 'config', 'name'));
            } catch (\RuntimeException $ex) {
                $built = ['checks' => [], 'scripts' => [], 'errors' => [$ex->getMessage()], 'syntax' => null];
            }
            $url = \Bmon\Web\DownloadLinks::absolute('/pkg/' . $full['token']);
        }
        return View::render('agentconf/installer/form', [
            'title' => ($id === null ? 'New package' : $p['name']) . ' · Installer', 'nav' => 'agentconf',
            'id' => $id, 'p' => $p, 'errors' => $errors, 'built' => $built, 'url' => $url,
            'mismatches' => AgentPackages::mismatches($p['base_dir'] ?: '/usr/local/monit'),
            'encrypted' => AgentPackages::encrypted(),
        ]);
    }
}
