<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Logger;
use MarketBot\Telegram\SubscriptionGate;
use MarketBot\Telegram\TelegramAPI;
use MarketBot\Telegram\UserRepository;
use MarketBot\Telegram\Webhook;

require_once __DIR__ . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!empty($config['telegram']['webhook_secret']) && !hash_equals((string) $config['telegram']['webhook_secret'], (string) $secret)) {
    http_response_code(401);
    exit;
}

$raw = (string) file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

try {
    $api = new TelegramAPI((string) $config['telegram']['token']);
    $users = new UserRepository();
    $gate = new SubscriptionGate($api);
    $webhook = new Webhook($api, $users, $gate, (string) $config['telegram']['webapp_url']);
    $webhook->handle($update);
} catch (\Throwable $e) {
    Logger::error('webhook', 'top-level', ['err' => $e->getMessage()]);
}

echo json_encode(['ok' => true]);
