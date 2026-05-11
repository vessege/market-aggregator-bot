<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Admin\DynamicSourceRepository;

/**
 * Single place that builds a fully-loaded ParserManager — built-in parsers
 * plus any DynamicJsonParser instances configured by admins via the
 * `dynamic_sources` table.
 *
 * Used by api/index.php (live search) and bin/run-parser.php (cron).
 */
final class ParserRegistry
{
    /** @param array<string,mixed> $config Output of Bootstrap::init() */
    public static function build(array $config): ParserManager
    {
        $http = new HttpClient(
            userAgent: (string) ($config['parser']['user_agent'] ?? 'Mozilla/5.0'),
            timeout:   (int)    ($config['parser']['timeout']    ?? 20),
            delayMs:   (int)    ($config['parser']['delay_ms']   ?? 0),
            retries:   (int)    ($config['parser']['retries']    ?? 2),
            backoffMs: (int)    ($config['parser']['backoff_ms'] ?? 400),
        );

        $manager = new ParserManager($http);

        // Built-in parsers (only the ones with a real implementation).
        // OLX, Ozon, AliExpress and YandexMarket parsers are stubs and would
        // just return empty results — admins can re-add them as DynamicJsonParser
        // entries with the proper API endpoint, or implement them later.
        $manager->register(new UzumParser($http));
        $manager->register(new WildberriesParser($http));

        // Dynamic parsers (admin-configured)
        try {
            $repo = new DynamicSourceRepository();
            foreach ($repo->active() as $row) {
                $manager->register(new DynamicJsonParser($http, $row));
            }
        } catch (\Throwable $e) {
            // dynamic_sources table may not exist yet on legacy installs.
        }

        return $manager;
    }
}
