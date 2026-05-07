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
     * Live full-text search against the source's API.
     *
     * Default implementation returns empty (parser does not support live search).
     * Override in concrete parsers that expose a search endpoint.
     *
     * @param array<string,mixed> $options Implementation-specific (e.g. limit, dest).
     * @return iterable<ParsedProduct>
     */
    public function searchQuery(string $query, array $options = []): iterable
    {
        return [];
    }
}
