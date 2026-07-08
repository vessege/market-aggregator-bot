<?php
declare(strict_types=1);

namespace MarketBot\Core;

/**
 * Converts marketplace prices into UZS using rates stored in `settings`
 * (keys: rate_rub_uzs, rate_usd_uzs). Rates are seeded with rough defaults
 * and should be refreshed via `php bin/update-rates.php` (CBU API) in cron.
 */
final class Currency
{
    public static function toUzs(float $amount, string $currency): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'UZS') {
            return $amount;
        }
        $rate = match ($currency) {
            'RUB' => Settings::getFloat('rate_rub_uzs'),
            'USD' => Settings::getFloat('rate_usd_uzs'),
            default => 0.0,
        };
        if ($rate <= 0) {
            return null;
        }
        return round($amount * $rate, 2);
    }
}
