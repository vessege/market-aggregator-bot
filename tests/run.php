<?php
declare(strict_types=1);

/**
 * Lightweight test runner (no PHPUnit needed): php tests/run.php
 * Covers TextNormalizer, Currency/Settings, schema loading (incl. FTS5
 * triggers), ParserManager upsert and ProductRepository search.
 */

use MarketBot\Core\Currency;
use MarketBot\Core\Database;
use MarketBot\Core\Settings;
use MarketBot\Core\TextNormalizer;
use MarketBot\Parsers\BaseParser;
use MarketBot\Parsers\HttpClient;
use MarketBot\Parsers\OlxParser;
use MarketBot\Parsers\OzonParser;
use MarketBot\Parsers\ParsedProduct;
use MarketBot\Parsers\ParserManager;
use MarketBot\Parsers\UzumParser;
use MarketBot\Parsers\WildberriesParser;
use MarketBot\WebApp\ProductRepository;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'MarketBot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$failures = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "  ok  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

echo "== TextNormalizer ==\n";
check('cyrillic transliteration', TextNormalizer::normalize('Телефон Samsung') === 'telefon samsung');
check('uzbek cyrillic', TextNormalizer::normalize('Ўзбекистон қаҳваси') === "o'zbekiston qahvasi");
check('apostrophe variants unified', TextNormalizer::normalize('Goʻzallik go`zallik go’zallik') === "go'zallik go'zallik go'zallik");
check('tags stripped', TextNormalizer::normalize('<b>Kitob</b> <img src=x>') === 'kitob');
check('tokens', TextNormalizer::tokens("samsung galaxy-a55 o'yin") === ['samsung', 'galaxy', 'a55', "o'yin"]);

