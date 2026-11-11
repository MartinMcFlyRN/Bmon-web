<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Report of the failing services at this moment, posted to a Slack channel
 * through an Incoming Webhook (https://api.slack.com/messaging/webhooks).
 *
 * Used by `bin/bmon-web report:slack`. The message uses Block Kit: a header with
 * the totals, the offline hosts, then one section per host with its failing
 * services (failing checks, for how long, last error message).
 */
final class SlackReport
{
    /** Slack limits: 50 blocks per message, 3000 characters per section. */
    private const MAX_BLOCKS = 50;
    private const MAX_SECTION = 2900;
    private const MAX_SERVICES = 1000;

    /**
     * Reads the current state from the collector database.
     * @return array{stats:array,services:array,offline:array}
     */
    public static function collect(bool $withOffline = true): array
    {
        return [
            'stats' => Overview::stats(),
            'services' => Overview::failingServices(self::MAX_SERVICES),
            'offline' => $withOffline ? Overview::offlineHosts(200) : [],
        ];
    }

    /** Builds the webhook payload. $baseUrl (app.url) turns host names into links. */
    public static function payload(array $data, string $baseUrl = ''): array
    {
        $stats = $data['stats'];
        $services = $data['services'];
        $offline = $data['offline'];
        $baseUrl = rtrim($baseUrl, '/');

        $byHost = [];
        foreach ($services as $s) {
            $byHost[$s['host_id']][] = $s;
        }
        $nServices = count($services);
        $nHosts = count($byHost);

        $title = $nServices === 0
            ? 'No failing services'
            : sprintf('%d failing service%s on %d host%s', $nServices, $nServices === 1 ? '' : 's', $nHosts, $nHosts === 1 ? '' : 's');
        $emoji = $nServices === 0 ? ':large_green_circle:' : ':red_circle:';

        $now = (new \DateTimeImmutable('now', new \DateTimeZone((string) Config::get('app.timezone'))))->format('Y-m-d H:i');
        $online = (int) ($stats['hosts'] ?? 0) - (int) ($stats['hosts_offline'] ?? 0);
        $context = sprintf('%s · %s · %d/%d hosts online · %d services monitored',
            Config::get('app.name'), $now, $online, (int) ($stats['hosts'] ?? 0), (int) ($stats['services'] ?? 0));

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => mb_strimwidth("$emoji $title", 0, 150), 'emoji' => true]],
            ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => self::esc($context)]]],
        ];

        if ($offline) {
            $items = array_map(
                static fn ($h) => self::hostRef($h['id'], $h['hostname'], $baseUrl) . ' (' . fmt_duration(time() - self::ts($h['offline_since'])) . ')',
                $offline
            );
            $text = ':black_circle: *Offline hosts (' . (int) ($stats['hosts_offline'] ?? count($offline)) . '):* ' . implode(', ', $items);
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_strimwidth($text, 0, self::MAX_SECTION, '…')]];
        }

        if ($byHost) {
            $blocks[] = ['type' => 'divider'];
        }

        // one section per host; keep room for the closing blocks
        $budget = self::MAX_BLOCKS - count($blocks) - 2;
        $hostsShown = 0;
        foreach ($byHost as $hostId => $rows) {
            $sections = self::hostSections($hostId, $rows, $baseUrl);
            if (count($sections) > $budget) {
                break;
            }
            foreach ($sections as $text) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]];
            }
            $budget -= count($sections);
            $hostsShown++;
        }
        if ($hostsShown < $nHosts) {
            $more = $nHosts - $hostsShown;
            $text = "_…and $more more host" . ($more === 1 ? '' : 's') . ' with failing services._';
            if ($baseUrl !== '') {
                $text .= " <$baseUrl/status?filter=failing|See all in Bmon>";
            }
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]];
        }
        if ($baseUrl !== '') {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => "<$baseUrl/|Open the dashboard>"]]];
        }

        // plain text: shown in notifications and by clients without Block Kit
        $fallback = "$title" . ($offline ? ', ' . count($offline) . ' host(s) offline' : '');
        return ['text' => $fallback, 'blocks' => $blocks];
    }

    /** @return array{ok:bool,error:string} */
    public static function send(string $webhookUrl, array $payload): array
    {
        if (!preg_match('#^https://[^\s/]+/\S+$#', $webhookUrl)) {
            return ['ok' => false, 'error' => 'the webhook URL must be an https:// URL (slack.webhook_url or --webhook)'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $http = [
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n"
                . 'User-Agent: Bmon-web/' . BMON_WEB_VERSION . "\r\nConnection: close",
            'content' => $body,
            'timeout' => (float) Config::get('slack.timeout', 10),
            'ignore_errors' => true,
            'follow_location' => 0,
        ];
        if (($proxy = (string) Config::get('slack.proxy')) !== '') {
            $http['proxy'] = preg_replace('#^https?://#', 'tcp://', $proxy);
            $http['request_fulluri'] = true;
        }
        $ctx = stream_context_create(['http' => $http]);

        $http_response_header = [];
        $err = 'no response';
        set_error_handler(static function (int $no, string $msg) use (&$err): bool {
            $err = $msg;
            return true;
        });
        try {
            $response = file_get_contents($webhookUrl, false, $ctx);
        } finally {
            restore_error_handler();
        }
        if ($response === false && $http_response_header === []) {
            // never print the webhook URL: it is a secret
            $err = preg_replace('#https?://\S+#', '<webhook>', preg_replace('/^.*?failed to open stream: /i', '', $err));
            return ['ok' => false, 'error' => "cannot reach Slack: $err"];
        }
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        if ($status === 200) {
            return ['ok' => true, 'error' => ''];
        }
        // Slack answers with a short code: invalid_payload, no_service, channel_not_found, ...
        return ['ok' => false, 'error' => "Slack answered $status: " . mb_strimwidth(trim((string) $response), 0, 200)];
    }

    // -------------------------------------------------------------------------

    /** Sections for one host (split when longer than Slack allows). */
    private static function hostSections(int|string $hostId, array $rows, string $baseUrl): array
    {
        $head = '*' . self::hostRef($hostId, $rows[0]['hostname'], $baseUrl) . '*'
            . ($rows[0]['remote_addr'] !== '' ? '  `' . self::esc($rows[0]['remote_addr']) . '`' : '');
        $lines = [];
        foreach ($rows as $s) {
            $line = '• *' . self::esc($s['name']) . '* _' . self::esc(Monit::serviceType((int) $s['type'])) . '_ — '
                . self::esc(implode(', ', Monit::failures((int) $s['status'])))
                . ' · for ' . fmt_duration(time() - self::ts($s['status_changed_at']));
            if (!empty($s['message'])) {
                $line .= "\n      " . self::esc(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $s['message']), 0, 200, '…'));
            }
            $lines[] = $line;
        }

        $sections = [];
        $current = $head;
        foreach ($lines as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > self::MAX_SECTION) {
                $sections[] = $current;
                $current = $head . ' _(continued)_';
            }
            $current .= "\n" . $line;
        }
        $sections[] = $current;
        return $sections;
    }

    private static function hostRef(int|string $id, string $hostname, string $baseUrl): string
    {
        return $baseUrl !== '' ? "<$baseUrl/status/$id|" . self::esc($hostname) . '>' : self::esc($hostname);
    }

    /** Slack mrkdwn escaping (&, <, >). */
    private static function esc(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private static function ts(?string $utc): int
    {
        return $utc ? (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp() : time();
    }
}
