<?php
declare(strict_types=1);

/**
 * Dynamic sitemap. Lists the home page + every active product.
 * Linked from robots.txt. Capped at 50 000 URLs (sitemaps.org limit);
 * if the catalog ever exceeds that, switch to a sitemap index.
 */

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Database;
use MarketBot\Core\Env;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
Bootstrap::init();

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$envBase = (string) Env::get('PUBLIC_URL', '');
if ($envBase !== '') {
    $base = rtrim($envBase, '/');
} else {
    $scheme = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path   = rtrim(dirname((string) $_SERVER['SCRIPT_NAME']), '/');
    $base   = $scheme . '://' . $host . $path;
}

$rows = [];
try {
    $stmt = Database::pdo()->query(
        'SELECT id, updated_at FROM products WHERE is_active = 1 ORDER BY updated_at DESC LIMIT 50000'
    );
    $rows = $stmt->fetchAll() ?: [];
} catch (\Throwable $e) {
    // Render only the home URL if the DB is unreachable.
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo "  <url><loc>" . htmlspecialchars($base . '/') . "</loc><changefreq>daily</changefreq><priority>1.0</priority></url>\n";
foreach ($rows as $r) {
    $loc = $base . '/#product/' . (int) $r['id'];
    $lm  = isset($r['updated_at']) ? date('c', strtotime((string) $r['updated_at']) ?: time()) : date('c');
    echo "  <url><loc>" . htmlspecialchars($loc) . "</loc><lastmod>$lm</lastmod></url>\n";
}
echo '</urlset>' . "\n";
