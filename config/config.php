<?php
declare(strict_types=1);

use MarketBot\Core\Env;

require_once __DIR__ . '/../src/Core/Env.php';

Env::load(__DIR__ . '/../.env');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Tashkent'));

return [
    'app' => [
        'env'      => Env::get('APP_ENV', 'production'),
        'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
        'key'      => (string) Env::get('APP_KEY', 'change-me'),
        'session'  => (string) Env::get('SESSION_NAME', 'mab_session'),
    ],
    'db' => (function (): array {
        $sqlitePath = (string) Env::get('DB_SQLITE_PATH', __DIR__ . '/../storage/database.sqlite');
        if ($sqlitePath !== '' && $sqlitePath[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $sqlitePath)) {
            // Resolve relative paths against the repo root so PHP's built-in
            // server (which changes CWD per request) keeps using one DB file.
            $sqlitePath = dirname(__DIR__) . '/' . ltrim($sqlitePath, './');
        }
        return [
            'driver'      => (string) Env::get('DB_DRIVER', 'mysql'),
            'host'        => (string) Env::get('DB_HOST', '127.0.0.1'),
            'port'        => (int)    Env::get('DB_PORT', 3306),
            'name'        => (string) Env::get('DB_NAME', 'market_bot'),
            'user'        => (string) Env::get('DB_USER', 'root'),
            'pass'        => (string) Env::get('DB_PASS', ''),
            'sqlite_path' => $sqlitePath,
        ];
    })(),
    'admin' => [
        'username' => (string) Env::get('ADMIN_USERNAME', 'admin'),
        'password' => (string) Env::get('ADMIN_PASSWORD', 'admin123'),
    ],
    'parser' => [
        'user_agent' => (string) Env::get('PARSER_USER_AGENT', 'Mozilla/5.0'),
        'delay_ms'   => (int)    Env::get('PARSER_REQUEST_DELAY_MS', 800),
        'timeout'    => (int)    Env::get('PARSER_TIMEOUT', 20),
    ],
    'paths' => [
        'root'             => realpath(__DIR__ . '/..'),
        'storage'          => realpath(__DIR__ . '/..') . '/storage',
        'uploads_products' => realpath(__DIR__ . '/..') . '/storage/uploads/products',
        'logs'             => realpath(__DIR__ . '/..') . '/storage/logs',
    ],
];
