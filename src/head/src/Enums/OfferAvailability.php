<?php

declare(strict_types=1);

namespace Hypervel\Head\Enums;

enum OfferAvailability: string
{
    case BackOrder = 'BackOrder';
    case Discontinued = 'Discontinued';
    case InStock = 'InStock';
    case InStoreOnly = 'InStoreOnly';
    case LimitedAvailability = 'LimitedAvailability';
    case MadeToOrder = 'MadeToOrder';
    case OnlineOnly = 'OnlineOnly';
    case OutOfStock = 'OutOfStock';
    case PreOrder = 'PreOrder';
    case PreSale = 'PreSale';
    case Reserved = 'Reserved';
    case SoldOut = 'SoldOut';

    /**
     * Get the schema.org availability URL.
     */
    public function url(): string
    {
        return 'https://schema.org/' . $this->value;
    }
}
