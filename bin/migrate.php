<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Database;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';

Bootstrap::init();
$pdo = Database::pdo();

$ext = Database::isSqlite() ? 'sqlite.sql' : 'mysql.sql';
$dir = dirname(__DIR__) . '/db/migrations';
if (!is_dir($dir)) {
    fwrite(STDERR, "No migrations directory: $dir\n");
    exit(1);
}

$pdo->exec(
    Database::isSqlite()
        ? 'CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)'
        : 'CREATE TABLE IF NOT EXISTS `schema_migrations` (`name` VARCHAR(255) NOT NULL PRIMARY KEY, `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$files = glob("$dir/*.$ext") ?: [];
sort($files);

$applied = 0;
foreach ($files as $f) {
    $name = basename($f);
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = :n');
    $stmt->execute(['n' => $name]);
    if ($stmt->fetch()) {
        echo "[skip] $name (already applied)\n";
        continue;
    }
    echo "[apply] $name ... ";
    Database::runSqlFile($f);
    $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (:n)')->execute(['n' => $name]);
    echo "done\n";
    $applied++;
}

echo "\nApplied $applied migration(s).\n";
