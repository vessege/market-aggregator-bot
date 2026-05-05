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
}