echo "== Database schema (sqlite, in-memory) ==\n";
Database::init(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
Database::runSqlFile($root . '/db/schema.sqlite.sql');
$pdo = Database::pdo();
check('schema loaded with FTS triggers', (bool) $pdo->query("SELECT 1 FROM products_fts")->fetchAll() !== null);
check('default rates seeded', Settings::getFloat('rate_rub_uzs') > 0);

echo "== Currency ==\n";
Settings::set('rate_rub_usz_dummy', null); // no-op sanity
Settings::set('rate_rub_uzs', '150');
Settings::set('rate_usd_uzs', '12900');
check('UZS passthrough', Currency::toUzs(1000.0, 'UZS') === 1000.0);
check('RUB converted', Currency::toUzs(100.0, 'RUB') === 15000.0);
check('unknown currency null', Currency::toUzs(5.0, 'EUR') === null);

echo "== Upsert + search ==\n";
final class FakeParser extends BaseParser
{
    /** @var ParsedProduct[] */
    public static array $items = [];

    public function source(): string { return 'uzum'; }
    public function displayName(): string { return 'Fake'; }
    public function fetchFeed(array $options = []): iterable { yield from self::$items; }
}

FakeParser::$items = [
    new ParsedProduct(
        source: 'uzum', externalId: 'p1',
        title: 'Samsung Galaxy A55 smartfoni', price: 3500000.0, currency: 'UZS',
        description: 'Kuchli telefon 8GB RAM', categorySlug: 'elektronika', soldCount: 500,
    ),
    new ParsedProduct(
        source: 'uzum', externalId: 'p2',
        title: 'Смартфон Samsung Galaxy S24', price: 25000.0, currency: 'RUB',
        oldPrice: 30000.0, soldCount: 100,
    ),
    new ParsedProduct(
        source: 'uzum', externalId: 'p3',
        title: 'Kitob: Alkimyogar', price: 50000.0, currency: 'UZS',
        categorySlug: 'kitoblar', soldCount: 900,
    ),
];

$manager = new ParserManager(new HttpClient());
$manager->register(new FakeParser(new HttpClient()));
$res = $manager->runSource('uzum', []);
check('first run inserts 3', $res['inserted'] === 3 && $res['updated'] === 0, json_encode($res));

$res2 = $manager->runSource('uzum', []);
check('second run updates 3 (atomic upsert, no dupes)', $res2['inserted'] === 0 && $res2['updated'] === 3, json_encode($res2));
check('no duplicate rows', (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 3);

$row = $pdo->query("SELECT search_text, price_uzs FROM products WHERE external_id = 'p2'")->fetch();
check('search_text normalized to latin', str_contains((string) $row['search_text'], 'smartfon samsung'));
check('price_uzs converted from RUB', abs((float) $row['price_uzs'] - 3750000.0) < 0.01, (string) $row['price_uzs']);

$repo = new ProductRepository();

$r = $repo->search(['q' => 'samsung']);
check('latin query finds both scripts', count($r) === 2, count($r) . ' found');

$r = $repo->search(['q' => 'Смартфон']);
check('cyrillic query finds latin titles (prefix match)', count($r) === 2, count($r) . ' found');

$r = $repo->search(['q' => 'alkimyogar']);
check('single hit', count($r) === 1 && $r[0]['external_id'] === 'p3');

$r = $repo->search(['q' => 'tv']);
check('short query LIKE fallback does not crash', is_array($r));

$r = $repo->search(['q' => '100%_!']);
check('LIKE wildcards escaped', count($r) === 0);

$r = $repo->search(['min_price' => 3600000]);
check('price filter uses UZS-normalized value', count($r) === 1 && $r[0]['external_id'] === 'p2', count($r) . ' found');

$r = $repo->search(['sort' => 'price_asc']);
check('price_asc sorts across currencies in UZS', $r[0]['external_id'] === 'p3' && $r[2]['external_id'] === 'p2');

$r = $repo->search(['q' => 'samsung', 'sort' => 'price_asc']);
check('search combined with non-relevance sort', count($r) === 2 && $r[0]['external_id'] === 'p1');

$r = $repo->search([]);
check('hydrate exposes price_uzs, hides search_text', array_key_exists('price_uzs', $r[0]) && !array_key_exists('search_text', $r[0]));

$cats = $repo->categories();
$elektronika = array_values(array_filter($cats, fn ($c) => $c['slug'] === 'elektronika'))[0] ?? null;
check('categories single-query counts', $elektronika !== null && $elektronika['product_count'] === 1, json_encode($elektronika));

// FTS stays in sync after delete
$pdo->exec("DELETE FROM products WHERE external_id = 'p3'");
$r = $repo->search(['q' => 'alkimyogar']);
check('FTS trigger syncs deletes', count($r) === 0);

echo "== Live search (uzum + wildberries + olx, fixtures) ==\n";

/**
 * Canned HTTP layer with the real response shapes of the three marketplace
 * search APIs, so the whole live-search path (fan-out -> parse -> upsert ->
 * DB search) is exercised without network access.
 */
final class FakeHttpClient extends HttpClient
{
    public int $multiCalls = 0;
    /** @var array<string,string> host-substring => body */
    public array $fixtures = [];

    public function get(string $url, array $headers = []): array
    {
        return $this->respond($url);
    }

    public function multiGet(array $requests): array
    {
        $this->multiCalls++;
        $out = [];
        foreach ($requests as $key => $req) {
            $out[$key] = $this->respond($req['url']);
        }
        return $out;
    }

    private function respond(string $url): array
    {
        foreach ($this->fixtures as $needle => $body) {
            if (str_contains($url, $needle)) {
                return ['ok' => true, 'status' => 200, 'body' => $body, 'error' => null];
            }
        }
        return ['ok' => false, 'status' => 404, 'body' => '', 'error' => 'no fixture'];
    }
}

$fake = new FakeHttpClient();
$fake->fixtures = [
    // Uzum: GET api.uzum.uz/api/v2/main/search/product?query=...
    'api.uzum.uz' => json_encode(['payload' => ['products' => [[
        'productId'        => 777001,
        'title'            => 'iPhone 15 Pro smartfoni 256GB',
        'sellPrice'        => 15000000,
        'fullPrice'        => 17000000,
        'rating'           => 4.9,
        'feedbackQuantity' => 120,
        'ordersQuantity'   => 300,
        'image'            => 'https://images.uzum.uz/x/original.jpg',
        'category'         => ['title' => 'Telefonlar va gadjetlar'],
    ]]]], JSON_UNESCAPED_UNICODE),
    // Wildberries: GET search.wb.ru/exactmatch/.../search?query=... (prices in kopecks)
    'search.wb.ru' => json_encode(['data' => ['products' => [[
        'id'           => 555001,
        'name'         => 'Смартфон Apple iPhone 15 128GB',
        'sizes'        => [['price' => ['product' => 900000000, 'basic' => 950000000]]],
        'reviewRating' => 4.8,
        'feedbacks'    => 5200,
        'supplier'     => 'Apple Store',
    ]]]], JSON_UNESCAPED_UNICODE),
    // OLX: GET www.olx.uz/api/v1/offers/?query=...
    'olx.uz' => json_encode(['data' => [[
        'id'          => 333001,
        'title'       => 'iPhone 15 yangi holatda',
        'description' => 'Ideal holat, dokumentlari bor',
        'url'         => 'https://www.olx.uz/d/obyavlenie/iphone-15-ID333001.html',
        'params'      => [['key' => 'price', 'value' => ['value' => 12000000, 'currency' => 'UZS']]],
        'photos'      => [['link' => 'https://apollo.olxcdn.com/v1/files/abc/image;s={width}x{height}']],
        'user'        => ['name' => 'Aziz'],
    ], [
        // priceless (barter) offer — must be skipped
        'id'     => 333002,
        'title'  => 'iPhone almashaman',
        'params' => [],
    ]]], JSON_UNESCAPED_UNICODE),
];

$liveManager = new ParserManager($fake);
$liveManager->register(new UzumParser($fake));
$liveManager->register(new WildberriesParser($fake));
$liveManager->register(new OlxParser($fake));
$liveManager->register(new OzonParser($fake)); // stub — must be ignored

check('stubs report no live-search support', !(new OzonParser($fake))->supportsLiveSearch());
check('all three drivers support live search',
    (new UzumParser($fake))->supportsLiveSearch()
    && (new WildberriesParser($fake))->supportsLiveSearch()
    && (new OlxParser($fake))->supportsLiveSearch());

$res = $liveManager->liveSearchCached('iPhone 15', 600, 10);
check('live search fetched from 3 sources', $res['fetched'] === 3 && $res['upserted'] === 3 && !$res['cached'], json_encode($res));

$r = $repo->search(['q' => 'iphone']);
$sources = array_unique(array_column($r, 'source'));
sort($sources);
check('DB search returns live results from all 3 marketplaces',
    count($r) === 3 && $sources === ['olx', 'uzum', 'wildberries'], json_encode($sources));

$wb = array_values(array_filter($r, fn ($p) => $p['source'] === 'wildberries'))[0];
check('WB kopecks -> RUB -> UZS conversion', abs($wb['price_uzs'] - 9000000.0 * 150) < 0.01, (string) $wb['price_uzs']);

$olx = array_values(array_filter($r, fn ($p) => $p['source'] === 'olx'))[0];
check('OLX photo size template resolved', str_contains((string) $olx['image_url'], '800x800'));
check('OLX priceless offers skipped', !in_array('333002', array_column($r, 'external_id'), true));

$uz = array_values(array_filter($r, fn ($p) => $p['source'] === 'uzum'))[0];
check('Uzum search item mapped (price, old price, category)',
    $uz['price'] === 15000000.0 && $uz['old_price'] === 17000000.0 && $uz['category_slug'] === 'elektronika',
    json_encode([$uz['price'], $uz['old_price'], $uz['category_slug']]));

// Cyrillic user query must find these live products too
$r = $repo->search(['q' => 'айфон']);
check('cyrillic "айфон" does not crash (transliteration)', is_array($r));
$r = $repo->search(['q' => 'смартфон iphone']);
check('cyrillic multiword finds live products', count($r) >= 2, count($r) . ' found');

$res2 = $liveManager->liveSearchCached('iPhone 15', 600, 10);
check('TTL cache prevents repeat marketplace calls', $res2['cached'] === true && $fake->multiCalls === 1, json_encode($res2));

$res3 = $liveManager->liveSearchCached('iPhone 15', 0, 10);
check('expired TTL refetches', $res3['cached'] === false && $fake->multiCalls === 2);

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
