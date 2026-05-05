<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

/**
 * Plain DTO yielded by parsers.
 */
final class ParsedProduct
{
    /** @param string[] $images */
    public function __construct(
        public string $source,
        public string $externalId,
        public string $title,
        public float $price,
        public string $currency = 'UZS',
        public ?float $oldPrice = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
        public array $images = [],
        public ?string $externalUrl = null,
        public ?string $categorySlug = null,
        public ?float $rating = null,
        public int $reviewsCount = 0,
        public int $soldCount = 0,
        public ?string $seller = null,
        public ?float $sellerRating = null,
    ) {}

    /** @return array<string,mixed> */
    public function toRow(): array
    {
        return [
            'source'        => $this->source,
            'external_id'   => $this->externalId,
            'external_url'  => $this->externalUrl,
            'title'         => mb_substr($this->title, 0, 500),
            'description'   => $this->description,
            'image_url'     => $this->imageUrl,
            'images_json'   => $this->images ? json_encode($this->images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'price'         => $this->price,
            'old_price'     => $this->oldPrice,
            'currency'      => $this->currency,
            'rating'        => $this->rating,
            'reviews_count' => $this->reviewsCount,
            'sold_count'    => $this->soldCount,
            'seller'        => $this->seller,
            'seller_rating' => $this->sellerRating,
            'category_slug' => $this->categorySlug,
        ];
    }
}
