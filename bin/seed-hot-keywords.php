<?php
declare(strict_types=1);

/*
 * One-shot seed: populates the hot_keywords table with a curated list of
 * popular Uzbek shopping terms. Safe to run multiple times — uses INSERT
 * IGNORE / INSERT OR IGNORE so existing rows stay intact.
 *
 * Run once on the server:
 *   php /www/knyazrico.uztan.ru/narxbor.uz/bin/seed-hot-keywords.php
 *
 * After this, the refresh-hot-keywords cron will start pulling products for
 * every keyword on the list.
 */

use MarketBot\Core\Bootstrap;
use MarketBot\WebApp\HotKeywordRepository;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
Bootstrap::init();

$repo = new HotKeywordRepository();

// Mixed Uzbek / Russian / English popular search terms grouped by intent.
// Priority is inversely proportional to refresh order (higher = fetched
// sooner by the cron's dueForRefresh() query).
$keywords = [
    // Electronics — highest priority (most searched)
    ['telefon', 50],
    ['iphone', 50],
    ['samsung', 50],
    ['noutbuk', 45],
    ['laptop', 45],
    ['planshet', 40],
    ['naushnik', 40],
    ['airpods', 40],
    ['quloqchin', 35],
    ['kompyuter', 35],
    ['monitor', 30],
    ['televizor', 35],
    ['xiaomi', 40],
    ['redmi', 35],
    ['poco', 30],

    // Home appliances
    ['konditsioner', 30],
    ['changyutgich', 25],
    ['muzlatgich', 30],
    ['kir mashinasi', 30],
    ['mikrodalgali pech', 25],
    ['gaz plita', 25],
    ['choynak', 25],
    ['blender', 20],
    ['mikser', 20],

    // Clothing / fashion
    ['kiyim', 35],
    ['krossovka', 35],
    ['poyabzal', 30],
    ['shimcha', 25],
    ['kurtka', 25],
    ['ko\'ylak', 25],

    // Kids / baby
    ['o\'yinchoq', 30],
    ['bola kiyim', 25],
    ['pampers', 30],

    // Beauty
    ['kosmetika', 25],
    ['parfyumeriya', 25],
    ['shampun', 20],

    // Sports / outdoor
    ['velosiped', 25],
    ['gantel', 20],
    ['palatka', 20],

    // Office / school
    ['ruchka', 15],
    ['daftar', 15],
    ['stepler', 15],

    // Food
    ['shokolad', 20],
    ['choy', 20],

    // Generic
    ['soat', 25],
    ['sumka', 25],
    ['ko\'zoynak', 20],
    ['atir', 20],
];

$added = 0;
$skipped = 0;
foreach ($keywords as [$kw, $priority]) {
    if ($repo->add($kw, $priority)) {
        $added++;
        echo "  +  $kw (priority=$priority)\n";
    } else {
        $skipped++;
        echo "  =  $kw (already exists or invalid)\n";
    }
}

echo "\nSeed complete: $added added, $skipped skipped.\n";
echo "Total hot_keywords rows now: " . count($repo->all()) . "\n";
echo "\nNext: make sure the cron is scheduled:\n";
echo "  */10 * * * * /usr/bin/php " . dirname(__DIR__) . "/bin/refresh-hot-keywords.php >> "
   . dirname(__DIR__) . "/storage/logs/cron.log 2>&1\n";
