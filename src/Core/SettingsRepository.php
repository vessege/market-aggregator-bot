<?php
declare(strict_types=1);

namespace MarketBot\Core;

use PDO;

/**
 * Thin wrapper around the `settings` key-value table. Used by both the
 * admin UI and the CurrencyConverter override path. Schema quotes the
 * reserved word `key` driver-specifically.
 */
final class SettingsRepository
{
    private function col(string $name): string
    {
        return Database::isSqlite() ? "\"$name\"" : "`$name`";
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $k = $this->col('key');
        $v = $this->col('value');
        $stmt = Database::pdo()->prepare("SELECT $v AS v FROM settings WHERE $k = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? $default : (string) $row['v'];
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $k = $this->col('key');
        $v = $this->col('value');
        $rows = Database::pdo()->query("SELECT $k AS k, $v AS v FROM settings")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['k']] = (string) ($r['v'] ?? '');
        }
        return $out;
    }

    public function set(string $key, ?string $value): void
    {
        $pdo = Database::pdo();
        $k = $this->col('key');
        $v = $this->col('value');
        $u = $this->col('updated_at');
        if (Database::isSqlite()) {
            $sql = "INSERT INTO settings ($k, $v, $u) VALUES (:k, :v, :u)
                    ON CONFLICT($k) DO UPDATE SET $v = excluded.$v, $u = excluded.$u";
        } else {
            $sql = "INSERT INTO settings ($k, $v, $u) VALUES (:k, :v, :u)
                    ON DUPLICATE KEY UPDATE $v = VALUES($v), $u = VALUES($u)";
        }
        $pdo->prepare($sql)->execute([
            'k' => $key,
            'v' => $value,
            'u' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Bulk-set; invalidates CurrencyConverter cache so FX changes apply immediately. */
    public function setMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            $this->set((string) $k, $v === null ? null : (string) $v);
        }
        CurrencyConverter::reset();
    }
}
