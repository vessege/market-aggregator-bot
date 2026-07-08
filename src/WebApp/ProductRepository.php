<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

use MarketBot\Core\Database;
use MarketBot\Core\TextNormalizer;
use PDO;

final class ProductRepository
{
    /**
     * @param array<string,mixed> $filter
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filter = []): array
    {
        $pdo = Database::pdo();

        $where = ['p.is_active = 1'];
        $params = [];
        $joins = '';
        $relevanceOrder = null;

        $searchOrderParams = [];
        if (!empty($filter['q'])) {
            $fts = $this->buildSearchClause((string) $filter['q']);
            $where[] = $fts['where'];
            $joins .= $fts['join'];
            $params += $fts['params'];
            $relevanceOrder = $fts['order'];
            $searchOrderParams = $fts['order_params'];
        }
        if (!empty($filter['category'])) {
            $where[] = 'c.slug = :cat';
            $params['cat'] = $filter['category'];
        }
        if (!empty($filter['source'])) {
            $where[] = 'p.source = :src';
            $params['src'] = $filter['source'];
        }
        // Narx filtri/saralash UZSga keltirilgan qiymat ustida ishlaydi,
        // aks holda RUB va UZS raqamlari bevosita solishtirilib qoladi.
        // Qiymatlar SQLga raqam sifatida yoziladi (PDO string sifatida
        // bog'lasa SQLite'da REAL >= TEXT doim false bo'ladi).
        if (!empty($filter['min_price'])) {
            $where[] = 'COALESCE(p.price_uzs, p.price) >= ' . sprintf('%.2F', (float) $filter['min_price']);
        }
        if (!empty($filter['max_price'])) {
            $where[] = 'COALESCE(p.price_uzs, p.price) <= ' . sprintf('%.2F', (float) $filter['max_price']);
        }

        $sort = $filter['sort'] ?? 'popular';
        $orderBy = match ($sort) {
            'price_asc'  => 'COALESCE(p.price_uzs, p.price) ASC',
            'price_desc' => 'COALESCE(p.price_uzs, p.price) DESC',
            'rating'     => 'p.rating DESC, p.reviews_count DESC',
            'newest'     => 'p.created_at DESC',
            default      => $relevanceOrder ?? 'p.sold_count DESC, p.rating DESC',
        };
        if ($orderBy === $relevanceOrder) {
            // The relevance ORDER BY may carry its own placeholders; only
            // bind them when that ordering is actually used.
            $params += $searchOrderParams;
        }

        $limit  = max(1, min(100, (int) ($filter['limit']  ?? 30)));
        $offset = max(0,            (int) ($filter['offset'] ?? 0));

        $sql = "SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                $joins
                WHERE " . implode(' AND ', $where) . "
                ORDER BY $orderBy
                LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Build the full-text search clause for a raw user query.
     *
     * MySQL: MATCH ... AGAINST on the ft_products_search FULLTEXT index in
     * BOOLEAN MODE with `+token*` prefix operators. SQLite: FTS5 virtual
     * table (products_fts) with `token*` prefix matching and bm25 ranking.
     * Short/empty token sets fall back to an escaped LIKE on search_text.
     *
     * @return array{where:string, join:string, params:array<string,string>, order:?string, order_params:array<string,string>}
     */
    private function buildSearchClause(string $rawQuery): array
    {
        $normalized = TextNormalizer::normalize($rawQuery);
        $tokens = TextNormalizer::tokens($normalized);

        // Full-text engines skip very short tokens (MySQL default
        // innodb_ft_min_token_size = 3), so only use them when every token
        // is long enough; otherwise LIKE gives correct (if slower) results.
        $minTokenLen = 3;
        $ftsUsable = $tokens !== []
            && count(array_filter($tokens, fn ($t) => mb_strlen($t) >= $minTokenLen)) === count($tokens);

        if ($ftsUsable && Database::isMysql()) {
            $bool = implode(' ', array_map(
                static fn ($t) => '+' . str_replace(['+', '-', '@', '<', '>', '(', ')', '~', '*', '"'], '', $t) . '*',
                $tokens
            ));
            return [
                'where'  => 'MATCH(p.search_text) AGAINST(:ftq IN BOOLEAN MODE)',
                'join'   => '',
                'params' => ['ftq' => $bool],
                'order'  => 'MATCH(p.search_text) AGAINST(:ftq2 IN BOOLEAN MODE) DESC, p.sold_count DESC',
                'order_params' => ['ftq2' => $bool],
            ];
        }

        if ($ftsUsable && Database::isSqlite() && $this->sqliteFtsAvailable()) {
            $match = implode(' ', array_map(
                static fn ($t) => '"' . str_replace('"', '', $t) . '"*',
                $tokens
            ));
            return [
                'where'  => '1=1',
                'join'   => ' JOIN (SELECT rowid AS fts_id, rank AS fts_rank FROM products_fts WHERE products_fts MATCH :ftq) ft ON ft.fts_id = p.id ',
                'params' => ['ftq' => $match],
                'order'  => 'ft.fts_rank ASC, p.sold_count DESC',
                'order_params' => [],
            ];
        }

        // Fallback: escaped LIKE over the normalized column. '!' is used as
        // the escape char because a literal backslash behaves differently in
        // MySQL and SQLite string literals.
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized) . '%';
        return [
            'where'  => "p.search_text LIKE :likeq ESCAPE '!'",
            'join'   => '',
            'params' => ['likeq' => $like],
            'order'  => null,
            'order_params' => [],
        ];
    }

    private function sqliteFtsAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            try {
                Database::pdo()->query('SELECT 1 FROM products_fts LIMIT 1');
                $available = true;
            } catch (\Throwable) {
                $available = false;
            }
        }
        return $available;
    }

    /**
     * Mark a product as freshly checked. Called before a live refresh so that
     * concurrent requests don't all trigger external HTTP calls.
     */
    public function touch(int $id): void
    {
        Database::pdo()
            ->prepare('UPDATE products SET updated_at = :now WHERE id = :id')
            ->execute(['now' => date('Y-m-d H:i:s'), 'id' => $id]);
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, c.slug AS category_slug, c.name AS category_name
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        $rows = Database::pdo()->query(
            "SELECT c.id, c.slug, c.name, c.icon, c.position,
                    COUNT(p.id) AS product_count
               FROM categories c
          LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
              WHERE c.is_active = 1
           GROUP BY c.id, c.slug, c.name, c.icon, c.position
              ORDER BY c.position, c.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['product_count'] = (int) $r['product_count'];
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function favorites(int $userId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
               FROM favorites f
               JOIN products p ON p.id = f.product_id
          LEFT JOIN categories c ON c.id = p.category_id
              WHERE f.user_id = :u
              ORDER BY f.created_at DESC'
        );
        $stmt->execute(['u' => $userId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function toggleFavorite(int $userId, int $productId): bool
    {
        $pdo = Database::pdo();
        $check = $pdo->prepare('SELECT id FROM favorites WHERE user_id = :u AND product_id = :p');
        $check->execute(['u' => $userId, 'p' => $productId]);
        if ($row = $check->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare('DELETE FROM favorites WHERE id = :id')->execute(['id' => $row['id']]);
            return false;
        }
        $pdo->prepare('INSERT INTO favorites (user_id, product_id) VALUES (:u, :p)')
            ->execute(['u' => $userId, 'p' => $productId]);
        return true;
    }

    /** @param array<int> $favoriteIds */
    public function attachFavoriteFlag(array &$products, array $favoriteIds): void
    {
        $favSet = array_flip($favoriteIds);
        foreach ($products as &$p) {
            $p['is_favorite'] = isset($favSet[(int) $p['id']]);
        }
    }

    /** @return array<int> */
    public function favoriteIds(int $userId): array
    {
        $stmt = Database::pdo()->prepare('SELECT product_id FROM favorites WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'product_id'));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        $row['id']            = (int) $row['id'];
        $row['price']         = (float) $row['price'];
        $row['old_price']     = $row['old_price'] !== null ? (float) $row['old_price'] : null;
        $row['price_uzs']     = isset($row['price_uzs']) && $row['price_uzs'] !== null ? (float) $row['price_uzs'] : null;
        unset($row['search_text']);
        $row['rating']        = $row['rating'] !== null ? (float) $row['rating'] : null;
        $row['reviews_count'] = (int) ($row['reviews_count'] ?? 0);
        $row['sold_count']    = (int) ($row['sold_count'] ?? 0);
        $row['is_active']     = (int) $row['is_active'] === 1;

        $images = [];
        if (!empty($row['images_json'])) {
            $decoded = json_decode((string) $row['images_json'], true);
            if (is_array($decoded)) {
                $images = $decoded;
            }
        }
        if (empty($images) && !empty($row['image_url'])) {
            $images = [$row['image_url']];
        }
        $row['images'] = $images;

        if (!empty($row['updated_at'])) {
            $row['synced_at_human'] = self::humanTimeDiff($row['updated_at']);
        }

        return $row;
    }

    private static function humanTimeDiff(string $datetime): string
    {
        $ts = strtotime($datetime) ?: time();
        $diff = max(0, time() - $ts);
        if ($diff < 60) return $diff . ' s avval';
        if ($diff < 3600) return (int) ($diff / 60) . ' daq avval';
        if ($diff < 86400) return (int) ($diff / 3600) . ' soat avval';
        return (int) ($diff / 86400) . ' kun avval';
    }
}
