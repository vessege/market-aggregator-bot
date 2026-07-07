<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Core\Currency;
use MarketBot\Core\Database;
use MarketBot\Core\Logger;
use MarketBot\Core\TextNormalizer;
use PDO;
use RuntimeException;

final class ParserManager
{
    /** @var array<string, BaseParser> */
    private array $parsers = [];

    public function __construct(private HttpClient $http) {}

    public function register(BaseParser $parser): void
    {
        $this->parsers[$parser->source()] = $parser;
    }

    /** @return array<string, BaseParser> */
    public function all(): array
    {
        return $this->parsers;
    }

    public function get(string $source): BaseParser
    {
        if (!isset($this->parsers[$source])) {
            throw new RuntimeException("Parser '$source' is not registered");
        }
        return $this->parsers[$source];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{ok:bool, run_id:int, inserted:int, updated:int, error:?string}
     */
    public function runSource(string $source, array $options = []): array
    {
        $parser = $this->get($source);
        $pdo = Database::pdo();

        $pdo->prepare(
            'INSERT INTO parser_runs (source, status, params, started_at)
             VALUES (:s, :st, :p, :now)'
        )->execute([
            's'   => $source,
            'st'  => 'running',
            'p'   => json_encode($options, JSON_UNESCAPED_UNICODE),
            'now' => date('Y-m-d H:i:s'),
        ]);
        $runId = (int) $pdo->lastInsertId();

        $inserted = 0;
        $updated = 0;
        $error = null;
        try {
            foreach ($parser->fetchFeed($options) as $product) {
                $res = $this->upsertProduct($product);
                if ($res === 'inserted') {
                    $inserted++;
                } elseif ($res === 'updated') {
                    $updated++;
                }
            }
            $status = 'done';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $status = 'failed';
            Logger::error('parser', "run failed for $source", ['error' => $error]);
        }

        $pdo->prepare(
            'UPDATE parser_runs
                SET status = :st, inserted_count = :i, updated_count = :u, error = :e, finished_at = :now
              WHERE id = :id'
        )->execute([
            'st'  => $status,
            'i'   => $inserted,
            'u'   => $updated,
            'e'   => $error,
            'now' => date('Y-m-d H:i:s'),
            'id'  => $runId,
        ]);

        return ['ok' => $status === 'done', 'run_id' => $runId, 'inserted' => $inserted, 'updated' => $updated, 'error' => $error];
    }

    /**
     * Live keyword search across every registered parser that supports it.
     * Marketplace requests run in parallel; results are upserted into the
     * products table so the regular DB search picks them up immediately.
     *
     * @return array{ok:bool, fetched:int, upserted:int, sources:array<string,array{ok:bool,count:int,error:?string}>}
     */
    public function liveSearch(string $query, int $limitPerSource = 10): array
    {
        $requests = [];
        foreach ($this->parsers as $src => $parser) {
            $req = $parser->searchRequest($query, $limitPerSource);
            if ($req !== null) {
                $requests[$src] = $req;
            }
        }
        if ($requests === []) {
            return ['ok' => true, 'fetched' => 0, 'upserted' => 0, 'sources' => []];
        }

        $responses = $this->http->multiGet($requests);

        $fetched = 0;
        $upserted = 0;
        $sources = [];
        foreach ($responses as $src => $resp) {
            if (!$resp['ok']) {
                $sources[$src] = ['ok' => false, 'count' => 0, 'error' => $resp['error'] ?? ('HTTP ' . $resp['status'])];
                Logger::error('live-search', "$src request failed", ['status' => $resp['status'], 'error' => $resp['error']]);
                continue;
            }
            try {
                $items = $this->parsers[$src]->parseSearchResponse($resp['body'], $limitPerSource);
            } catch (\Throwable $e) {
                $sources[$src] = ['ok' => false, 'count' => 0, 'error' => $e->getMessage()];
                Logger::error('live-search', "$src parse failed", ['error' => $e->getMessage()]);
                continue;
            }
            $count = 0;
            foreach ($items as $item) {
                $fetched++;
                if ($this->upsertProduct($item) !== 'skipped') {
                    $upserted++;
                }
                $count++;
            }
            $sources[$src] = ['ok' => true, 'count' => $count, 'error' => null];
        }

        return ['ok' => true, 'fetched' => $fetched, 'upserted' => $upserted, 'sources' => $sources];
    }

    /**
     * liveSearch() guarded by a TTL cache keyed on the normalized query, so
     * repeated identical searches within the window don't hit marketplaces.
     *
     * @return array{ok:bool, cached:bool, fetched:int, upserted:int}
     */
    public function liveSearchCached(string $query, int $ttlSeconds = 600, int $limitPerSource = 10): array
    {
        $key = mb_substr(TextNormalizer::normalize($query), 0, 190);
        if ($key === '') {
            return ['ok' => true, 'cached' => false, 'fetched' => 0, 'upserted' => 0];
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT searched_at FROM live_search_cache WHERE query = :q');
        $stmt->execute(['q' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (time() - (int) strtotime((string) $row['searched_at'])) < $ttlSeconds) {
            return ['ok' => true, 'cached' => true, 'fetched' => 0, 'upserted' => 0];
        }

        // Claim the cache slot before fetching so concurrent identical
        // queries don't stampede the marketplaces.
        $now = date('Y-m-d H:i:s');
        if (Database::isSqlite()) {
            $pdo->prepare('INSERT INTO live_search_cache (query, searched_at) VALUES (:q, :now)
                           ON CONFLICT(query) DO UPDATE SET searched_at = excluded.searched_at')
                ->execute(['q' => $key, 'now' => $now]);
        } else {
            $pdo->prepare('INSERT INTO live_search_cache (query, searched_at) VALUES (:q, :now)
                           ON DUPLICATE KEY UPDATE searched_at = VALUES(searched_at)')
                ->execute(['q' => $key, 'now' => $now]);
        }

        $res = $this->liveSearch($query, $limitPerSource);
        return ['ok' => $res['ok'], 'cached' => false, 'fetched' => $res['fetched'], 'upserted' => $res['upserted']];
    }

    /**
     * Refresh a single product from its source — used for "live" detail view.
     */
    public function refreshProduct(string $source, string $externalId): ?ParsedProduct
    {
        $parser = $this->get($source);
        $product = $parser->fetchOne($externalId);
        if ($product !== null) {
            $this->upsertProduct($product);
        }
        return $product;
    }

    /** @return 'inserted'|'updated'|'skipped' */
    private function upsertProduct(ParsedProduct $p): string
    {
        $pdo = Database::pdo();
        $row = $p->toRow();

        $categoryId = null;
        if (!empty($row['category_slug'])) {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug LIMIT 1');
            $stmt->execute(['slug' => $row['category_slug']]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                $categoryId = (int) $found['id'];
            }
        }
        unset($row['category_slug']);
        $row['category_id'] = $categoryId;

        $row['search_text'] = TextNormalizer::normalize(
            $p->title . ' ' . ($p->description ?? '') . ' ' . ($p->seller ?? '')
        );
        $row['price_uzs'] = Currency::toUzs($p->price, $p->currency);

        // Timestamps come from PHP (app timezone) on both drivers so that the
        // API staleness check compares like with like.
        $now = date('Y-m-d H:i:s');
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        // Stats only — the write below is atomic either way.
        $check = $pdo->prepare('SELECT 1 FROM products WHERE source = :s AND external_id = :e LIMIT 1');
        $check->execute(['s' => $row['source'], 'e' => $row['external_id']]);
        $exists = (bool) $check->fetchColumn();

        $cols = array_keys($row);
        $updatable = array_diff($cols, ['source', 'external_id', 'created_at']);

        if (Database::isSqlite()) {
            $sets = implode(', ', array_map(static fn ($c) => "$c = excluded.$c", $updatable));
            $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')
                    ON CONFLICT(source, external_id) DO UPDATE SET ' . $sets;
        } else {
            $sets = implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", $updatable));
            $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')
                    ON DUPLICATE KEY UPDATE ' . $sets;
        }
        $pdo->prepare($sql)->execute($row);

        return $exists ? 'updated' : 'inserted';
    }
}
