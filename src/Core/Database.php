<?php
declare(strict_types=1);

namespace MarketBot\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';

    /** @param array<string,mixed> $cfg */
    public static function init(array $cfg): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = strtolower((string) ($cfg['driver'] ?? 'mysql'));
        self::$driver = $driver;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = (string) $cfg['sqlite_path'];
                if (!is_dir(dirname($path))) {
                    @mkdir(dirname($path), 0775, true);
                }
                $dsn = 'sqlite:' . $path;
                self::$pdo = new PDO($dsn, null, null, $options);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $cfg['host'],
                    (int) $cfg['port'],
                    $cfg['name']
                );
                self::$pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], $options);
                self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new RuntimeException('Database not initialized; call Database::init() first');
        }
        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function isMysql(): bool
    {
        return self::$driver === 'mysql';
    }

    public static function isSqlite(): bool
    {
        return self::$driver === 'sqlite';
    }

    /**
     * Run a SQL file (split on `;`). Robust enough for our schema files which
     * don't use stored procedures or DELIMITER blocks.
     */
    public static function runSqlFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        $statements = self::splitSql($sql);
        $pdo = self::pdo();
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    /** @return string[] */
    private static function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';
            if ($ch === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
                $inDouble = !$inDouble;
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }
            if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                // Trigger bodies (BEGIN ... END;) contain semicolons — keep
                // buffering until the closing END is reached.
                if (self::isUnterminatedTrigger($buffer)) {
                    $buffer .= $ch;
                    continue;
                }
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $ch;
        }
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }
        return $statements;
    }

    private static function isUnterminatedTrigger(string $buffer): bool
    {
        if (!preg_match('/create\s+trigger/i', $buffer)) {
            return false;
        }
        return !preg_match('/\bend\s*$/i', rtrim($buffer));
    }
}
