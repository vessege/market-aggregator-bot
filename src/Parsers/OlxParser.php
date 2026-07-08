<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Core\Logger;

/**
 * OLX.uz parser using the public JSON offers API
 * (GET https://www.olx.uz/api/v1/offers/?query=...&limit=...).
 *
 * Listing pages sit behind Cloudflare, but the JSON API is served to plain
 * HTTP clients. Only query-based fetching is supported — OLX is a classifieds
 * board, so there is no meaningful "popular products" feed.
 */
final class OlxParser extends BaseParser
{
    private const SEARCH = 'https://www.olx.uz/api/v1/offers/';

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
        $query = trim((string) ($options['query'] ?? ''));
        if ($query === '') {
            return; // no query — nothing sensible to crawl on a classifieds board
        }
        $maxItems = max(1, (int) ($options['max_items'] ?? 30));

        $req = $this->searchRequest($query, $maxItems);
        $resp = $this->http->get($req['url'], $req['headers']);
        if (!$resp['ok']) {
            Logger::error('parser-olx', 'search failed', ['status' => $resp['status']]);
            return;
        }
        yield from $this->parseSearchResponse($resp['body'], $maxItems);
    }

    /** @return array{url:string, headers:array<string,string>} */
    public function searchRequest(string $query, int $limit): array
    {
        $url = self::SEARCH . '?' . http_build_query([
            'query'  => $query,
            'limit'  => max(1, min(40, $limit)),
            'offset' => 0,
        ]);
        return [
            'url'     => $url,
            'headers' => [
                'Accept'          => 'application/json',
                'Accept-Language' => 'uz-UZ,uz;q=0.9,ru;q=0.7',
            ],
        ];
    }

    /** @return ParsedProduct[] */
    public function parseSearchResponse(string $body, int $limit): array
    {
        $json = json_decode($body, true);
        $items = is_array($json) ? ($json['data'] ?? []) : [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (count($out) >= $limit) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $product = $this->mapOffer($item);
            if ($product !== null) {
                $out[] = $product;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $offer */
    private function mapOffer(array $offer): ?ParsedProduct
    {
        $id = isset($offer['id']) ? (string) $offer['id'] : '';
        $title = trim((string) ($offer['title'] ?? ''));
        if ($id === '' || $title === '') {
            return null;
        }

        // Price lives in params[key=price].value = {value, currency, ...}.
        // Offers without a numeric price (barter, "kelishiladi") are skipped.
        $price = 0.0;
        $currency = 'UZS';
        foreach (($offer['params'] ?? []) as $param) {
            if (!is_array($param) || ($param['key'] ?? '') !== 'price') {
                continue;
            }
            $value = $param['value'] ?? null;
            if (is_array($value) && isset($value['value']) && is_numeric($value['value'])) {
                $price = (float) $value['value'];
                $currency = strtoupper((string) ($value['currency'] ?? 'UZS'));
            }
            break;
        }
        if ($price <= 0) {
            return null;
        }

        $images = [];
        foreach (($offer['photos'] ?? []) as $photo) {
            $link = is_array($photo) ? ($photo['link'] ?? null) : null;
            if (is_string($link) && $link !== '') {
                // Photo links are templates: ...;s={width}x{height}
                $images[] = str_replace(['{width}', '{height}'], ['800', '800'], $link);
            }
        }

        return new ParsedProduct(
            source:       'olx',
            externalId:   $id,
            title:        $title,
            price:        $price,
            currency:     $currency,
            description:  isset($offer['description']) ? mb_substr((string) $offer['description'], 0, 2000) : null,
            imageUrl:     $images[0] ?? null,
            images:       $images,
            externalUrl:  isset($offer['url']) ? (string) $offer['url'] : null,
            seller:       isset($offer['user']['name']) ? (string) $offer['user']['name'] : null,
        );
    }
}
