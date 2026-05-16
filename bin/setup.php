<?php
declare(strict_types=1);

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Database;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';

// SAFETY: This script applies the schema, which DROPs all tables. It is
// destructive. We require:
//   1. CLI invocation (refuse when called over the web)
//   2. --confirm=YES_DESTROY (or env DESTROY_CONFIRM=YES_DESTROY)
// to prevent accidental data loss.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("setup.php is destructive and CLI-only. Use bin/migrate.php for safe schema updates.\n");
}

$argvOpts = getopt('', ['confirm:']);
$confirm  = (string) ($argvOpts['confirm'] ?? getenv('DESTROY_CONFIRM') ?: '');
if ($confirm !== 'YES_DESTROY') {
    fwrite(STDERR, "Refusing to run: pass --confirm=YES_DESTROY to acknowledge this DROPs all tables.\n");
    exit(2);
}

$config = Bootstrap::init();
$pdo = Database::pdo();

$schemaFile = Database::isSqlite()
    ? dirname(__DIR__) . '/db/schema.sqlite.sql'
    : dirname(__DIR__) . '/db/schema.mysql.sql';

echo "Loading schema from: $schemaFile\n";
Database::runSqlFile($schemaFile);
echo "Schema applied.\n";

// Seed admin user
$username = (string) $config['admin']['username'];
$password = (string) $config['admin']['password'];

$existing = $pdo->prepare('SELECT id FROM admins WHERE username = :u LIMIT 1');
$existing->execute(['u' => $username]);
if (!$existing->fetch()) {
    $pdo->prepare(
        'INSERT INTO admins (username, password_hash, display_name, is_super)
         VALUES (:u, :p, :d, 1)'
    )->execute([
        'u' => $username,
        'p' => password_hash($password, PASSWORD_BCRYPT),
        'd' => 'Super Admin',
    ]);
    echo "Created admin: $username (password from .env)\n";
} else {
    echo "Admin '$username' already exists.\n";
}

echo "Setup complete.\n";
