<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Database;
use MarketBot\Core\Logger;
use MarketBot\Parsers\HttpClient;
use MarketBot\Parsers\ParserManager;
use MarketBot\Parsers\ParserRegistry;
use MarketBot\Parsers\UzumParser;
use MarketBot\Parsers\WildberriesParser;
use MarketBot\Telegram\UserRepository;
use MarketBot\Telegram\WebAppAuth;
use MarketBot\WebApp\ProductRepository;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
$config = Bootstrap::init();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Telegram-Init-Data');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function read_init_data(): string
{
    $hdr = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
    if (is_string($hdr) && $hdr !== '') {
        return $hdr;
    }
    return (string) ($_REQUEST['init_data'] ?? '');
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
$users = new UserRepository();
$products = new ProductRepository();

$authUser = null;
if ($config['telegram']['token'] !== '') {
    $verifier = new WebAppAuth($config['telegram']['token']);
    $verified = $verifier->verify(read_init_data());
    if ($verified !== null && !empty($verified['user'])) {
        $authUser = $verified['user'];
        $users->upsert($authUser);
    }
}

$dbUserId = null;
if ($authUser) {
    $row = $users->findByTg((int) $authUser['id']);
    $dbUserId = $row ? (int) $row['id'] : null;
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

            if ($dbUserId !== null) {
                $favIds = $products->favoriteIds($dbUserId);
                $products->attachFavoriteFlag($items, $favIds);
            }

            $resp = ['products' => $items];
            if ($liveTriggered) {
                $resp['live'] = [
                    'triggered' => true,
                    'inserted'  => (int) ($liveStats['inserted'] ?? 0),
                    'updated'   => (int) ($liveStats['updated'] ?? 0),
                    'by_source' => $liveStats['by_source'] ?? [],
                ];
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
            if ($stale) {
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

            if ($dbUserId !== null && $p) {
                $favs = $products->favoriteIds($dbUserId);
                $p['is_favorite'] = in_array($id, $favs, true);
            }
            ok(['product' => $p]);
        }

        case 'favorites': {
            if ($dbUserId === null) fail(401, 'auth required');
            ok(['products' => $products->favorites($dbUserId)]);
        }

        case 'toggle_favorite': {
            if ($dbUserId === null) fail(401, 'auth required');
            $body = json_in();
            $pid = (int) ($body['product_id'] ?? 0);
            if ($pid <= 0) fail(400, 'invalid product_id');
            $added = $products->toggleFavorite($dbUserId, $pid);
            ok(['favorited' => $added]);
        }

        case 'me': {
            ok(['user' => $authUser, 'db_user_id' => $dbUserId]);
        }

        case 'sources': {
            $manager = ParserRegistry::build($config);
            $list = [];
            foreach ($manager->all() as $src => $parser) {
                $list[] = ['source' => $src, 'name' => $parser->displayName()];
            }
            ok(['sources' => $list]);
        }

        default:
            fail(404, 'unknown action');
    }
} catch (\Throwable $e) {
    Logger::error('api', 'unhandled', ['err' => $e->getMessage(), 'path' => $path]);
    fail(500, 'server error');
}
