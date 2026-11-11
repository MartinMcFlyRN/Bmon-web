<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Report of the active alerts at this moment, posted to a Slack channel
 * through an Incoming Webhook (https://api.slack.com/messaging/webhooks).
 *
 * Used by `bin/bmon-web report:slack`. One numbered line per alert, in the
 * style of M/Monit's summary:
 *
 *   Active alerts summary:
 *   1) <host>: <message of the last failure event of the service>
 *   2) <host>: No report from Monit: the network, the host or Monit is down. Last report was …
 *
 * Failing services of online hosts and offline hosts are listed together, by host name.
 */
final class SlackReport
{
    /** Slack truncates messages longer than 40000 characters: stay well below. */
    private const MAX_TEXT = 38000;
    private const MAX_SERVICES = 2000;

    /**
     * Reads the current state from the collector database.
     * @return array{stats:array,services:array,offline:array}
     */
    public static function collect(bool $withOffline = true): array
    {
        return [
            'stats' => Overview::stats(),
            'services' => Overview::failingServices(self::MAX_SERVICES),
            'offline' => $withOffline ? Overview::offlineHosts(1000) : [],
        ];
    }

    /**
     * The alert lines, sorted by host name (then service), without numbering.
     * @return list<array{hostname:string,host_id:int|string,sort:string,text:string}>
     */
    public static function alerts(array $data): array
    {
        $alerts = [];
        foreach ($data['services'] as $s) {
            $alerts[] = [
                'hostname' => $s['hostname'],
                'host_id' => $s['host_id'],
                'sort' => $s['name'],
                'text' => self::serviceText($s),
            ];
        }
        foreach ($data['offline'] as $h) {
            $alerts[] = [
                'hostname' => $h['hostname'],
                'host_id' => $h['id'],
                'sort' => '',
                'text' => 'No report from Monit: the network, the host or Monit is down. Last report was '
                    . fmt_dt($h['last_seen'], 'j M Y H:i:s O'),
            ];
        }
        usort($alerts, static fn ($a, $b) => [strtolower($a['hostname']), $a['sort']] <=> [strtolower($b['hostname']), $b['sort']]);
        return $alerts;
    }

    /** Builds the webhook payload. $baseUrl (app.url) turns host names into links. */
    public static function payload(array $data, string $baseUrl = ''): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $title = (string) Config::get('slack.title', 'Active alerts summary:');
        $alerts = self::alerts($data);

        if ($alerts === []) {
            return ['text' => self::esc($title) . "\n" . self::esc((string) Config::get('slack.no_alerts', 'No active alerts.'))];
        }

        $lines = [self::esc($title)];
        $length = mb_strlen($lines[0]);
        foreach ($alerts as $n => $a) {
            $line = ($n + 1) . ') ' . self::hostRef($a['host_id'], $a['hostname'], $baseUrl) . ': ' . self::esc($a['text']);
            if ($length + mb_strlen($line) + 200 > self::MAX_TEXT) {
                $more = count($alerts) - $n;
                $lines[] = "…and $more more alert" . ($more === 1 ? '' : 's')
                    . ($baseUrl !== '' ? " <$baseUrl/|in Bmon>" : '') . '.';
                break;
            }
            $lines[] = $line;
            $length += mb_strlen($line) + 1;
        }
        return ['text' => implode("\n", $lines)];
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

    /**
     * Text of a failing service: the message of its last failure event, as Monit
     * wrote it (on one line); without one, the failing checks.
     */
    private static function serviceText(array $s): string
    {
        $msg = trim(preg_replace('/\s+/', ' ', (string) ($s['message'] ?? '')));
        if ($msg !== '') {
            return mb_strimwidth($msg, 0, 500, '…');
        }
        return $s['name'] . ': ' . implode(', ', Monit::failures((int) $s['status'])) . ' failed';
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
}
