<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

/**
 * Placeholder Ozon parser.
 *
 * Ozon's `composer-api.bx/_action/searchSuggestions` and entrypoint API
 * require additional anti-bot fingerprinting (Akamai). A working
 * implementation typically uses a headless browser (Puppeteer / Playwright)
 * to obtain cookies first.
 *
 * This parser intentionally returns nothing until properly implemented;
 * keeping it registered lets the admin panel show the source as
 * "available driver, not yet implemented".
 */
final class OzonParser extends BaseParser
{
    public function source(): string
    {
        return 'ozon';
    }

    public function displayName(): string
    {
        return 'Ozon';
    }

    public function fetchFeed(array $options = []): iterable
    {
        return [];
    }
}
