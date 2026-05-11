<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Core\Logger;

/**
 * Uzum Market parser using the public unofficial JSON endpoints exposed by
 * https://uzum.uz frontend.
 *
 * Endpoints:
 *  - GET /api/v2/product/{id}            -> full product detail
 *  - GET /api/v2/product/{id}/similar    -> list of similar products (used to expand catalog)
 */
final class UzumParser extends BaseParser
{
    private const BASE = 'https://api.uzum.uz';
    private const PRODUCT_URL_TEMPLATE = 'https://uzum.uz/uz/product/%s';

    public function source(): string
    {
        return 'uzum';
    }

    public function displayName(): string
    {
        return 'Uzum Market';
    }

    /**
     * Crawl seed_ids and their `similar` lists (BFS) up to a configurable cap.
     *
     * Options:
     *  - seed_ids:  int[]  starting product ids (default: a few popular ids)
     *  - max_items: int    cap on yielded products (default 50)
     *  - max_depth: int    BFS depth (default 2)
     *
     * @return iterable<ParsedProduct>
     */
    public function fetchFeed(array $options = []): iterable
    {
        $seedIds  = $options['seed_ids']  ?? [1234, 1235, 1236, 1237, 1238];
        $maxItems = max(1, (int) ($options['max_items'] ?? 50));
        $maxDepth = max(1, (int) ($options['max_depth'] ?? 2));

        $visited = [];
        $queue = [];
        foreach ($seedIds as $id) {
            $queue[] = ['id' => (int) $id, 'depth' => 0];
        }

        $yielded = 0;
        while ($queue && $yielded < $maxItems) {
            $node = array_shift($queue);
            $id = $node['id'];
            $depth = $node['depth'];

            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $product = $this->fetchOne((string) $id);
            if ($product !== null) {
                yield $product;
                $yielded++;
                if ($yielded >= $maxItems) {
                    return;
                }
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            $similarIds = $this->fetchSimilarIds($id);
            foreach ($similarIds as $sid) {
                if (!isset($visited[$sid])) {
                    $queue[] = ['id' => $sid, 'depth' => $depth + 1];
                }
            }
        }
    }

    /**
     * Live full-text search via Uzum's GraphQL endpoint.
     *
     * Requires UZUM_AUTH_TOKEN (and ideally UZUM_X_IID) in .env. The token is
     * a short-lived Bearer/Basic token issued by uzum.uz to its frontend; it
     * rotates periodically. Without a valid token this method returns nothing.
     *
     * Options:
     *  - limit:      int     max products (default 20)
     *  - auth_token: string  override env token
     *  - x_iid:      string  override env x-iid
     *  - auth_type:  string  Bearer | Basic (default Bearer)
     *  - graphql_url: string override endpoint
     *
     * @param array<string,mixed> $options
     * @return iterable<ParsedProduct>
     */
    public function searchQuery(string $query, array $options = []): iterable
    {
        $token   = (string) ($options['auth_token']  ?? getenv('UZUM_AUTH_TOKEN') ?: '');
        $xIid    = (string) ($options['x_iid']       ?? getenv('UZUM_X_IID') ?: '');
        $authTyp = (string) ($options['auth_type']   ?? getenv('UZUM_AUTH_TYPE') ?: 'Bearer');
        $endpoint = (string) ($options['graphql_url'] ?? getenv('UZUM_GRAPHQL_URL') ?: 'https://graphql.uzum.uz/');
        $limit   = max(1, min(100, (int) ($options['limit'] ?? 20)));

        if ($token === '') {
            Logger::error('parser-uzum', 'live search skipped: UZUM_AUTH_TOKEN not configured', []);
            return;
        }

        $body = [
            'operationName' => 'getMakeSearch',
            'variables' => [
                'queryInput' => [
                    'text' => $query,
                    'showAdultContent' => 'TRUE',
                    'filters' => [],
                    'sort' => 'BY_RELEVANCE_DESC',
                    'pagination' => ['offset' => 0, 'limit' => $limit],
                ],
            ],
            'query' => 'query getMakeSearch($queryInput: MakeSearchQueryInput!) {'
                . ' makeSearch(query: $queryInput) {'
                . '   items { catalogCard {'
                . '     id productId title minSellPrice minFullPrice rating'
                . '     ordersQuantity feedbackQuantity'
                . '     photos { link(trans: PRODUCT_540) { high low } }'
                . '   } }'
                . '   total'
                . ' }'
                . '}',
        ];

        $headers = [
            'Authorization'   => $authTyp . ' ' . $token,
            'Accept-Language' => 'uz-UZ',
        ];
        if ($xIid !== '') {
            $headers['X-Iid'] = $xIid;
        }

        $resp = $this->http->postJson($endpoint, $body, $headers);
        if (!$resp['ok']) {
            Logger::error('parser-uzum', 'graphql search failed', ['status' => $resp['status']]);
            return;
        }

        $json = json_decode($resp['body'], true);
        $items = $json['data']['makeSearch']['items'] ?? [];
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $it) {
            $card = $it['catalogCard'] ?? null;
            if (!is_array($card)) {
                continue;
            }
            $product = $this->mapSearchCard($card);
            if ($product !== null) {
                yield $product;
            }
        }
    }

