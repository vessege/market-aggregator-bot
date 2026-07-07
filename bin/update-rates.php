<?php
declare(strict_types=1);

/**
 * Fetch official CBU (Central Bank of Uzbekistan) exchange rates and store
 * them in `settings` (rate_usd_uzs, rate_rub_uzs). Run daily from cron:
 *
 *   30 9 * * *  cd /var/www/marketbot && php bin/update-rates.php
 */

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Settings;
use MarketBot\Parsers\HttpClient;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

$http = new HttpClient(userAgent: $config['parser']['user_agent'], timeout: 15);
$resp = $http->get('https://cbu.uz/uz/arkhiv-kursov-valyut/json/');
if (!$resp['ok']) {
    fwrite(STDERR, "CBU API failed: HTTP {$resp['status']} {$resp['error']}\n");
    exit(1);
}

$list = json_decode($resp['body'], true);
if (!is_array($list)) {
    fwrite(STDERR, "CBU API returned invalid JSON\n");
    exit(1);
}

$updated = 0;
foreach ($list as $item) {
    $ccy = strtoupper((string) ($item['Ccy'] ?? ''));
    $rate = (float) str_replace(',', '.', (string) ($item['Rate'] ?? '0'));
    if ($rate <= 0) {
        continue;
    }
    if ($ccy === 'USD') {
        Settings::set('rate_usd_uzs', (string) $rate);
        echo "USD/UZS = $rate\n";
        $updated++;
    } elseif ($ccy === 'RUB') {
        Settings::set('rate_rub_uzs', (string) $rate);
        echo "RUB/UZS = $rate\n";
        $updated++;
    }
}

if ($updated === 0) {
    fwrite(STDERR, "No USD/RUB rates found in CBU response\n");
    exit(1);
}
echo "Rates updated.\n";
