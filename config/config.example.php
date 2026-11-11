<?php

// Copy to /etc/bmon-web/config.php (or config/config.php) and edit.
// Only the keys you set override the defaults (see src/Config.php).

return [
    'app' => [
        'name'      => 'Bmon',
        'base_path' => '',              // '/bmon' if published in a subdirectory
        'timezone'  => 'Europe/Rome',
        'debug'     => false,           // never true in production
    ],

    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=bmon_web;charset=utf8mb4',
        // or a unix socket: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=bmon_web;charset=utf8mb4'
        'user'     => 'bmon_web',
        'password' => 'CHANGE-ME',
    ],

    // Database written by bmon-collector (same server, SELECT grant only).
    'collector_db' => 'bmon_collector',

    'session' => [
        'secure'           => true,     // set false only to test over plain HTTP
        'idle_timeout'     => 3600,
        'absolute_timeout' => 43200,
    ],

    'security' => [
        'password_min_length' => 10,
        'login_window'        => 900,
        'login_max_per_ip'    => 20,
        'login_max_per_user'  => 5,
        // Only if another reverse proxy sits in front of the nginx that runs PHP:
        // 'trusted_proxies' => ['10.0.0.0/8'],
        'trusted_proxies'     => [],
    ],
];
