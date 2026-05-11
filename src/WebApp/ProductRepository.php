<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

use MarketBot\Core\CurrencyConverter;
use MarketBot\Core\Database;
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

        if (!empty($filter['q'])) {
            // Escape LIKE wildcards so user-supplied %/_ don't behave as wildcards.
            $needle = '%' . addcslashes((string) $filter['q'], '\%_') . '%';
            $where[] = "(p.title LIKE :q ESCAPE '\\' OR p.description LIKE :q ESCAPE '\\')";
            $params['q'] = $needle;
        }
        if (!empty($filter['category'])) {
            $where[] = 'c.slug = :cat';
            $params['cat'] = $filter['category'];
        }
        if (!empty($filter['source'])) {
            $where[] = 'p.source = :src';
            $params['src'] = $filter['source'];
        }
        if (!empty($filter['min_price'])) {
            $where[] = 'p.price >= :minp';
            $params['minp'] = (float) $filter['min_price'];
        }
        if (!empty($filter['max_price'])) {
            $where[] = 'p.price <= :maxp';
            $params['maxp'] = (float) $filter['max_price'];
        }

        $sort = $filter['sort'] ?? 'popular';
        $orderBy = match ($sort) {
            'price_asc'  => 'p.price ASC',
            'price_desc' => 'p.price DESC',
            'rating'     => 'p.rating DESC, p.reviews_count DESC',
            'newest'     => 'p.created_at DESC',
            default      => 'p.sold_count DESC, p.rating DESC',
        };

        $limit  = max(1, min(100, (int) ($filter['limit']  ?? 30)));
        $offset = max(0,            (int) ($filter['offset'] ?? 0));

        $sql = "SELECT p.*, c.slug AS category_slug, c.name AS category_name, c.icon AS category_icon
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY $orderBy
                LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
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
        // Single GROUP BY query — avoids N+1 lookups when there are many
        // categories. LEFT JOIN keeps empty categories visible with count=0.
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
            $r['product_count'] = (int) ($r['product_count'] ?? 0);
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        $row['id']            = (int) $row['id'];
        $row['price']         = (float) $row['price'];
        $row['old_price']     = $row['old_price'] !== null ? (float) $row['old_price'] : null;
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

        // Convert non-UZS prices to UZS on the way out, so the frontend
        // never has to know about FX rates. Original price + currency are
        // kept in price_original / currency_original.
        return CurrencyConverter::decorateRow($row);
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
