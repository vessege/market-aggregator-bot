<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

use MarketBot\Core\Database;
use PDO;

/**
 * Admin-curated list of search keywords the cron pre-fetches every run.
 *
 * Workflow:
 *  - Admin adds keywords manually (e.g. "samsung", "iphone", "stol", ...).
 *  - SearchAnalytics tracks what users actually search; admin can promote
 *    zero-result queries to this table in one click.
 *  - bin/refresh-hot-keywords.php iterates active keywords ordered by
 *    priority and last_fetched_at, runs the parser for each, persists
 *    products. Older fetches are picked first so the catalog stays fresh.
 */
final class HotKeywordRepository
{
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM hot_keywords' . ($onlyActive ? ' WHERE is_active = 1' : '')
             . ' ORDER BY priority DESC, keyword ASC';
        $stmt = Database::pdo()->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * Returns the N keywords most overdue for a refresh. Newly-added keywords
     * (last_fetched_at IS NULL) sort first.
     */
    public function dueForRefresh(int $limit = 20): array
    {
        // NULL last_fetched_at must sort first; emulate on both drivers.
        $sql = 'SELECT * FROM hot_keywords
                 WHERE is_active = 1
              ORDER BY (last_fetched_at IS NULL) DESC,
                       last_fetched_at ASC,
                       priority DESC
                 LIMIT :lim';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function add(string $keyword, int $priority = 10): bool
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return false;
        }
        try {
            $sql = Database::isSqlite()
                ? 'INSERT OR IGNORE INTO hot_keywords (keyword, priority, is_active) VALUES (:k, :p, 1)'
                : 'INSERT IGNORE INTO hot_keywords (keyword, priority, is_active) VALUES (:k, :p, 1)';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute(['k' => $kw, 'p' => $priority]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = Database::pdo()->prepare('UPDATE hot_keywords SET is_active = :a WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    public function setPriority(int $id, int $priority): void
    {
        $stmt = Database::pdo()->prepare('UPDATE hot_keywords SET priority = :p WHERE id = :id');
        $stmt->execute(['p' => $priority, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM hot_keywords WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function markFetched(int $id, int $results): void
    {
        $sql = Database::isSqlite()
            ? "UPDATE hot_keywords SET last_fetched_at = datetime('now'), last_results = :r WHERE id = :id"
            : 'UPDATE hot_keywords SET last_fetched_at = NOW(), last_results = :r WHERE id = :id';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['r' => $results, 'id' => $id]);
    }
}
