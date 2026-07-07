<?php
declare(strict_types=1);

namespace MarketBot\Admin;

use MarketBot\Core\Database;
use MarketBot\Core\Logger;
use MarketBot\Telegram\TelegramAPI;
use PDO;

final class BroadcastService
{
    public function __construct(
        private TelegramAPI $api,
        private string $uploadsDir,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO broadcasts (admin_id, type, text, media_path, parse_mode, status, created_at)
             VALUES (:a, :t, :tx, :m, :pm, :st, :now)'
        )->execute([
            'a'   => $data['admin_id'] ?? null,
            't'   => $data['type']   ?? 'text',
            'tx'  => $data['text']   ?? null,
            'm'   => $data['media_path'] ?? null,
            'pm'  => $data['parse_mode'] ?? null,
            'st'  => 'pending',
            'now' => date('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Run a broadcast in-process. Designed for both the admin "Send now" action
     * and the CLI script. The optional progress callback receives (sent, failed, total).
     *
     * @return array{ok:bool, sent:int, failed:int, total:int}
     */
    public function run(int $broadcastId, ?callable $progress = null): array
    {
        $pdo = Database::pdo();
        $bc = $pdo->prepare('SELECT * FROM broadcasts WHERE id = :id');
        $bc->execute(['id' => $broadcastId]);
        $broadcast = $bc->fetch(PDO::FETCH_ASSOC);
        if (!$broadcast) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'total' => 0];
        }

        // Snapshot recipients (active users) once
        $pdo->prepare(
            "INSERT " . (Database::isSqlite() ? 'OR IGNORE ' : 'IGNORE ') .
            "INTO broadcast_recipients (broadcast_id, user_id, status)
             SELECT :bid, id, 'pending' FROM users WHERE is_blocked = 0"
        )->execute(['bid' => $broadcastId]);

        $totalStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM broadcast_recipients WHERE broadcast_id = :bid');
        $totalStmt->execute(['bid' => $broadcastId]);
        $total = (int) ($totalStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $pdo->prepare('UPDATE broadcasts SET status = :st, total_count = :t, started_at = :now WHERE id = :id')
            ->execute(['st' => 'running', 't' => $total, 'now' => date('Y-m-d H:i:s'), 'id' => $broadcastId]);

        $sent = 0;
        $failed = 0;

        $loop = $pdo->prepare(
            "SELECT br.id AS recipient_id, u.tg_id, u.id AS user_id
               FROM broadcast_recipients br
               JOIN users u ON u.id = br.user_id
              WHERE br.broadcast_id = :bid AND br.status = 'pending'
              LIMIT 1000"
        );

        $type = (string) $broadcast['type'];
        $text = (string) ($broadcast['text'] ?? '');
        $media = (string) ($broadcast['media_path'] ?? '');
        $parseMode = (string) ($broadcast['parse_mode'] ?? '');
        $extra = $parseMode !== '' ? ['parse_mode' => $parseMode] : [];

        while (true) {
            $loop->execute(['bid' => $broadcastId]);
            $rows = $loop->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $tgId = (int) $r['tg_id'];
                $resp = match ($type) {
                    'photo' => $this->api->sendPhoto($tgId, $media, $text, $extra),
                    'video' => $this->api->sendVideo($tgId, $media, $text, $extra),
                    default => $this->api->sendMessage($tgId, $text, $extra),
                };

                if (!empty($resp['ok'])) {
                    $sent++;
                    $pdo->prepare("UPDATE broadcast_recipients SET status='sent', sent_at=:now WHERE id=:id")
                        ->execute(['now' => date('Y-m-d H:i:s'), 'id' => (int) $r['recipient_id']]);
                } else {
                    $failed++;
                    $err = substr((string) ($resp['description'] ?? $resp['error'] ?? ''), 0, 240);
                    $pdo->prepare("UPDATE broadcast_recipients SET status='failed', error=:e, sent_at=:now WHERE id=:id")
                        ->execute(['e' => $err, 'now' => date('Y-m-d H:i:s'), 'id' => (int) $r['recipient_id']]);
                    if (str_contains(strtolower($err), 'forbidden') || str_contains($err, 'blocked')) {
                        $pdo->prepare('UPDATE users SET is_blocked = 1 WHERE id = :id')
                            ->execute(['id' => (int) $r['user_id']]);
                    }
                }

                $pdo->prepare(
                    'UPDATE broadcasts SET sent_count = :s, failed_count = :f WHERE id = :id'
                )->execute(['s' => $sent, 'f' => $failed, 'id' => $broadcastId]);

                if ($progress) {
                    $progress($sent, $failed, $total);
                }

                // Telegram rate limit: ~30 msg/sec; we go a bit under.
                usleep(40000);
            }
        }

        $pdo->prepare(
            "UPDATE broadcasts SET status = 'done', sent_count = :s, failed_count = :f, finished_at = :now WHERE id = :id"
        )->execute(['s' => $sent, 'f' => $failed, 'now' => date('Y-m-d H:i:s'), 'id' => $broadcastId]);

        Logger::info('broadcast', "broadcast $broadcastId done", ['sent' => $sent, 'failed' => $failed, 'total' => $total]);

        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'total' => $total];
    }

    private const ALLOWED_UPLOADS = [
        // ext => allowed MIME prefixes
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'mp4'  => ['video/mp4'],
        'mov'  => ['video/quicktime'],
    ];

    /** @param array<string,mixed> $file from $_FILES */
    public function storeUpload(array $file): ?string
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_UPLOADS[$ext])) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_UPLOADS[$ext], true)) {
            return null;
        }

        if (!is_dir($this->uploadsDir)) {
            @mkdir($this->uploadsDir, 0775, true);
        }
        $name = sprintf('%s-%s.%s', date('Ymd-His'), bin2hex(random_bytes(6)), $ext);
        $dest = $this->uploadsDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return null;
        }
        return $dest;
    }
}
