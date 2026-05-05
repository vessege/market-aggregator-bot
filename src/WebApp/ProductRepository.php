<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

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
            $where[] = '(p.title LIKE :q OR p.description LIKE :q)';
            $params['q'] = '%' . $filter['q'] . '%';
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
        $rows = Database::pdo()->query(
            "SELECT id, slug, name, icon, position
               FROM categories
              WHERE is_active = 1
              ORDER BY position, name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $cnt = Database::pdo()->prepare(
                'SELECT COUNT(*) AS c FROM products WHERE category_id = :id AND is_active = 1'
            );
            $cnt->execute(['id' => $r['id']]);
            $r['product_count'] = (int) ($cnt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
            $out[] = $r;
        }
        return $out;
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
