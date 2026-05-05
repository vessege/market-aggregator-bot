<?php
declare(strict_types=1);

namespace MarketBot\Telegram;

use MarketBot\Core\Database;

final class SubscriptionGate
{
    public function __construct(private TelegramAPI $api) {}

    /**
     * Check if user is subscribed to all active mandatory channels.
     *
     * @return array{ok:bool, missing:array<int,array{chat_id:string,title:?string,invite_link:?string}>}
     */
    public function check(int $userId): array
    {
        $pdo = Database::pdo();
        $rows = $pdo->query(
            'SELECT chat_id, title, invite_link FROM subscription_channels WHERE is_active = 1 ORDER BY position, id'
        )->fetchAll();

        $missing = [];
        foreach ($rows as $r) {
            $resp = $this->api->getChatMember($r['chat_id'], $userId);
            $status = $resp['result']['status'] ?? null;
            if (!in_array($status, ['member', 'administrator', 'creator', 'restricted'], true)) {
                $missing[] = [
                    'chat_id'     => (string) $r['chat_id'],
                    'title'       => $r['title'] !== null ? (string) $r['title'] : null,
                    'invite_link' => $r['invite_link'] !== null ? (string) $r['invite_link'] : null,
                ];
            }
        }

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /** @param array<int,array{chat_id:string,title:?string,invite_link:?string}> $missing */
    public function missingKeyboard(array $missing): array
    {
        $rows = [];
        foreach ($missing as $m) {
            $url = $m['invite_link'] ?: ('https://t.me/' . ltrim((string) $m['chat_id'], '@'));
            $rows[] = [[
                'text' => '➕ ' . ($m['title'] ?: 'Kanalga obuna bo\'ling'),
                'url'  => $url,
            ]];
        }
        $rows[] = [[
            'text'          => '✅ Tekshirish',
            'callback_data' => 'check_subscription',
        ]];
        return ['inline_keyboard' => $rows];
    }
}
