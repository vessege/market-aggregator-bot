<?php
declare(strict_types=1);

/*
 * Faza 6: Hybrid arch cron job.
 *
 * For each "hot keyword" managed in the admin panel (or auto-promoted from
 * zero-result user searches), call every active parser's live search and
 * persist whatever comes back. The catalog grows in the background so most
 * user queries hit the local cache.
 *
 * Recommended cron: every 10 minutes.
 *   * /10 * * * * /usr/bin/php /www/narxbor.uz/bin/refresh-hot-keywords.php >> /var/log/narxbor-cron.log 2>&1
 */

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Env;
use MarketBot\Core\Logger;
use MarketBot\Parsers\ParserRegistry;
use MarketBot\WebApp\HotKeywordRepository;
use MarketBot\WebApp\SearchAnalytics;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

$opts = getopt('', ['batch:', 'per-keyword:', 'dry-run', 'verbose']);
$batch       = (int) ($opts['batch']       ?? (int) Env::get('HOT_KEYWORDS_BATCH', '8'));
$perKeyword  = (int) ($opts['per-keyword'] ?? (int) Env::get('HOT_KEYWORDS_PER_KW',  '20'));
$dryRun      = array_key_exists('dry-run',  $opts);
$verbose     = array_key_exists('verbose',  $opts);

$start = microtime(true);
echo '[' . date('H:i:s') . "] refresh-hot-keywords starting (batch=$batch, per-keyword=$perKeyword"
    . ($dryRun ? ', DRY RUN' : '') . ")\n";

$repo = new HotKeywordRepository();
$keywords = $repo->dueForRefresh($batch);

if (!$keywords) {
    echo "No hot keywords to refresh. Add some at /admin/keywords.php\n";
    exit(0);
}

$manager = ParserRegistry::build($config);
$totalInserted = 0;
$totalUpdated  = 0;

foreach ($keywords as $row) {
    $kw = (string) $row['keyword'];
    $id = (int) $row['id'];
    if ($verbose) echo "  -> $kw ... ";

    if ($dryRun) {
        echo "(skipped, dry-run)\n";
        continue;
    }

    try {
        $res = $manager->searchAll($kw, null, $perKeyword);
        $ins = (int) ($res['inserted'] ?? 0);
        $upd = (int) ($res['updated']  ?? 0);
        $tot = (int) ($res['total']    ?? 0);
        $totalInserted += $ins;
        $totalUpdated  += $upd;
        $repo->markFetched($id, $tot);
        echo $verbose
            ? "ins=$ins upd=$upd total=$tot\n"
            : sprintf("  %-40s ins=%-4d upd=%-4d total=%-4d\n", $kw, $ins, $upd, $tot);
    } catch (\Throwable $e) {
        Logger::error('cron-hot-keywords', 'keyword failed', [
            'keyword' => $kw,
            'err' => $e->getMessage(),
        ]);
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

// Housekeeping: prune very old search logs.
$pruned = (new SearchAnalytics())->prune((int) Env::get('SEARCH_LOG_RETENTION_DAYS', '30'));
if ($pruned > 0) {
    echo "Pruned $pruned old search_logs row(s).\n";
}

$elapsed = round(microtime(true) - $start, 2);
echo "[" . date('H:i:s') . "] done in {$elapsed}s. Total inserted=$totalInserted updated=$totalUpdated\n";
