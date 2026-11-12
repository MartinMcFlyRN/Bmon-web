<?php

declare(strict_types=1);

namespace Bmon\Web;

/**
 * Minimal ZIP archive writer (PKZIP 2.0, deflate), so that the php-zip extension
 * is not required. Meant for a handful of small text files.
 */
final class ZipWriter
{
    /** @var list<array{name:string,data:string,time:int,mode:int}> */
    private array $files = [];

    /** $mode: Unix permissions restored by unzip (e.g. 0755 for scripts). */
    public function add(string $name, string $data, ?int $mtime = null, int $mode = 0644): void
    {
        $this->files[] = [
            'name' => ltrim(str_replace('\\', '/', $name), '/'), 'data' => $data,
            'time' => $mtime ?? time(), 'mode' => $mode & 0777,
        ];
    }

    public function bytes(): string
    {
        $out = '';
        $central = '';
        foreach ($this->files as $f) {
            $crc = crc32($f['data']);
            $size = strlen($f['data']);
            $deflated = gzdeflate($f['data'], 9);
            [$method, $payload] = $deflated !== false && strlen($deflated) < $size ? [8, $deflated] : [0, $f['data']];
            [$dosTime, $dosDate] = self::dosTime($f['time']);
            $flags = 0x0800; // UTF-8 names
            $offset = strlen($out);

            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $dosTime, $dosDate,
                $crc, strlen($payload), $size, strlen($f['name']), 0) . $f['name'] . $payload;

            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0314, 20, $flags, $method, $dosTime, $dosDate,
                $crc, strlen($payload), $size, strlen($f['name']), 0, 0, 0, 0,
                ((0100000 | $f['mode']) << 16), $offset) . $f['name'];   // external attributes: regular file + mode
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->files), count($this->files),
            strlen($central), strlen($out), 0);
    }

    /** @return array{0:int,1:int} */
    private static function dosTime(int $ts): array
    {
        // DOS times are local times: use the display timezone; DOS dates start in 1980
        $d = (new \DateTimeImmutable('@' . max($ts, 315532800)))
            ->setTimezone(new \DateTimeZone((string) Config::get('app.timezone', 'UTC')));
        [$y, $mo, $day, $h, $mi, $sec] = array_map('intval', explode(' ', $d->format('Y n j G i s')));
        return [($h << 11) | ($mi << 5) | intdiv($sec, 2), (($y - 1980) << 9) | ($mo << 5) | $day];
    }
}
