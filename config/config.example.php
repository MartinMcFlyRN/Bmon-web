<?php

// Copy to /etc/bmon-web/config.php (or config/config.php) and edit.
//
// This file keeps the database connection and the settings that must not be
// changed from the browser. Everything else is in Admin → Configuration: the
// keys listed at the bottom can still be set here, as initial values, and a
// value saved in Admin → Configuration overrides them.

return [
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=bmon_web;charset=utf8mb4',
        // or a unix socket: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=bmon_web;charset=utf8mb4'
        'user'     => 'bmon_web',
        'password' => 'CHANGE-ME',
    ],

    // Database written by bmon-collector (same server, SELECT grant only).
    'collector_db' => 'bmon_collector',

    'app' => [
        'base_path' => '',              // '/bmon' if published in a subdirectory
        'debug'     => false,           // never true in production
    ],

    'monit' => [
        // Same value as BMON_CREDENTIALS_KEY of bmon-collector (empty if not set there).
        'credentials_key' => '',
    ],

    'agentconf' => [
        // monit binary used to validate the checks with "monit -t" ('' = search PATH, 'none' = never).
        'monit_binary' => '',
    ],

    // Optional initial values of Admin → Configuration, e.g.:
    // 'app'     => ['name' => 'Bmon', 'url' => 'https://bmon.example.com', 'timezone' => 'Europe/Rome'],
    // 'session' => ['secure' => true, 'idle_timeout' => 3600, 'absolute_timeout' => 43200],
    // 'security'=> ['password_min_length' => 10, 'login_window' => 900, 'login_max_per_ip' => 20,
    //               'login_max_per_user' => 5, 'trusted_proxies' => []],
    // 'monit'   => ['tls_verify' => false, 'ca_cert' => '-----BEGIN CERTIFICATE-----…', 'tls_verify_name' => true,
    //               'require_tls' => false, 'timeout' => 10],
    // 'slack'   => ['webhook_url' => '', 'title' => 'Active alerts summary:', 'no_alerts' => 'No active alerts.',
    //               'proxy' => '', 'timeout' => 10],
    // 'agentconf' => ['monit_dir' => '/etc/monit/conf.d', 'script_dir' => '', 'link_ttl_hours' => 24,
    //               'collector_url' => '', 'web_address' => ''],
    // Note: PHP keeps only the last 'app' / 'monit' / 'agentconf' key of an array:
    // merge them with the ones above instead of repeating them.
];
