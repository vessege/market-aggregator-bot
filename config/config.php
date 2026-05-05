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
    'telegram' => [
        'token'           => (string) Env::get('BOT_TOKEN', ''),
        'username'        => ltrim((string) Env::get('BOT_USERNAME', ''), '@'),
        'webapp_url'      => (string) Env::get('WEBAPP_URL', ''),
        'webhook_url'     => (string) Env::get('WEBHOOK_URL', ''),
        'webhook_secret'  => (string) Env::get('WEBHOOK_SECRET', ''),
        'admin_tg_ids'    => array_filter(array_map('trim', explode(',', (string) Env::get('ADMIN_TELEGRAM_IDS', '')))),
    ],
    'db' => [
        'driver'      => (string) Env::get('DB_DRIVER', 'mysql'),
        'host'        => (string) Env::get('DB_HOST', '127.0.0.1'),
        'port'        => (int)    Env::get('DB_PORT', 3306),
        'name'        => (string) Env::get('DB_NAME', 'market_bot'),
        'user'        => (string) Env::get('DB_USER', 'root'),
        'pass'        => (string) Env::get('DB_PASS', ''),
        'sqlite_path' => (string) Env::get('DB_SQLITE_PATH', __DIR__ . '/../storage/database.sqlite'),
    ],
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
        'root'              => realpath(__DIR__ . '/..'),
        'storage'           => realpath(__DIR__ . '/..') . '/storage',
        'uploads_broadcast' => realpath(__DIR__ . '/..') . '/storage/uploads/broadcast',
        'uploads_products'  => realpath(__DIR__ . '/..') . '/storage/uploads/products',
        'logs'              => realpath(__DIR__ . '/..') . '/storage/logs',
    ],
];
