<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Links to download an agent's AgentConf zip without signing in, e.g. with curl
 * from the agent itself:  https://bmon.example.com/dl/<token>
 *
 * - the token is 32 random bytes (256 bits), URL-safe base64 (43 characters);
 * - only its SHA-256 is stored, so the link is shown once, when it is created;
 * - a link expires after agentconf.link_ttl_hours (default 24);
 * - creating a new link for an agent revokes the previous ones; links can be revoked;
 * - the zip is built at download time, with the current checks.
 */
final class DownloadLinks
{
    /** @return array{token:string,url:string,expires_at:string} */
    public static function create(int $hostId, string $by): array
    {
        self::revokeHost($hostId);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hours = max(1, min(24 * 30, (int) Config::get('agentconf.link_ttl_hours', 24)));
        $expires = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        Db::exec(
            'INSERT INTO download_links (token_hash, host_id, created_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [hash('sha256', $token), $hostId, $by, now_utc(), $expires]
        );
        if (random_int(1, 20) === 1) {
            Db::exec('DELETE FROM download_links WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        }
        return ['token' => $token, 'url' => self::url($token), 'expires_at' => $expires];
    }

    /** The agent's link that still works, if any (without its token, which is not stored). */
    public static function active(int $hostId): ?array
    {
        return Db::one(
            'SELECT * FROM download_links WHERE host_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(3)
              ORDER BY id DESC LIMIT 1',
            [$hostId]
        );
    }

    /** @return int number of links revoked */
    public static function revokeHost(int $hostId): int
    {
        return Db::exec(
            'UPDATE download_links SET revoked_at = ? WHERE host_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP(3)',
            [now_utc(), $hostId]
        );
    }

    /**
     * Looks a token up.
     * @return array{0:string,1:?array} [state: ok|expired|revoked|unknown, row]
     */
    public static function resolve(string $token): array
    {
        $row = Db::one('SELECT * FROM download_links WHERE token_hash = ?', [hash('sha256', $token)]);
        return match (true) {
            $row === null => ['unknown', null],
            $row['revoked_at'] !== null => ['revoked', $row],
            strtotime($row['expires_at'] . ' UTC') <= time() => ['expired', $row],
            default => ['ok', $row],
        };
    }

    public static function markUsed(int $id, string $ip): void
    {
        Db::exec('UPDATE download_links SET uses = uses + 1, last_used_at = ?, last_used_ip = ? WHERE id = ?',
            [now_utc(), $ip, $id]);
    }

    /** Absolute URL of a token: app.url, or the address this request came to. */
    public static function url(string $token): string
    {
        $base = rtrim((string) Config::get('app.url'), '/');
        if ($base === '') {
            $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
            $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $base = ($https ? 'https' : 'http') . '://' . $host . rtrim((string) Config::get('app.base_path'), '/');
        }
        return $base . '/dl/' . $token;
    }

    /** Shell commands to download and install the zip on the agent, for copy & paste. */
    public static function installCommands(string $url): string
    {
        $zip = '/tmp/bmon-agentconf.zip';
        return "curl -fsS -o $zip '$url'\n" . AgentChecks::installSteps($zip) . "rm -f $zip\n";
    }
}
