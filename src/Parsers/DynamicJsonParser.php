<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Core\Logger;

/**
 * Generic JSON-API parser configured at runtime from the dynamic_sources DB
 * table. Admins add new marketplaces through the admin panel; each one becomes
 * a DynamicJsonParser instance registered into ParserManager alongside the
 * built-in parsers (Uzum, Wildberries, ...).
 *
 * Supports:
 *  - GET / POST search requests
 *  - Custom headers (e.g. Authorization, Accept-Language)
 *  - Templated URL/body with {query} and {limit} placeholders
 *  - Dot-path extraction for the items array (e.g. data.products) and each
 *    product field (id, title, price, ...).
 */
final class DynamicJsonParser extends BaseParser
{
    /** @param array<string,mixed> $config Row from dynamic_sources */
    public function __construct(HttpClient $http, private array $config)
    {
        parent::__construct($http);
    }

    public function source(): string
    {
        return (string) ($this->config['slug'] ?? 'dynamic');
    }

    public function displayName(): string
    {
        return (string) ($this->config['display_name'] ?? $this->source());
    }

    /**
     * @param array<string,mixed> $options
     * @return iterable<ParsedProduct>
     */
    public function fetchFeed(array $options = []): iterable
    {
        $query = (string) ($options['query'] ?? '');
        if ($query === '') {
            return;
        }
        yield from $this->searchQuery($query, $options);
    }

    /**
     * @param array<string,mixed> $options
     * @return iterable<ParsedProduct>
     */
    public function searchQuery(string $query, array $options = []): iterable
    {
        $limit = max(1, (int) ($options['limit'] ?? 20));
        $vars = ['query' => $query, 'limit' => (string) $limit];

        $url = $this->renderTemplate((string) ($this->config['search_url'] ?? ''), $vars);
        if ($url === '') {
            return;
        }

        $headers = [];
        $rawHeaders = $this->config['headers_json'] ?? null;
        if (is_string($rawHeaders) && $rawHeaders !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $headers[(string) $k] = $this->renderTemplate((string) $v, $vars);
                }
            }
        }

        $method = strtoupper((string) ($this->config['http_method'] ?? 'GET'));
        if ($method === 'POST') {
            $bodyTpl = (string) ($this->config['body_template'] ?? '');
            $body = $bodyTpl !== '' ? $this->renderTemplate($bodyTpl, $vars) : '';
            $resp = $this->http->postJson($url, $body !== '' ? $body : '{}', $headers);
        } else {
            $resp = $this->http->get($url, $headers);
        }

        if (!$resp['ok']) {
            Logger::error('parser-dynamic', 'request failed', [
                'source' => $this->source(),
                'status' => $resp['status'],
            ]);
            return;
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            return;
        }

        $items = $this->extract($json, (string) ($this->config['items_path'] ?? ''));
        if (!is_array($items)) {
            return;
        }

        $count = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product = $this->mapItem($item);
            if ($product !== null) {
                yield $product;
                if (++$count >= $limit) {
                    return;
                }
            }
        }
    }

    /** @param array<string,mixed> $item */
    private function mapItem(array $item): ?ParsedProduct
    {
        $id    = $this->getString($item, (string) ($this->config['field_id']    ?? ''));
        $title = $this->getString($item, (string) ($this->config['field_title'] ?? ''));
        if ($id === '' || $title === '') {
            return null;
        }

        $price    = $this->toFloat($this->getString($item, (string) ($this->config['field_price']     ?? '')));
        $oldRaw   = $this->getString($item, (string) ($this->config['field_old_price']         ?? ''));
        $oldPrice = $oldRaw !== '' ? $this->toFloat($oldRaw) : null;
        $currency = $this->getString($item, (string) ($this->config['field_currency']         ?? '')) ?: 'UZS';
        $image    = $this->getString($item, (string) ($this->config['field_image']            ?? ''));
        $url      = $this->getString($item, (string) ($this->config['field_url']              ?? ''));
        $rating   = $this->getString($item, (string) ($this->config['field_rating']           ?? ''));
        $reviews  = (int) $this->getString($item, (string) ($this->config['field_reviews']     ?? ''));
        $sold     = (int) $this->getString($item, (string) ($this->config['field_sold']        ?? ''));

        $tpl = (string) ($this->config['external_url_tpl'] ?? '');
        if ($url === '' && $tpl !== '') {
            $url = $this->renderTemplate($tpl, ['id' => $id]);
        }

        return new ParsedProduct(
            source:       $this->source(),
            externalId:   $id,
            title:        $title,
            price:        $price,
            currency:     $currency !== '' ? $currency : 'UZS',
            oldPrice:     $oldPrice !== null && $oldPrice > $price ? $oldPrice : null,
            description:  null,
            imageUrl:     $image !== '' ? $image : null,
            images:       $image !== '' ? [$image] : [],
            externalUrl:  $url !== '' ? $url : null,
            categorySlug: null,
            rating:       $rating !== '' ? (float) $rating : null,
            reviewsCount: $reviews,
            soldCount:    $sold,
            seller:       null,
            sellerRating: null,
        );
    }

    /**
     * Resolve a dot-path against $data: "data.items.0.title" or "payload.results".
     * Returns null if any segment is missing.
     *
     * @param mixed $data
     */
    private function extract(mixed $data, string $path): mixed
    {
        if ($path === '') {
            return $data;
        }
        $parts = explode('.', $path);
        $cur = $data;
        foreach ($parts as $p) {
            if (is_array($cur)) {
                if (array_key_exists($p, $cur)) {
                    $cur = $cur[$p];
                    continue;
                }
                if (ctype_digit($p) && array_key_exists((int) $p, $cur)) {
                    $cur = $cur[(int) $p];
                    continue;
                }
            }
            return null;
        }
        return $cur;
    }

    /**
     * Parse a possibly-localised numeric string into a float.
     * Handles "123 456,78 so'm", "1,234.56", "1.234,56", "$199.99" etc.
     */
    private function toFloat(string $raw): float
    {
        $s = trim($raw);
        if ($s === '') {
            return 0.0;
        }
        // Strip currency symbols/letters/spaces, keep digits, comma, dot, minus.
        $s = preg_replace('/[^\d.,\-]/', '', $s) ?? '';
        if ($s === '' || $s === '-' || $s === '.' || $s === ',') {
            return 0.0;
        }
        $hasComma = strpos($s, ',') !== false;
        $hasDot   = strpos($s, '.') !== false;
        if ($hasComma && $hasDot) {
            // The right-most separator is the decimal mark.
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma) {
            // Treat comma as decimal point if it has 1-2 digits after it,
            // otherwise as a thousands separator.
            if (preg_match('/,\d{1,2}$/', $s)) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        }
        return (float) $s;
    }

    /** @param array<string,mixed> $item */
    private function getString(array $item, string $path): string
    {
        if ($path === '') {
            return '';
        }
        $v = $this->extract($item, $path);
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return '';
    }

    /** @param array<string,string> $vars */
    private function renderTemplate(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', rawurlencode((string) $v), $out);
            $out = str_replace('{!' . $k . '}', (string) $v, $out); // unescaped
        }
        return $out;
    }
}
