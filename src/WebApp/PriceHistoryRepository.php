<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

use MarketBot\Core\CurrencyConverter;
use MarketBot\Core\Database;
use PDO;

final class PriceHistoryRepository
{
    /**
     * Return up to $limit most-recent price snapshots for a product,
     * normalized to UZS so the sparkline draws sensible values when the
     * underlying source quotes RUB or USD.
     *
     * @return array<int,array{captured_at:string, price:float, currency:string, price_uzs:?float}>
     */
    public function recent(int $productId, int $limit = 90): array
    {
        $limit = max(1, min(365, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT captured_at, price, currency
               FROM price_history
              WHERE product_id = :pid
              ORDER BY captured_at DESC
              LIMIT ' . $limit
        );
        $stmt->execute(['pid' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Reverse so the frontend gets oldest -> newest (left to right).
        $rows = array_reverse($rows);
        foreach ($rows as &$r) {
            $r['price'] = (float) $r['price'];
            $uzs = CurrencyConverter::toUzs($r['price'], (string) $r['currency']);
            $r['price_uzs'] = $uzs;
        }
        return $rows;
    }

    /**
     * Returns [min_price_uzs, max_price_uzs, low_count] for the rolling window.
     * Used to render the "tarixiy minimum" / "lowest ever" badge.
     *
     * @return array{min:?float,max:?float,n:int}
     */
    public function stats(int $productId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT price, currency FROM price_history WHERE product_id = :pid'
        );
        $stmt->execute(['pid' => $productId]);
        $min = null;
        $max = null;
        $n   = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u = CurrencyConverter::toUzs((float) $r['price'], (string) $r['currency']);
            if ($u === null) continue;
            $min = $min === null ? $u : min($min, $u);
            $max = $max === null ? $u : max($max, $u);
            $n++;
        }
        return ['min' => $min, 'max' => $max, 'n' => $n];
    }
}
