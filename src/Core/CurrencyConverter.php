<?php
declare(strict_types=1);

namespace MarketBot\Core;

/**
 * Lightweight currency converter.
 *
 *   - Rates are configured in .env: FX_RUB_TO_UZS=160, FX_USD_TO_UZS=12700, ...
 *   - Anything missing falls back to FX rates read from the `settings` table
 *     (so admins can update them without an .env redeploy).
 *   - convert() and convertRow() never throw — they just return the original
 *     price/currency when no rate is available.
 *
 * We deliberately do NOT hit an HTTP FX API at request time. If you need fresh
 * rates, fetch them in a cron job and write to the settings table.
 */
final class CurrencyConverter
{
    /** Target currency the site displays prices in. */
    public const TARGET = 'UZS';

    /** @var array<string,float>|null cached rate map {FROM => factor to UZS} */
    private static ?array $rates = null;

    /** @return array<string,float> map of currency code (uppercase) → multiplier to UZS */
    public static function rates(): array
    {
        if (self::$rates !== null) {
            return self::$rates;
        }
        $rates = ['UZS' => 1.0];
        foreach (['RUB', 'USD', 'EUR', 'KZT'] as $cur) {
            $env = Env::get('FX_' . $cur . '_TO_UZS', '');
            if ($env !== '' && is_numeric($env) && (float) $env > 0) {
                $rates[$cur] = (float) $env;
            }
        }
        // settings table overrides .env if present. `key` is a reserved word
        // in MySQL so we quote it differently per driver.
        try {
            $col = Database::isSqlite() ? '"key"' : '`key`';
            $val = Database::isSqlite() ? '"value"' : '`value`';
            $stmt = Database::pdo()->query(
                "SELECT $col AS k, $val AS v FROM settings WHERE $col LIKE 'fx_%_to_uzs'"
            );
            if ($stmt !== false) {
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (preg_match('/^fx_([a-z]{3})_to_uzs$/i', (string) $row['k'], $m)
                        && is_numeric($row['v']) && (float) $row['v'] > 0) {
                        $rates[strtoupper($m[1])] = (float) $row['v'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // settings table may not exist yet on legacy installs — ignore.
        }
        self::$rates = $rates;
        return $rates;
    }

    /** Reset cached rates (used in tests). */
    public static function reset(): void
    {
        self::$rates = null;
    }

    /**
     * Convert a price into UZS. Returns null if no rate is known for $from
     * (caller can then keep the original price + currency).
     */
    public static function toUzs(float $price, string $from): ?float
    {
        $from = strtoupper(trim($from));
        if ($from === '' || $from === self::TARGET) {
            return $price;
        }
        $rates = self::rates();
        if (!isset($rates[$from])) {
            return null;
        }
        return round($price * $rates[$from], 2);
    }

    /**
     * Decorate a DB product row with UZS-converted price fields. Adds:
     *   - price_uzs / old_price_uzs (null if conversion not possible)
     *   - price_original / currency_original (the source values)
     *   - currency stays at 'UZS' when conversion succeeded, otherwise the
     *     original is preserved so the frontend never shows wrong amounts.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decorateRow(array $row): array
    {
        $cur = strtoupper((string) ($row['currency'] ?? self::TARGET));
        $price = isset($row['price']) ? (float) $row['price'] : 0.0;
        $row['price_original']    = $price;
        $row['currency_original'] = $cur;
        if ($cur === self::TARGET) {
            $row['price_uzs'] = $price;
            $row['fx_applied'] = false;
            if (isset($row['old_price']) && $row['old_price'] !== null) {
                $row['old_price_uzs'] = (float) $row['old_price'];
            }
            return $row;
        }
        $uzs = self::toUzs($price, $cur);
        if ($uzs === null) {
            $row['price_uzs']  = null;
            $row['fx_applied'] = false;
            return $row;
        }
        $row['price']      = $uzs;
        $row['price_uzs']  = $uzs;
        $row['currency']   = self::TARGET;
        $row['fx_applied'] = true;
        if (isset($row['old_price']) && $row['old_price'] !== null) {
            $old = self::toUzs((float) $row['old_price'], $cur);
            if ($old !== null) {
                $row['old_price']     = $old;
                $row['old_price_uzs'] = $old;
            }
        }
        return $row;
    }
}
