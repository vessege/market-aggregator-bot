<?php
declare(strict_types=1);

/**
 * MarketCompare backup script.
 *
 * - SQLite: copies the .sqlite file (with a WAL checkpoint) into
 *   storage/backups/db-YYYYMMDD-HHMMSS.sqlite, then gzips it.
 * - MySQL: runs `mysqldump` if available and writes db-*.sql.gz.
 * - Cleans up backups older than 14 days (configurable via BACKUP_KEEP_DAYS).
 *
 * Suggested cron (daily at 03:30):
 *   30 3 * * * /usr/bin/php /path/to/bin/backup.php >> storage/logs/backup.log 2>&1
 */

use MarketBot\Core\Bootstrap;
use MarketBot\Core\Database;
use MarketBot\Core\Env;
use MarketBot\Core\Logger;

require_once dirname(__DIR__) . '/src/Core/Bootstrap.php';
Bootstrap::init();

$root   = dirname(__DIR__);
$dest   = $root . '/storage/backups';
if (!is_dir($dest)) {
    @mkdir($dest, 0775, true);
}
if (!is_writable($dest)) {
    fwrite(STDERR, "[backup] storage/backups not writable\n");
    exit(1);
}

$ts   = date('Ymd-His');
$keep = max(1, (int) Env::get('BACKUP_KEEP_DAYS', '14'));

if (Database::isSqlite()) {
    $srcPath = (string) Env::get('DB_SQLITE_PATH', 'storage/database.sqlite');
    if ($srcPath[0] !== '/') $srcPath = $root . '/' . ltrim($srcPath, '/');
    if (!is_file($srcPath)) {
        fwrite(STDERR, "[backup] sqlite file not found: $srcPath\n");
        exit(1);
    }

    // Checkpoint WAL so the snapshot is consistent.
    try {
        Database::pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    } catch (\Throwable $e) {
        // Non-fatal; continue with the copy.
        Logger::error('backup', 'wal checkpoint failed', ['err' => $e->getMessage()]);
    }

    $outRaw = "$dest/db-$ts.sqlite";
    if (!@copy($srcPath, $outRaw)) {
        fwrite(STDERR, "[backup] copy failed\n");
        exit(2);
    }

    $outGz  = $outRaw . '.gz';
    if (gzipFile($outRaw, $outGz)) {
        @unlink($outRaw);
        fwrite(STDOUT, "[backup] " . date('c') . " wrote $outGz (" . sizeFmt($outGz) . ")\n");
    } else {
        fwrite(STDOUT, "[backup] " . date('c') . " wrote $outRaw (gzip skipped)\n");
    }
} else {
    // MySQL path — only if mysqldump is on $PATH.
    $bin = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($bin === '') {
        fwrite(STDERR, "[backup] mysqldump not found on PATH\n");
        exit(3);
    }
    $host = (string) Env::get('DB_HOST', '127.0.0.1');
    $port = (string) Env::get('DB_PORT', '3306');
    $db   = (string) Env::get('DB_NAME', '');
    $user = (string) Env::get('DB_USER', '');
    $pass = (string) Env::get('DB_PASS', '');
    if ($db === '' || $user === '') {
        fwrite(STDERR, "[backup] DB_NAME / DB_USER missing in .env\n");
        exit(4);
    }
    $outGz = "$dest/db-$ts.sql.gz";
    // Use --defaults-extra-file via a temp config so the password never
    // appears in `ps`.
    $tmp = tempnam(sys_get_temp_dir(), 'mybak');
    file_put_contents($tmp, "[client]\nuser=$user\npassword=$pass\nhost=$host\nport=$port\n");
    @chmod($tmp, 0600);
    $cmd = escapeshellcmd($bin)
        . ' --defaults-extra-file=' . escapeshellarg($tmp)
        . ' --single-transaction --quick --routines --triggers '
        . escapeshellarg($db) . ' | gzip > ' . escapeshellarg($outGz);
    $rc  = 0;
    $out = [];
    exec($cmd, $out, $rc);
    @unlink($tmp);
    if ($rc !== 0) {
        fwrite(STDERR, "[backup] mysqldump exit=$rc\n");
        exit(5);
    }
    fwrite(STDOUT, "[backup] " . date('c') . " wrote $outGz (" . sizeFmt($outGz) . ")\n");
}

// Retention sweep
$cutoff = time() - $keep * 86400;
$removed = 0;
foreach (glob("$dest/db-*") ?: [] as $f) {
    if (is_file($f) && filemtime($f) < $cutoff) {
        if (@unlink($f)) $removed++;
    }
}
if ($removed > 0) {
    fwrite(STDOUT, "[backup] swept $removed file(s) older than $keep days\n");
}

function gzipFile(string $src, string $dst): bool
{
    $in  = @fopen($src, 'rb');
    $out = @gzopen($dst, 'wb9');
    if (!$in || !$out) return false;
    while (!feof($in)) gzwrite($out, (string) fread($in, 1024 * 512));
    fclose($in);
    gzclose($out);
    return true;
}

function sizeFmt(string $path): string
{
    $b = @filesize($path) ?: 0;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024) return round($b, 1) . $u;
        $b /= 1024;
    }
    return round($b, 1) . 'TB';
}
