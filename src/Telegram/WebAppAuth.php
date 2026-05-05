<?php
declare(strict_types=1);

namespace MarketBot\Telegram;

/**
 * Validate Telegram WebApp initData per
 * https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
 */
final class WebAppAuth
{
    public function __construct(private string $botToken) {}

    /**
     * @return array<string,mixed>|null returns parsed user array on success, null on failure
     */
    public function verify(string $initData, int $maxAgeSeconds = 86400): ?array
    {
        if ($initData === '' || $this->botToken === '') {
            return null;
        }
        parse_str($initData, $data);
        if (!is_array($data) || empty($data['hash'])) {
            return null;
        }
        $providedHash = (string) $data['hash'];
        unset($data['hash']);

        ksort($data);
        $checkPairs = [];
        foreach ($data as $k => $v) {
            $checkPairs[] = $k . '=' . $v;
        }
        $checkString = implode("\n", $checkPairs);

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $checkString, $secretKey);

        if (!hash_equals($calculatedHash, $providedHash)) {
            return null;
        }

        if (!empty($data['auth_date']) && (time() - (int) $data['auth_date']) > $maxAgeSeconds) {
            return null;
        }

        $user = null;
        if (!empty($data['user'])) {
            $user = json_decode((string) $data['user'], true);
            if (!is_array($user)) {
                return null;
            }
        }

        return [
            'user'      => $user,
            'auth_date' => (int) ($data['auth_date'] ?? 0),
            'raw'       => $data,
        ];
    }
}
