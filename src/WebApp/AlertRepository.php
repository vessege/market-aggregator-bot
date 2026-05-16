<?php
declare(strict_types=1);

namespace MarketBot\WebApp;

use MarketBot\Core\Database;
use PDO;

final class AlertRepository
{
    /**
     * Create a price alert. Returns the alert id, or throws InvalidArgumentException.
     * Idempotent on (email, product_id, target_price) — re-submitting refreshes
     * the existing row's status to active instead of stacking duplicates.
     */
    public function create(int $productId, string $email, float $targetPrice, string $currency = 'UZS'): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('invalid_email');
        }
        if ($productId <= 0 || $targetPrice <= 0) {
            throw new \InvalidArgumentException('invalid_input');
        }
        $pdo = Database::pdo();

        // Check we aren't piling duplicates.
        $existing = $pdo->prepare(
            'SELECT id FROM price_alerts
              WHERE product_id = :pid AND email = :em AND target_price = :tp
              LIMIT 1'
        );
        $existing->execute(['pid' => $productId, 'em' => $email, 'tp' => $targetPrice]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare("UPDATE price_alerts
                              SET status = 'active', notified_at = NULL
                            WHERE id = :id")->execute(['id' => $row['id']]);
            return (int) $row['id'];
        }

        $pdo->prepare(
            'INSERT INTO price_alerts (product_id, email, target_price, currency, status, created_at)
             VALUES (:pid, :em, :tp, :cur, :st, :now)'
        )->execute([
            'pid' => $productId,
            'em'  => $email,
            'tp'  => $targetPrice,
            'cur' => strtoupper($currency) ?: 'UZS',
            'st'  => 'active',
            'now' => date('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Return alerts ready to be fired (current price ≤ target). Used by the
     * notification cron job. Returns rows joined with current product price.
     *
     * @return array<int,array<string,mixed>>
     */
    public function readyToFire(): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT a.id, a.product_id, a.email, a.target_price, a.currency,
                    p.title, p.external_url, p.price, p.currency AS product_currency
               FROM price_alerts a
               JOIN products p ON p.id = a.product_id
              WHERE a.status = 'active'
                AND p.price > 0
                AND p.price <= a.target_price"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markNotified(int $alertId): void
    {
        Database::pdo()->prepare(
            "UPDATE price_alerts SET status = 'notified', notified_at = :now WHERE id = :id"
        )->execute(['now' => date('Y-m-d H:i:s'), 'id' => $alertId]);
    }

    /** Admin listing. @return array<int,array<string,mixed>> */
    public function listAll(int $limit = 200): array
    {
        $limit = max(1, min(2000, $limit));
        $sql = "SELECT a.*, p.title AS product_title
                  FROM price_alerts a
                  LEFT JOIN products p ON p.id = a.product_id
                 ORDER BY a.created_at DESC
                 LIMIT $limit";
        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function cancel(int $id): void
    {
        Database::pdo()->prepare("UPDATE price_alerts SET status = 'cancelled' WHERE id = :id")
            ->execute(['id' => $id]);
    }
}
