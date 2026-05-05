<?php
declare(strict_types=1);

namespace MarketBot\Telegram;

use MarketBot\Core\Database;
use PDO;

final class UserRepository
{
    /** @param array<string,mixed> $tgUser */
    public function upsert(array $tgUser): int
    {
        $pdo = Database::pdo();
        $tgId = (int) ($tgUser['id'] ?? 0);
        if ($tgId === 0) {
            return 0;
        }

        $existing = $pdo->prepare('SELECT id FROM users WHERE tg_id = :tg_id');
        $existing->execute(['tg_id' => $tgId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        $now = date('Y-m-d H:i:s');
        if ($row) {
            $upd = $pdo->prepare(
                'UPDATE users SET username = :u, first_name = :f, last_name = :l, language_code = :lc, last_seen_at = :ls WHERE id = :id'
            );
            $upd->execute([
                'u'  => $tgUser['username']      ?? null,
                'f'  => $tgUser['first_name']    ?? null,
                'l'  => $tgUser['last_name']     ?? null,
                'lc' => $tgUser['language_code'] ?? null,
                'ls' => $now,
                'id' => (int) $row['id'],
            ]);
            return (int) $row['id'];
        }

        $ins = $pdo->prepare(
            'INSERT INTO users (tg_id, username, first_name, last_name, language_code, last_seen_at)
             VALUES (:tg, :u, :f, :l, :lc, :ls)'
        );
        $ins->execute([
            'tg' => $tgId,
            'u'  => $tgUser['username']      ?? null,
            'f'  => $tgUser['first_name']    ?? null,
            'l'  => $tgUser['last_name']     ?? null,
            'lc' => $tgUser['language_code'] ?? null,
            'ls' => $now,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function findByTg(int $tgId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE tg_id = :id');
        $stmt->execute(['id' => $tgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
