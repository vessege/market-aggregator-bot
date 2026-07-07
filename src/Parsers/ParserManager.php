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
