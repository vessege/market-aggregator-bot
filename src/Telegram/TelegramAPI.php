<?php
declare(strict_types=1);

namespace MarketBot\Telegram;

use CURLFile;
use MarketBot\Core\Logger;
use RuntimeException;

final class TelegramAPI
{
    private const BASE = 'https://api.telegram.org/bot';

    public function __construct(private string $token)
    {
        if ($token === '') {
            throw new RuntimeException('Telegram bot token not configured');
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function call(string $method, array $params = [], bool $multipart = false): array
    {
        $url = self::BASE . $this->token . '/' . $method;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);

        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        } else {
            $body = json_encode($this->normalize($params), JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            Logger::error('telegram', "API call failed: $method", ['error' => $err]);
            return ['ok' => false, 'error' => $err, 'http_code' => $code];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            Logger::error('telegram', "Invalid JSON from $method", ['body' => substr((string) $response, 0, 500)]);
            return ['ok' => false, 'error' => 'invalid json', 'http_code' => $code];
        }
        if (empty($decoded['ok'])) {
            Logger::error('telegram', "API returned !ok for $method", $decoded);
        }
        return $decoded;
    }

    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text'    => $text,
        ], $extra));
    }

    public function sendPhoto(int|string $chatId, string $photoPath, string $caption = '', array $extra = []): array
    {
        if (!is_file($photoPath)) {
            return ['ok' => false, 'error' => 'photo file not found'];
        }
        $params = array_merge([
            'chat_id' => $chatId,
            'photo'   => new CURLFile($photoPath),
            'caption' => $caption,
        ], $extra);
        return $this->call('sendPhoto', $params, true);
    }

    public function sendVideo(int|string $chatId, string $videoPath, string $caption = '', array $extra = []): array
    {
        if (!is_file($videoPath)) {
            return ['ok' => false, 'error' => 'video file not found'];
        }
        $params = array_merge([
            'chat_id'             => $chatId,
            'video'               => new CURLFile($videoPath),
            'caption'             => $caption,
            'supports_streaming'  => true,
        ], $extra);
        return $this->call('sendVideo', $params, true);
    }

    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function setWebhook(string $url, string $secretToken = '', array $extra = []): array
    {
        return $this->call('setWebhook', array_merge([
            'url'             => $url,
            'secret_token'    => $secretToken,
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ], $extra));
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /** @param array<string,mixed> $params */
    private function normalize(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            }
        }
        return $params;
    }
}
