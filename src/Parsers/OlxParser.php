<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

/**
 * Placeholder OLX parser.
 *
 * olx.uz uses Cloudflare-protected listing pages. A robust implementation
 * needs cookie-aware HTML scraping or use of OLX's classified RSS feeds.
 *
 * This stub keeps the parser registered so the admin panel can list it as
 * "available driver, not yet implemented".
 */
final class OlxParser extends BaseParser
{
    public function source(): string
    {
        return 'olx';
    }

    public function displayName(): string
    {
        return 'OLX';
    }

    public function fetchFeed(array $options = []): iterable
    {
        return [];
    }
}
