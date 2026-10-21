<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('Product')]
class Product extends SchemaObject
{
    /**
     * Set the product name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the product description.
     */
    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Set the product images.
     *
     * @param array<int, string>|string $image
     */
    public function image(string|array $image): static
    {
        return $this->set('image', $image);
    }

    /**
     * Set the product stock-keeping unit.
     */
    public function sku(string $sku): static
    {
        return $this->set('sku', $sku);
    }

    /**
     * Set the product brand.
     */
    public function brand(Brand|Organization $brand): static
    {
        return $this->set('brand', $brand);
    }

    /**
     * Set the product offers.
     *
     * @param array<int, Offer>|Offer $offers
     */
    public function offers(Offer|array $offers): static
    {
        return $this->set('offers', $offers);
    }
}
