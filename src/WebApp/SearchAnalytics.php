<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

use MarketBot\Core\Database;
use PDO;

/**
 * Tracks user search queries so cron can pre-fetch popular keywords.
 *
 * Designed to fail silently: search logging happens in the hot path and a
 * write failure must never break the API response. Callers wrap calls in
 * try/catch or use the @ operator-equivalent (defensive try/catch inside).
 */
final class SearchAnalytics
{
    /** Maximum length of a tracked query (longer ones are truncated). */
    private const MAX_QUERY_LENGTH = 120;

    /**
     * Record a single search event. Returns silently on any DB error.
     *
     * @param string $query    The exact (trimmed) user query
     * @param int    $results  Number of items finally returned to the user
     * @param bool   $liveUsed Whether the live-search fallback ran
     * @param string $ip       Client IP (will be SHA-256 hashed for privacy)
     */
    public function record(string $query, int $results, bool $liveUsed, string $ip): void
    {
        $q = trim($query);
        if ($q === '') {
            return;
        }
        if (function_exists('mb_strlen') && mb_strlen($q, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_QUERY_LENGTH, 'UTF-8');
        } elseif (strlen($q) > self::MAX_QUERY_LENGTH * 4) {
            $q = substr($q, 0, self::MAX_QUERY_LENGTH * 4);
        }

        try {
            // `query` is a reserved word in MySQL — backtick it on MySQL,
            // double-quote it on SQLite. Keep both driver-specific to avoid
            // relying on ANSI_QUOTES sql_mode.
            $sql = Database::isSqlite()
                ? 'INSERT INTO search_logs ("query", results, live_used, ip_hash) VALUES (:q, :r, :l, :h)'
                : 'INSERT INTO search_logs (`query`, results, live_used, ip_hash) VALUES (:q, :r, :l, :h)';
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute([
                'q' => $q,
                'r' => max(0, $results),
                'l' => $liveUsed ? 1 : 0,
                'h' => substr(hash('sha256', $ip . '|narxbor'), 0, 32),
            ]);
        } catch (\Throwable $e) {
            // Swallow — the API still works even if analytics are down.
        }
    }

    /**
     * Most-searched queries in the recent window. Used by:
     *  - admin Keywords page (to suggest queries to pre-fetch)
     *  - the hot-keywords cron (to refresh popular searches)
     *
     * @return array<int, array{query:string, hits:int, last_results:int, zero_count:int}>
     */
    public function topQueries(int $hours = 24, int $limit = 50): array
    {
        $col = Database::isSqlite() ? '"query"' : '`query`';
        try {
            $where = Database::isSqlite()
                ? "datetime('now','-' || :h || ' hours')"
                : "(NOW() - INTERVAL :h HOUR)";
            $sql = "SELECT $col AS q,
                           COUNT(*) AS hits,
                           AVG(results) AS avg_res,
                           SUM(CASE WHEN results = 0 THEN 1 ELSE 0 END) AS zero
                      FROM search_logs
                     WHERE created_at >= $where
                  GROUP BY $col
                  ORDER BY hits DESC, zero DESC
                  LIMIT :lim";
            $stmt = Database::pdo()->prepare($sql);
            $stmt->bindValue(':h', $hours, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'query'        => (string) $r['q'],
                    'hits'         => (int) $r['hits'],
                    'last_results' => (int) round((float) $r['avg_res']),
                    'zero_count'   => (int) $r['zero'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Hits with zero results — these are the queries that most need to be
     * added to the cron's hot-keywords list because users keep asking and
     * we keep failing them.
     *
     * @return array<int, array{query:string, hits:int}>
     */
    public function zeroResultQueries(int $hours = 24, int $limit = 30): array
    {
        $col = Database::isSqlite() ? '"query"' : '`query`';
        try {
            $where = Database::isSqlite()
                ? "datetime('now','-' || :h || ' hours')"
                : "(NOW() - INTERVAL :h HOUR)";
            $sql = "SELECT $col AS q, COUNT(*) AS hits
                      FROM search_logs
                     WHERE results = 0
                       AND created_at >= $where
                  GROUP BY $col
                  ORDER BY hits DESC
                  LIMIT :lim";
            $stmt = Database::pdo()->prepare($sql);
            $stmt->bindValue(':h', $hours, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'query' => (string) $r['q'],
                    'hits'  => (int) $r['hits'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Prune old search log rows. Called by cron alongside backup.
     */
    public function prune(int $keepDays = 30): int
    {
        try {
            $sql = Database::isSqlite()
                ? "DELETE FROM search_logs WHERE created_at < datetime('now','-' || :d || ' days')"
                : "DELETE FROM search_logs WHERE created_at < (NOW() - INTERVAL :d DAY)";
            $stmt = Database::pdo()->prepare($sql);
            $stmt->bindValue(':d', $keepDays, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
