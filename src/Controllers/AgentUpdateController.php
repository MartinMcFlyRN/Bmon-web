<?php

declare(strict_types=1);

namespace Bmon\Web\Controllers;

use Bmon\Web\AgentUpdate;
use Bmon\Web\Audit;
use Bmon\Web\HttpException;

/**
 * Endpoints of bmon-update.sh (no session: every request is signed, see
 * AgentUpdate). Plain-text answers, read by the script and shown in Monit.
 */
final class AgentUpdateController
{
    /**
     * GET /agent/v1/config?id=&user=&ts=&have=&sig=
     * 200 + zip (X-Bmon-Version, X-Bmon-Signature), 204 already installed,
     * 404 nothing published, 400/403 refused.
     */
    public function config(): never
    {
        $this->plain(function (): void {
            $in = $_GET;
            [$host, $key] = AgentUpdate::authenticate($in, AgentUpdate::configData($in));
            $id = (int) $host['id'];
            $have = is_string($in['have'] ?? null) ? $in['have'] : '';
            AgentUpdate::recordPoll($id, $have);

            $release = AgentUpdate::latest($id);
            if ($release === null) {
                throw new HttpException(404, 'Nothing deployed for this agent yet: Agent → Manage → the agent → Deploy configuration to host.');
            }
            header('X-Bmon-Version: ' . $release['version']);
            if (hash_equals($release['version'], $have)) {
                http_response_code(204);
                return;
            }
            $zip = AgentUpdate::zipOf((int) $release['id']);
            header('X-Bmon-Signature: ' . AgentUpdate::zipSignature($key, (string) $in['id'], (string) $in['ts'], $release['version']));
            header('Content-Type: application/zip');
            header('Content-Length: ' . strlen($zip));
            Audit::log('agentconf.update_download', "{$host['hostname']} (id $id) version " . substr($release['version'], 0, 12) . ' from ' . client_ip(),
                ['id' => null, 'username' => 'agent']);
            echo $zip;
        });
    }

    /** POST /agent/v1/result: id, user, ts, version, status, message, sig. */
    public function result(): never
    {
        $this->plain(function (): void {
            $in = array_map(static fn ($v) => is_string($v) ? $v : '', $_POST);
            $in['message'] = str_replace("\r\n", "\n", $in['message'] ?? '');
            [$host] = AgentUpdate::authenticate($in, AgentUpdate::resultData($in));
            $status = $in['status'] ?? '';
            $version = $in['version'] ?? '';
            if (!in_array($status, ['installed', 'rolled_back', 'failed'], true) || !preg_match('/^([0-9a-f]{64})?$/', $version)) {
                throw new HttpException(400, 'Malformed result.');
            }
            AgentUpdate::recordResult((int) $host['id'], $version, $status, $in['message']);
            Audit::log('agentconf.update_' . $status, "{$host['hostname']} (id {$host['id']}) version " . substr($version, 0, 12)
                . ($status !== 'installed' ? ': ' . strtok($in['message'], "\n") : ''), ['id' => null, 'username' => 'agent']);
            echo "ok\n";
        });
    }

    private function plain(callable $fn): never
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        ob_start();
        try {
            $fn();
            ob_end_flush();
        } catch (HttpException $e) {
            ob_end_clean();
            http_response_code($e->status);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage(), "\n";
        }
        exit;
    }
}
