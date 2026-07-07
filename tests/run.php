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
use MarketBot\Parsers\ParsedProduct;
use MarketBot\Parsers\ParserManager;
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

echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
