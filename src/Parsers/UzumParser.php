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
