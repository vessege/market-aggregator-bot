<?php
declare(strict_types=1);

/**
 * Idempotent migration for existing databases (fresh installs get everything
 * from db/schema.*.sql via bin/setup.php):
 *   - products.search_text + FULLTEXT/FTS5 index
 *   - products.price_uzs + index
 *   - login_attempts table
 *   - default currency rates in settings
 *   - backfill of search_text / price_uzs for existing rows
 */

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Currency;
use MarketBot\Core\Database;
use MarketBot\Core\Settings;
use MarketBot\Core\TextNormalizer;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
Bootstrap::init();
$pdo = Database::pdo();

function columnExists(PDO $pdo, string $table, string $column): bool
{
    if (Database::isSqlite()) {
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (strcasecmp((string) $col['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (\Throwable) {
        return false;
    }
}

// 1. New columns
if (!columnExists($pdo, 'products', 'search_text')) {
    $pdo->exec('ALTER TABLE products ADD COLUMN search_text TEXT NULL');
    echo "+ products.search_text\n";
}
if (!columnExists($pdo, 'products', 'price_uzs')) {
    $pdo->exec(Database::isSqlite()
        ? 'ALTER TABLE products ADD COLUMN price_uzs REAL NULL'
        : 'ALTER TABLE products ADD COLUMN price_uzs DECIMAL(16,2) NULL');
    echo "+ products.price_uzs\n";
}

// 2. Indexes / FTS
if (Database::isMysql()) {
    $idx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'ft_products_search'")->fetchAll();
    if (!$idx) {
        $pdo->exec('ALTER TABLE products ADD FULLTEXT ft_products_search (search_text)');
        echo "+ FULLTEXT ft_products_search\n";
    } else {
        // Old index was on (title, description) — rebuild on search_text.
        $cols = array_map(static fn ($r) => strtolower((string) $r['Column_name']), $idx);
        if (!in_array('search_text', $cols, true)) {
            $pdo->exec('ALTER TABLE products DROP INDEX ft_products_search');
            $pdo->exec('ALTER TABLE products ADD FULLTEXT ft_products_search (search_text)');
            echo "~ FULLTEXT ft_products_search rebuilt on search_text\n";
        }
    }
    $hasIdx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_active_price_uzs'")->fetchAll();
    if (!$hasIdx) {
        $pdo->exec('CREATE INDEX idx_products_active_price_uzs ON products (is_active, price_uzs)');
        echo "+ idx_products_active_price_uzs\n";
    }
} else {
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_products_active_price_uzs ON products(is_active, price_uzs)');
    if (!tableExists($pdo, 'products_fts')) {
        try {
            $pdo->exec("CREATE VIRTUAL TABLE products_fts USING fts5(search_text, content='products', content_rowid='id')");
            $pdo->exec('CREATE TRIGGER products_fts_ai AFTER INSERT ON products BEGIN
                INSERT INTO products_fts(rowid, search_text) VALUES (new.id, new.search_text);
            END');
            $pdo->exec("CREATE TRIGGER products_fts_ad AFTER DELETE ON products BEGIN
                INSERT INTO products_fts(products_fts, rowid, search_text) VALUES ('delete', old.id, old.search_text);
            END");
            $pdo->exec("CREATE TRIGGER products_fts_au AFTER UPDATE ON products BEGIN
                INSERT INTO products_fts(products_fts, rowid, search_text) VALUES ('delete', old.id, old.search_text);
                INSERT INTO products_fts(rowid, search_text) VALUES (new.id, new.search_text);
            END");
            // Index the rows that existed before the FTS table was created —
            // without this, the delete-side of the triggers corrupts the index.
            $pdo->exec("INSERT INTO products_fts(products_fts) VALUES ('rebuild')");
            echo "+ products_fts (FTS5) + triggers\n";
        } catch (\Throwable $e) {
            echo "! FTS5 unavailable (" . $e->getMessage() . ") — search will use LIKE fallback\n";
        }
    }
}

// 3. login_attempts
if (!tableExists($pdo, 'login_attempts')) {
    $pdo->exec(Database::isSqlite()
        ? 'CREATE TABLE login_attempts (
             id INTEGER PRIMARY KEY AUTOINCREMENT,
             ip TEXT NOT NULL,
             username TEXT,
             attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)'
        : 'CREATE TABLE login_attempts (
             id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
             ip VARCHAR(45) NOT NULL,
             username VARCHAR(64) NULL,
             attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
             PRIMARY KEY (id)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE INDEX idx_login_attempts_ip ON login_attempts (ip, attempted_at)');
    echo "+ login_attempts\n";
}

// 4. Default rates
if (Settings::get('rate_usd_uzs') === null) {
    Settings::set('rate_usd_uzs', '12900');
    Settings::set('rate_rub_uzs', '150');
    echo "+ default currency rates (run bin/update-rates.php for live rates)\n";
}

// 5. Backfill
$rows = $pdo->query(
    "SELECT id, title, description, seller, price, currency
       FROM products
      WHERE search_text IS NULL OR search_text = '' OR price_uzs IS NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare('UPDATE products SET search_text = :st, price_uzs = :pu WHERE id = :id');
$n = 0;
foreach ($rows as $r) {
    $upd->execute([
        'st' => TextNormalizer::normalize(
            (string) $r['title'] . ' ' . (string) ($r['description'] ?? '') . ' ' . (string) ($r['seller'] ?? '')
        ),
        'pu' => Currency::toUzs((float) $r['price'], (string) $r['currency']),
        'id' => (int) $r['id'],
    ]);
    $n++;
}
echo "Backfilled $n products.\nMigration complete.\n";
