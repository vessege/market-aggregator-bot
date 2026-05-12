<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Env;
use MarketBot\Core\Logger;
use MarketBot\Core\RateLimiter;
use MarketBot\Parsers\ParserRegistry;
use MarketBot\WebApp\AlertRepository;
use MarketBot\WebApp\PriceHistoryRepository;
use MarketBot\WebApp\ProductRepository;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_in(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $data = []): never
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

$path = (string) ($_GET['action'] ?? '');
$products = new ProductRepository();
$ip = RateLimiter::clientIp();

// Per-IP rate limits. Tunable via .env (default 60 rpm for cheap reads,
// 10 rpm for live search which hits external APIs).
$readLimit = (int) Env::get('RATE_LIMIT_API_RPM', 60);
$liveLimit = (int) Env::get('RATE_LIMIT_LIVE_RPM', 10);
if (!RateLimiter::allow('api:' . $path, $ip, $readLimit, 60)) {
    header('Retry-After: 30');
    fail(429, 'rate_limited');
}

try {
    switch ($path) {
        case 'products': {
            $filter = [
                'q'         => $_GET['q']         ?? null,
                'category'  => $_GET['category']  ?? null,
                'source'    => $_GET['source']    ?? null,
                'min_price' => $_GET['min_price'] ?? null,
                'max_price' => $_GET['max_price'] ?? null,
                'sort'      => $_GET['sort']      ?? null,
                'limit'     => $_GET['limit']     ?? 30,
                'offset'    => $_GET['offset']    ?? 0,
            ];
            $items = $products->search($filter);

            // Live search fallback: when the user typed a query and we have
            // few/no local results, hit the marketplaces in real time, persist
            // what comes back, and re-query the local index so we return a
            // unified list.
            $q = trim((string) ($filter['q'] ?? ''));
            $liveAllowed = ($_GET['live'] ?? '1') !== '0';
            $liveThreshold = 5;
            $liveTriggered = false;
            $liveStats = null;
            if ($liveAllowed && $q !== '' && count($items) < $liveThreshold) {
                // Live search has its own (stricter) rate limit, since it
                // makes outbound HTTP calls to upstream marketplaces.
                if (!RateLimiter::allow('api:live', $ip, $liveLimit, 60)) {
                    // Don't 429 here — just skip the live hop and serve what
                    // we already have locally. The user still gets a useful
                    // response, no degradation.
                    $liveStats = ['skipped' => 'rate_limited'];
                } else {
                    try {
                        $manager = ParserRegistry::build($config);
                        $sources = null;
                        if (!empty($filter['source'])) {
                            $sources = [(string) $filter['source']];
                        }
                        $liveStats = $manager->searchAll($q, $sources, 20);
                        $liveTriggered = true;
                        if (($liveStats['total'] ?? 0) > 0) {
                            $items = $products->search($filter);
                        }
                    } catch (\Throwable $e) {
                        Logger::error('api', 'live search failed', [
                            'q' => $q, 'err' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Cross-source dedupe: when several marketplaces return the same
            // product (same title), keep the cheapest as the "primary" and
            // attach the others under offers[] so the frontend can show "also
            // available on X for Y so'm" without duplicate cards in the grid.
            $items = dedupe_by_title($items);

            // Apply [min,max] price filter in PHP after FX conversion — the
            // SQL-level filter ran against source prices which may be in RUB.
            $items = apply_uzs_price_window(
                $items,
                $filter['min_price'] !== null ? (float) $filter['min_price'] : null,
                $filter['max_price'] !== null ? (float) $filter['max_price'] : null,
            );

            $resp = ['products' => $items];
            if ($liveTriggered) {
                $resp['live'] = [
                    'triggered' => true,
                    'inserted'  => (int) ($liveStats['inserted'] ?? 0),
                    'updated'   => (int) ($liveStats['updated'] ?? 0),
                    'by_source' => $liveStats['by_source'] ?? [],
                ];
            } elseif (isset($liveStats['skipped'])) {
                $resp['live'] = ['skipped' => $liveStats['skipped']];
            }
            ok($resp);
        }

        case 'categories': {
            ok(['categories' => $products->categories()]);
        }

        case 'product': {
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) fail(400, 'invalid id');
            $p = $products->findById($id);
            if (!$p) fail(404, 'not found');

            // On-demand "live" refresh if older than 5 minutes.
            $stale = isset($p['updated_at']) && (time() - strtotime((string) $p['updated_at']) > 300);
            if ($stale && RateLimiter::allow('api:live', $ip, $liveLimit, 60)) {
                try {
                    $manager = ParserRegistry::build($config);
                    if (in_array($p['source'], array_keys($manager->all()), true)) {
                        $manager->refreshProduct((string) $p['source'], (string) $p['external_id']);
                        $p = $products->findById($id);
                    }
                } catch (\Throwable $e) {
                    Logger::error('api', 'live refresh failed', ['id' => $id, 'err' => $e->getMessage()]);
                }
            }
            ok(['product' => $p]);
        }

        case 'sources': {
            $manager = ParserRegistry::build($config);
            $list = [];
            foreach ($manager->all() as $src => $parser) {
                $list[] = ['source' => $src, 'name' => $parser->displayName()];
            }
            ok(['sources' => $list]);
        }

        case 'history': {
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) fail(400, 'invalid id');
            $limit = (int) ($_GET['limit'] ?? 90);
            $repo  = new PriceHistoryRepository();
            $rows  = $repo->recent($id, $limit);
            $stats = $repo->stats($id);
            ok([
                'product_id' => $id,
                'history'    => $rows,
                'min_uzs'    => $stats['min'],
                'max_uzs'    => $stats['max'],
                'points'     => $stats['n'],
            ]);
        }

        case 'compare': {
            // Multiple product IDs comma-separated, e.g. ?ids=12,17,23
            $idsRaw = (string) ($_GET['ids'] ?? '');
            $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
            $ids = array_values(array_unique($ids));
            if (!$ids) fail(400, 'no ids');
            if (count($ids) > 6) $ids = array_slice($ids, 0, 6);
            $out = [];
            foreach ($ids as $id) {
                $p = $products->findById((int) $id);
                if ($p) $out[] = $p;
            }
            ok(['products' => $out]);
        }

        case 'alert_create': {
            // POST only — prevents accidental form auto-submit on link click.
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                fail(405, 'method_not_allowed');
            }
            // Tighter rate limit per IP so we can't be used as an email-bomb relay.
            if (!RateLimiter::allow('api:alert_create', $ip, 5, 60)) {
                header('Retry-After: 60');
                fail(429, 'rate_limited');
            }
            $body = json_in() ?: $_POST;
            try {
                $alertId = (new AlertRepository())->create(
                    productId:   (int) ($body['product_id']   ?? 0),
                    email:       (string) ($body['email']     ?? ''),
                    targetPrice: (float) ($body['target_price'] ?? 0),
                    currency:    (string) ($body['currency']  ?? 'UZS'),
                );
            } catch (\InvalidArgumentException $e) {
                fail(400, $e->getMessage());
            }
            ok(['alert_id' => $alertId]);
        }

        default:
            fail(404, 'unknown action');
    }
} catch (\Throwable $e) {
    Logger::error('api', 'unhandled', ['err' => $e->getMessage(), 'path' => $path]);
    fail(500, 'server error');
}

/**
 * Group products by a normalized title so cross-source duplicates collapse
 * into one card with the other offers attached. The cheapest offer wins the
 * top slot — that's what users want to see first.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function dedupe_by_title(array $items): array
{
    if (count($items) < 2) {
        return $items;
    }
    $groups = [];
    foreach ($items as $row) {
        $title = (string) ($row['title'] ?? '');
        $key   = normalize_title($title);
        if ($key === '') {
            // unique key per row so things without title don't collide
            $key = 'row:' . (string) ($row['id'] ?? spl_object_hash((object) $row));
        }
        $groups[$key][] = $row;
    }
    $out = [];
    foreach ($groups as $group) {
        if (count($group) === 1) {
            $out[] = $group[0];
            continue;
        }
        usort($group, fn($a, $b) => ((float) ($a['price'] ?? 0)) <=> ((float) ($b['price'] ?? 0)));
        $primary = $group[0];
        $offers  = [];
        for ($i = 1; $i < count($group); $i++) {
            $offers[] = [
                'id'           => $group[$i]['id']           ?? null,
                'source'       => $group[$i]['source']       ?? '',
                'price'        => $group[$i]['price']        ?? 0,
                'currency'     => $group[$i]['currency']     ?? 'UZS',
                'external_url' => $group[$i]['external_url'] ?? null,
            ];
        }
        $primary['offers'] = $offers;
        $out[] = $primary;
    }
    return $out;
}

function normalize_title(string $s): string
{
    $s = mb_strtolower($s);
    $s = preg_replace('/[\p{P}\p{S}]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function apply_uzs_price_window(array $items, ?float $min, ?float $max): array
{
    if ($min === null && $max === null) {
        return $items;
    }
    return array_values(array_filter($items, function ($row) use ($min, $max) {
        $price = (float) ($row['price_uzs'] ?? $row['price'] ?? 0);
        if ($min !== null && $price < $min) return false;
        if ($max !== null && $price > $max) return false;
        return true;
    }));
}
