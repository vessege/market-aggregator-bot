<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

abstract class BaseParser
{
    public function __construct(protected HttpClient $http) {}

    abstract public function source(): string;

    abstract public function displayName(): string;

    /**
     * Fetch a list/feed of products.
     *
     * @param array<string,mixed> $options Implementation-specific (e.g. category, page, limit).
     * @return iterable<ParsedProduct>
     */
    abstract public function fetchFeed(array $options = []): iterable;

    /**
     * Fetch a single product by external id (used for live refresh of price/availability).
     */
    public function fetchOne(string $externalId): ?ParsedProduct
    {
        return null;
    }

    /**
     * Build the HTTP request for a live keyword search on this marketplace,
     * or null if the driver doesn't support live search. Requests from all
     * parsers are executed in parallel by ParserManager::liveSearch().
     *
     * @return array{method?:string, url:string, headers?:array<string,string>, body?:mixed}|null
     */
    public function searchRequest(string $query, int $limit): ?array
    {
        return null;
    }

    /**
     * Parse the body returned for searchRequest() into products.
     *
     * @return ParsedProduct[]
     */
    public function parseSearchResponse(string $body, int $limit): array
    {
        return [];
    }

    public function supportsLiveSearch(): bool
    {
        return $this->searchRequest('probe', 1) !== null;
    }
}
