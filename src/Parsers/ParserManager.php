<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Core\Database;
use MarketBot\Core\Logger;
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

    /**
     * Run a live full-text search across all (or a filtered set of) registered
     * parsers and persist the results to the products table.
     *
     * @param array<string>|null $sources Limit to these source slugs (null = all).
     * @return array{ok:bool, total:int, inserted:int, updated:int, by_source:array<string,array<string,int>>, errors:array<string,string>}
     */
    public function searchAll(string $query, ?array $sources = null, int $limitPerSource = 20): array
    {
        $inserted = 0;
        $updated = 0;
        $total = 0;
        $bySource = [];
        $errors = [];

        $targets = $sources === null
            ? array_keys($this->parsers)
            : array_values(array_intersect($sources, array_keys($this->parsers)));

        foreach ($targets as $src) {
            $parser = $this->parsers[$src];
            $i = 0;
            $u = 0;
            try {
                foreach ($parser->searchQuery($query, ['limit' => $limitPerSource]) as $product) {
                    $res = $this->upsertProduct($product);
                    if ($res === 'inserted') {
                        $inserted++;
                        $i++;
                    } elseif ($res === 'updated') {
                        $updated++;
                        $u++;
                    }
                    $total++;
                }
                $bySource[$src] = ['inserted' => $i, 'updated' => $u];
            } catch (\Throwable $e) {
                Logger::error('parser', "live search failed for $src", ['error' => $e->getMessage()]);
                $errors[$src] = $e->getMessage();
            }
        }

        return [
            'ok' => empty($errors) || $total > 0,
            'total' => $total,
            'inserted' => $inserted,
            'updated' => $updated,
            'by_source' => $bySource,
            'errors' => $errors,
        ];
    }

    /**
     * Public upsert helper for callers that build ParsedProducts themselves
     * (e.g. dynamic JSON parsers configured at runtime).
     *
     * @return 'inserted'|'updated'|'skipped'
     */
    public function upsert(ParsedProduct $p): string
    {
        return $this->upsertProduct($p);
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

        $check = $pdo->prepare('SELECT id FROM products WHERE source = :s AND external_id = :e LIMIT 1');
        $check->execute(['s' => $row['source'], 'e' => $row['external_id']]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Capture price change BEFORE we overwrite the row so we can
            // append to price_history when the price moves.
            $prev = $pdo->prepare('SELECT price, currency FROM products WHERE id = :id LIMIT 1');
            $prev->execute(['id' => (int) $existing['id']]);
            $prevRow = $prev->fetch(PDO::FETCH_ASSOC) ?: null;

            $sets = [];
            $params = ['id' => (int) $existing['id']];
            foreach ($row as $k => $v) {
                if ($k === 'source' || $k === 'external_id') {
                    continue;
                }
                $sets[] = "$k = :$k";
                $params[$k] = $v;
            }
            $sets[] = 'updated_at = :__updated';
            $params['__updated'] = date('Y-m-d H:i:s');
            $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);

            self::recordPriceHistory(
                (int) $existing['id'],
                (float) $row['price'],
                (string) ($row['currency'] ?? 'UZS'),
                $prevRow ? (float) $prevRow['price'] : null,
                $prevRow ? (string) ($prevRow['currency'] ?? 'UZS') : null,
            );
            return 'updated';
        }

        $cols = array_keys($row);
        $sql = 'INSERT INTO products (' . implode(',', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
        $pdo->prepare($sql)->execute($row);
        $newId = (int) $pdo->lastInsertId();

        // First sighting — always record a baseline snapshot.
        self::recordPriceHistory(
            $newId,
            (float) $row['price'],
            (string) ($row['currency'] ?? 'UZS'),
            prevPrice: null,
            prevCurrency: null,
        );
        return 'inserted';
    }

    /**
     * Append a row to price_history if the price actually moved. Same-price
     * upserts would otherwise bloat the table without adding any signal.
     */
    private static function recordPriceHistory(
        int $productId,
        float $price,
        string $currency,
        ?float $prevPrice,
        ?string $prevCurrency,
    ): void {
        if ($price <= 0) {
            return;
        }
        // Skip if the price (and currency) didn't change.
        if ($prevPrice !== null && abs($prevPrice - $price) < 0.005 && $prevCurrency === $currency) {
            return;
        }
        try {
            Database::pdo()->prepare(
                'INSERT INTO price_history (product_id, price, currency, captured_at)
                 VALUES (:pid, :p, :c, :now)'
            )->execute([
                'pid' => $productId,
                'p'   => $price,
                'c'   => $currency,
                'now' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('price_history', 'insert failed', [
                'product_id' => $productId, 'err' => $e->getMessage(),
            ]);
        }
    }
}
