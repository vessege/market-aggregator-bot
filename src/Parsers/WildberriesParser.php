<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use MarketBot\Core\Logger;

/**
 * Wildberries parser using public unofficial JSON endpoints.
 *
 *  - Search:  https://search.wb.ru/exactmatch/ru/common/v9/search?...&query=...
 *  - Detail:  https://card.wb.ru/cards/v2/detail?...&nm={id}
 *  - Image:   https://basket-XX.wb.ru/vol{id_part}/part{...}/{id}/images/big/1.webp
 *
 * NOTE: Wildberries shards images across "basket" hosts. We compute the host
 * from the article (nm) id using the well-known sharding rules.
 */
final class WildberriesParser extends BaseParser
{
    private const SEARCH = 'https://search.wb.ru/exactmatch/ru/common/v9/search';
    private const DETAIL = 'https://card.wb.ru/cards/v2/detail';

    public function source(): string
    {
        return 'wildberries';
    }

    public function displayName(): string
    {
        return 'Wildberries';
    }

    /**
     * Options:
     *  - query:     string (default "tovar")
     *  - max_items: int    (default 30)
     *  - dest:      int    (geo dest, default -8144334 = Tashkent area)
     *
     * @return iterable<ParsedProduct>
     */
    public function fetchFeed(array $options = []): iterable
    {
        $query    = (string) ($options['query'] ?? 'смартфон');
        $maxItems = max(1, (int) ($options['max_items'] ?? 30));
        $dest     = (int)    ($options['dest'] ?? -8144334);

        $req = $this->searchRequest($query, $maxItems, $dest);
        $resp = $this->http->get($req['url'], $req['headers']);
        if (!$resp['ok']) {
            Logger::error('parser-wb', 'search failed', ['status' => $resp['status']]);
            return;
        }
        yield from $this->parseSearchResponse($resp['body'], $maxItems);
    }

    /** @return array{url:string, headers:array<string,string>} */
    public function searchRequest(string $query, int $limit, int $dest = -8144334): array
    {
        $url = self::SEARCH . '?' . http_build_query([
            'ab_testid'  => 'no_action',
            'appType'    => 1,
            'curr'       => 'rub',
            'dest'       => $dest,
            'query'      => $query,
            'resultset'  => 'catalog',
            'sort'       => 'popular',
            'spp'        => 30,
            'page'       => 1,
        ]);
        return ['url' => $url, 'headers' => $this->defaultHeaders()];
    }

    /** @return ParsedProduct[] */
    public function parseSearchResponse(string $body, int $limit): array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [];
        }
        // v9 wraps products in data.products; newer versions serve them at root.
        $products = $json['data']['products'] ?? $json['products'] ?? [];
        if (!is_array($products)) {
            return [];
        }

        $out = [];
        foreach ($products as $p) {
            if (count($out) >= $limit) {
                break;
            }
            if (!is_array($p)) {
                continue;
            }
            $product = $this->mapProduct($p);
            if ($product !== null) {
                $out[] = $product;
            }
        }
        return $out;
    }

    public function fetchOne(string $externalId): ?ParsedProduct
    {
        $url = self::DETAIL . '?' . http_build_query([
            'appType' => 1,
            'curr'    => 'rub',
            'dest'    => -8144334,
            'spp'     => 30,
            'nm'      => $externalId,
        ]);
        $resp = $this->http->get($url, $this->defaultHeaders());
        if (!$resp['ok']) {
            return null;
        }
        $json = json_decode($resp['body'], true);
        $product = $json['data']['products'][0] ?? null;
        if (!is_array($product)) {
            return null;
        }
        return $this->mapProduct($product);
    }

    /** @param array<string,mixed> $p */
    private function mapProduct(array $p): ?ParsedProduct
    {
        $id = isset($p['id']) ? (string) $p['id'] : (isset($p['nm']) ? (string) $p['nm'] : '');
        if ($id === '') {
            return null;
        }
        $title = (string) ($p['name'] ?? '');
        if ($title === '') {
            return null;
        }

        $sizes = $p['sizes'] ?? [];
        $price = 0.0;
        $oldPrice = null;
        if (is_array($sizes) && !empty($sizes)) {
            $first = $sizes[0];
            $priceObj = $first['price'] ?? null;
            if (is_array($priceObj)) {
                $price = (float) (($priceObj['product'] ?? 0) / 100);
                $basic = (float) (($priceObj['basic']   ?? 0) / 100);
                if ($basic > $price) {
                    $oldPrice = $basic;
                }
            }
        }
        if ($price <= 0 && isset($p['salePriceU'])) {
            $price = (float) ($p['salePriceU'] / 100);
        }
        if (isset($p['priceU']) && (float) ($p['priceU'] / 100) > $price) {
            $oldPrice = (float) ($p['priceU'] / 100);
        }

        $imageUrl = $this->buildImageUrl((int) $id);

        return new ParsedProduct(
            source:       'wildberries',
            externalId:   $id,
            title:        $title,
            price:        $price,
            currency:     'RUB',
            oldPrice:     $oldPrice,
            description:  null,
            imageUrl:     $imageUrl,
            images:       $imageUrl ? [$imageUrl] : [],
            externalUrl:  'https://www.wildberries.ru/catalog/' . $id . '/detail.aspx',
            categorySlug: null,
            rating:       isset($p['reviewRating']) ? (float) $p['reviewRating'] : null,
            reviewsCount: (int) ($p['feedbacks'] ?? 0),
            soldCount:    0,
            seller:       isset($p['supplier']) ? (string) $p['supplier'] : null,
            sellerRating: isset($p['supplierRating']) ? (float) $p['supplierRating'] : null,
        );
    }

    /**
     * Wildberries CDN sharding — works for nm ids in known ranges.
     * See https://github.com/glmrenard/wb-private-api for reference.
     */
    private function buildImageUrl(int $nm): ?string
    {
        if ($nm <= 0) {
            return null;
        }
        $vol  = (int) floor($nm / 100000);
        $part = (int) floor($nm / 1000);

        $basket = match (true) {
            $vol <= 143    => '01',
            $vol <= 287    => '02',
            $vol <= 431    => '03',
            $vol <= 719    => '04',
            $vol <= 1007   => '05',
            $vol <= 1061   => '06',
            $vol <= 1115   => '07',
            $vol <= 1169   => '08',
            $vol <= 1313   => '09',
            $vol <= 1601   => '10',
            $vol <= 1655   => '11',
            $vol <= 1919   => '12',
            $vol <= 2045   => '13',
            $vol <= 2189   => '14',
            $vol <= 2405   => '15',
            $vol <= 2621   => '16',
            $vol <= 2837   => '17',
            $vol <= 3053   => '18',
            $vol <= 3269   => '19',
            $vol <= 3485   => '20',
            $vol <= 3701   => '21',
            $vol <= 3917   => '22',
            $vol <= 4133   => '23',
            $vol <= 4349   => '24',
            $vol <= 4565   => '25',
            default        => '26',
        };

        return sprintf(
            'https://basket-%s.wbbasket.ru/vol%d/part%d/%d/images/big/1.webp',
            $basket, $vol, $part, $nm
        );
    }

    /** @return array<string,string> */
    private function defaultHeaders(): array
    {
        return [
            'Accept'          => 'application/json',
            'Accept-Language' => 'ru-RU,ru;q=0.9',
            'Origin'          => 'https://www.wildberries.ru',
            'Referer'         => 'https://www.wildberries.ru/',
        ];
    }
}
