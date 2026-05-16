<?php
declare(strict_types=1);

namespace MarketBot\Admin;

use MarketBot\Core\Database;
use PDO;

/**
 * CRUD repository for the `dynamic_sources` table.
 *
 * Each row defines a "user-added" marketplace: slug, display name, search
 * URL/method/headers/body, and JSON-path mappings used by DynamicJsonParser
 * to extract products from the response.
 */
final class DynamicSourceRepository
{
    /** @var string[] */
    private const FIELDS = [
        'slug', 'display_name', 'base_url', 'search_url', 'http_method',
        'headers_json', 'body_template', 'items_path',
        'field_id', 'field_title', 'field_price', 'field_old_price',
        'field_currency', 'field_image', 'field_url',
        'field_rating', 'field_reviews', 'field_sold',
        'external_url_tpl', 'is_active',
    ];

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT * FROM dynamic_sources ORDER BY display_name ASC'
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function active(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT * FROM dynamic_sources WHERE is_active = 1 ORDER BY display_name ASC'
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dynamic_sources WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dynamic_sources WHERE slug = :s LIMIT 1');
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $row = $this->normalize($data);
        $cols = array_keys($row);
        $sql = 'INSERT INTO dynamic_sources (' . implode(',', $cols) . ')'
             . ' VALUES (:' . implode(', :', $cols) . ')';
        $pdo = Database::pdo();
        $pdo->prepare($sql)->execute($row);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $row = $this->normalize($data);
        $sets = [];
        foreach (array_keys($row) as $col) {
            $sets[] = "$col = :$col";
        }
        $sql = 'UPDATE dynamic_sources SET ' . implode(', ', $sets)
             . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $params = $row;
        $params['id'] = $id;
        Database::pdo()->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM dynamic_sources WHERE id = :id')->execute(['id' => $id]);
    }

    public function toggleActive(int $id): void
    {
        Database::pdo()->prepare(
            'UPDATE dynamic_sources SET is_active = 1 - is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute(['id' => $id]);
    }

    /**
     * Normalize input — keep only known fields, coerce types.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $data)) {
                continue;
            }
            $v = $data[$f];
            if ($f === 'is_active') {
                $out[$f] = (int) ((bool) $v);
            } elseif ($f === 'http_method') {
                $m = strtoupper((string) $v);
                $out[$f] = in_array($m, ['GET', 'POST'], true) ? $m : 'GET';
            } else {
                $out[$f] = $v === '' || $v === null ? null : (string) $v;
            }
        }
        if (!isset($out['http_method'])) {
            $out['http_method'] = 'GET';
        }
        if (!isset($out['is_active'])) {
            $out['is_active'] = 1;
        }
        if (empty($out['slug'])) {
            $out['slug'] = 'src_' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        if (empty($out['display_name'])) {
            $out['display_name'] = $out['slug'];
        }
        if (empty($out['search_url'])) {
            $out['search_url'] = '';
        }
        return $out;
    }
}
