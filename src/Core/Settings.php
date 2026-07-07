<?php
declare(strict_types=1);

namespace MarketBot\Core;

use PDO;

/**
 * Key-value settings stored in the `settings` table, with per-request cache.
 */
final class Settings
{
    /** @var array<string,?string> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default;
        }
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE `key` = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::$cache[$key] = $row !== false ? ($row['value'] ?? null) : null;
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $pdo = Database::pdo();
        if (Database::isSqlite()) {
            $sql = 'INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, :now)
                    ON CONFLICT(`key`) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO settings (`key`, value, updated_at) VALUES (:k, :v, :now)
                    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)';
        }
        $pdo->prepare($sql)->execute(['k' => $key, 'v' => $value, 'now' => date('Y-m-d H:i:s')]);
        self::$cache[$key] = $value;
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $v = self::get($key);
        return $v !== null && is_numeric($v) ? (float) $v : $default;
    }
}
