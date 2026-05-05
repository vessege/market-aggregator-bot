<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Telegram\TelegramAPI;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

$token  = (string) $config['telegram']['token'];
$url    = (string) $config['telegram']['webhook_url'];
$secret = (string) $config['telegram']['webhook_secret'];

if ($token === '' || $url === '') {
    fwrite(STDERR, "BOT_TOKEN or WEBHOOK_URL not set in .env\n");
    exit(1);
}

$api = new TelegramAPI($token);
$res = $api->setWebhook($url, $secret);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
