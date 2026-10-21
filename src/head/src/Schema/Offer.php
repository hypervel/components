<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\Enums\OfferAvailability;
use Hypervel\Head\SchemaType;

#[SchemaType('Offer')]
class Offer extends SchemaObject
{
    /**
     * Set the offer price.
     */
    public function price(float|int|string $price): static
    {
        return $this->set('price', $price);
    }

    /**
     * Set the price currency.
     */
    public function currency(string $currency): static
    {
        return $this->set('priceCurrency', $currency);
    }

    /**
     * Set the offer availability.
     */
    public function availability(OfferAvailability $availability): static
    {
        return $this->set('availability', $availability->url());
    }
}
