<?php
declare(strict_types=1);

namespace MarketBot\Telegram;

use MarketBot\Core\Logger;

final class Webhook
{
    public function __construct(
        private TelegramAPI $api,
        private UserRepository $users,
        private SubscriptionGate $gate,
        private string $webAppUrl,
    ) {}

    /** @param array<string,mixed> $update */
    public function handle(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $this->onMessage($update['message']);
                return;
            }
            if (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
                return;
            }
        } catch (\Throwable $e) {
            Logger::error('webhook', 'unhandled exception', ['msg' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $message */
    private function onMessage(array $message): void
    {
        $tgUser = $message['from'] ?? null;
        if (!is_array($tgUser)) {
            return;
        }
        $this->users->upsert($tgUser);

        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === null) {
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->showMain((int) $chatId, (int) $tgUser['id'], (string) ($tgUser['first_name'] ?? ''));
            return;
        }
        if ($text === '/help') {
            $this->api->sendMessage($chatId, "🛒 *Marketplace Aggregator Bot*\n\nBot O'zbekiston, Rossiya va global onlayn do'konlardagi mahsulotlarni jamlaydi va eng yaxshi takliflarni ko'rsatadi.\n\n/start — bosh menyu", ['parse_mode' => 'Markdown']);
            return;
        }

        // Default: show main
        $this->showMain((int) $chatId, (int) $tgUser['id'], (string) ($tgUser['first_name'] ?? ''));
    }

    /** @param array<string,mixed> $cb */
    private function onCallback(array $cb): void
    {
        $data = (string) ($cb['data'] ?? '');
        $tgUser = $cb['from'] ?? [];
        $chatId = $cb['message']['chat']['id'] ?? null;
        $userId = (int) ($tgUser['id'] ?? 0);
        if ($chatId === null || $userId === 0) {
            return;
        }

        if ($data === 'check_subscription') {
            $this->api->call('answerCallbackQuery', ['callback_query_id' => $cb['id'] ?? '']);
            $this->showMain((int) $chatId, $userId, (string) ($tgUser['first_name'] ?? ''));
            return;
        }

        $this->api->call('answerCallbackQuery', ['callback_query_id' => $cb['id'] ?? '']);
    }

    private function showMain(int $chatId, int $userId, string $firstName): void
    {
        $check = $this->gate->check($userId);
        if (!$check['ok']) {
            $this->api->sendMessage(
                $chatId,
                "Salom" . ($firstName ? ", $firstName" : "") . "! 👋\n\nBotdan foydalanish uchun quyidagi kanallarga obuna bo'ling:",
                ['reply_markup' => $this->gate->missingKeyboard($check['missing'])]
            );
            return;
        }

        $welcome = "🛒 *Marketplace Aggregator*\n\n" .
            ($firstName ? "Salom, *" . $this->escape($firstName) . "*! " : '') .
            "Eng yaxshi takliflarni topish uchun do'konni oching:";

        $this->api->sendMessage($chatId, $welcome, [
            'parse_mode'   => 'Markdown',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text'    => '🛍 Do\'konni ochish',
                        'web_app' => ['url' => $this->webAppUrl],
                    ]],
                ],
            ],
        ]);
    }

    private function escape(string $s): string
    {
        return str_replace(['_', '*', '[', ']', '`'], ['\_', '\*', '\[', '\]', '\`'], $s);
    }
}
