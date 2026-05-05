<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

/**
 * Placeholder Yandex Market parser.
 *
 * Yandex Market exposes a Partner API but it requires OAuth and a registered
 * partner account. Public listing pages are protected by Yandex SmartCaptcha.
 */
final class YandexMarketParser extends BaseParser
{
    public function source(): string
    {
        return 'yandex_market';
    }

    public function displayName(): string
    {
        return 'Yandex Market';
    }

    public function fetchFeed(array $options = []): iterable
    {
        return [];
    }
}
