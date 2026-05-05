<?php
declare(strict_types=1);

use MarketBot\Admin\BroadcastService;
use MarketBot\Core\Bootstrap;
use MarketBot\Telegram\TelegramAPI;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

$opts = getopt('', ['id:']);
$broadcastId = (int) ($opts['id'] ?? 0);
if ($broadcastId <= 0) {
    fwrite(STDERR, "Usage: php bin/send-broadcast.php --id=<broadcast_id>\n");
    exit(1);
}

$api = new TelegramAPI((string) $config['telegram']['token']);
$service = new BroadcastService($api, (string) $config['paths']['uploads_broadcast']);
$res = $service->run($broadcastId, function (int $sent, int $failed, int $total) {
    fwrite(STDOUT, "  progress: $sent/$total (failed=$failed)\n");
});
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
