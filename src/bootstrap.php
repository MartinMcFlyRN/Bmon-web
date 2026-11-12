<?php

declare(strict_types=1);

const BMON_WEB_VERSION = '0.8.0';

define('BMON_ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit("Bmon-web requires PHP >= 8.2\n");
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Bmon\\Web\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';