    /** @param array<string,mixed> $card */
    private function mapSearchCard(array $card): ?ParsedProduct
    {
        $id = isset($card['productId']) ? (string) $card['productId'] : (isset($card['id']) ? (string) $card['id'] : '');
        if ($id === '') {
            return null;
        }
        $title = (string) ($card['title'] ?? '');
        if ($title === '') {
            return null;
        }

        $price = isset($card['minSellPrice']) ? (float) $card['minSellPrice'] : 0.0;
        $oldPrice = null;
        if (isset($card['minFullPrice']) && (float) $card['minFullPrice'] > $price) {
            $oldPrice = (float) $card['minFullPrice'];
        }

        $imageUrl = null;
        $images = [];
        $photos = $card['photos'] ?? [];
        if (is_array($photos)) {
            foreach ($photos as $ph) {
                $link = $ph['link']['high'] ?? $ph['link']['low'] ?? null;
                if (is_string($link) && $link !== '') {
                    $images[] = $link;
                    $imageUrl ??= $link;
                }
            }
        }

        return new ParsedProduct(
            source:       'uzum',
            externalId:   $id,
            title:        $title,
            price:        $price,
            currency:     'UZS',
            oldPrice:     $oldPrice,
            description:  null,
            imageUrl:     $imageUrl,
            images:       $images,
            externalUrl:  sprintf(self::PRODUCT_URL_TEMPLATE, $id),
            categorySlug: null,
            rating:       isset($card['rating']) ? (float) $card['rating'] : null,
            reviewsCount: (int) ($card['feedbackQuantity'] ?? 0),
            soldCount:    (int) ($card['ordersQuantity'] ?? 0),
            seller:       null,
            sellerRating: null,
        );
    }

    public function fetchOne(string $externalId): ?ParsedProduct
    {
        $url = self::BASE . '/api/v2/product/' . urlencode($externalId);
        $resp = $this->http->get($url, $this->defaultHeaders());
        if (!$resp['ok']) {
            Logger::error('parser-uzum', 'product fetch failed', ['id' => $externalId, 'status' => $resp['status']]);
            return null;
        }
        $json = json_decode($resp['body'], true);
        $data = $json['payload']['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        return $this->mapProduct($data);
    }

    /** @return int[] */
    private function fetchSimilarIds(int $productId): array
    {
        $url = self::BASE . '/api/v2/product/' . $productId . '/similar';
        $resp = $this->http->get($url, $this->defaultHeaders());
        if (!$resp['ok']) {
            return [];
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            return [];
        }
        $ids = [];
        foreach ($json as $item) {
            if (isset($item['productId'])) {
                $ids[] = (int) $item['productId'];
            }
        }
        return $ids;
    }

    /** @param array<string,mixed> $data */
    private function mapProduct(array $data): ?ParsedProduct
    {
        $id = isset($data['id']) ? (string) $data['id'] : '';
        if ($id === '') {
            return null;
        }

        $title = (string) ($data['localizableTitle']['uz'] ?? $data['title'] ?? '');
        if ($title === '') {
            return null;
        }

        $description = isset($data['description']) ? (string) $data['description'] : null;

        $images = [];
        foreach (($data['photos'] ?? []) as $photo) {
            $hi = $photo['photo']['540']['high']
                ?? $photo['photo']['480']['high']
                ?? $photo['photo']['720']['high']
                ?? null;
            if (is_string($hi) && $hi !== '') {
                $images[] = $hi;
            }
        }

        $price = 0.0;
        $oldPrice = null;
        $skuList = $data['skuList'] ?? [];
        if (is_array($skuList) && !empty($skuList)) {
            $sku = $skuList[0];
            $price    = (float) ($sku['purchasePrice'] ?? $sku['fullPrice'] ?? 0);
            $fullPrice = (float) ($sku['fullPrice'] ?? 0);
            if ($fullPrice > $price) {
                $oldPrice = $fullPrice;
            }
        }

        return new ParsedProduct(
            source:       'uzum',
            externalId:   $id,
            title:        $title,
            price:        $price,
            currency:     'UZS',
            oldPrice:     $oldPrice,
            description:  $description,
            imageUrl:     $images[0] ?? null,
            images:       $images,
            externalUrl:  sprintf(self::PRODUCT_URL_TEMPLATE, $id),
            categorySlug: $this->guessCategorySlug($data),
            rating:       isset($data['rating']) ? (float) $data['rating'] : null,
            reviewsCount: (int) ($data['reviewsAmount'] ?? 0),
            soldCount:    (int) ($data['ordersAmount'] ?? $data['rOrdersAmount'] ?? 0),
            seller:       isset($data['seller']['title']) ? (string) $data['seller']['title'] : null,
            sellerRating: isset($data['seller']['rating']) ? (float) $data['seller']['rating'] : null,
        );
    }

    /** @param array<string,mixed> $data */
    private function guessCategorySlug(array $data): ?string
    {
        $title = strtolower((string) ($data['category']['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $map = [
            'elektron'   => 'elektronika',
            'telefon'    => 'elektronika',
            'kompyuter'  => 'elektronika',
            'kiyim'      => 'kiyim',
            'poyabzal'   => 'kiyim',
            'go\'zall'   => 'go-zallik',
            'kosmetik'   => 'go-zallik',
            'bola'       => 'bolalar',
            "o'yinchoq"  => 'bolalar',
            'sport'      => 'sport',
            'oziq'       => 'oziq-ovqat',
            'kitob'      => 'kitoblar',
            'avto'       => 'avtomobil',
            'uy'         => 'uy-jihozlari',
        ];
        foreach ($map as $needle => $slug) {
            if (str_contains($title, $needle)) {
                return $slug;
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private function defaultHeaders(): array
    {
        return [
            'Accept'          => 'application/json',
            'Accept-Language' => 'uz-UZ,uz;q=0.9,ru;q=0.7,en;q=0.5',
            'X-Iid'           => '7c56d8ee-6c57-4d14-9c81-c89fd734698a',
        ];
    }
}
