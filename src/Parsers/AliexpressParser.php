<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

/**
 * Placeholder AliExpress parser.
 *
 * aliexpress.com / aliexpress.ru uses heavy anti-bot protection. A real
 * implementation typically uses the Affiliate API
 * (https://portals.aliexpress.com/) which requires API credentials.
 */
final class AliexpressParser extends BaseParser
{
    public function source(): string
    {
        return 'aliexpress';
    }

    public function displayName(): string
    {
        return 'AliExpress';
    }

    public function fetchFeed(array $options = []): iterable
    {
        return [];
    }
}
