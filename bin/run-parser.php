<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Parsers\AliexpressParser;
use MarketBot\Parsers\HttpClient;
use MarketBot\Parsers\OlxParser;
use MarketBot\Parsers\OzonParser;
use MarketBot\Parsers\ParserManager;
use MarketBot\Parsers\UzumParser;
use MarketBot\Parsers\WildberriesParser;
use MarketBot\Parsers\YandexMarketParser;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

$opts = getopt('s:l:d:q:', ['source:', 'limit:', 'depth:', 'query:']);
$source   = $opts['source']   ?? $opts['s'] ?? null;
$limit    = (int) ($opts['limit']  ?? $opts['l'] ?? 30);
$depth    = (int) ($opts['depth']  ?? $opts['d'] ?? 2);
$query    = (string) ($opts['query']  ?? $opts['q'] ?? '');

if ($source === null) {
    fwrite(STDERR, "Usage: php bin/run-parser.php --source=<uzum|wildberries|olx|ozon|aliexpress|yandex_market> [--limit=30] [--depth=2] [--query=...]\n");
    exit(1);
}

$http = new HttpClient(
    userAgent: $config['parser']['user_agent'],
    timeout:   $config['parser']['timeout'],
    delayMs:   $config['parser']['delay_ms']
);
$manager = new ParserManager($http);
$manager->register(new UzumParser($http));
$manager->register(new WildberriesParser($http));
$manager->register(new OlxParser($http));
$manager->register(new OzonParser($http));
$manager->register(new AliexpressParser($http));
$manager->register(new YandexMarketParser($http));

echo "[" . date('H:i:s') . "] Running parser: $source (limit=$limit, depth=$depth)\n";

$options = ['max_items' => $limit, 'max_depth' => $depth];
if ($query !== '') {
    $options['query'] = $query;
}

$res = $manager->runSource($source, $options);
echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($res['ok'] ? 0 : 2);
