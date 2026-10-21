<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('Place')]
class Place extends SchemaObject
{
    /**
     * Set the place name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the place address.
     *
     * @param array<string, mixed>|SchemaObject|string $address
     */
    public function address(SchemaObject|array|string $address): static
    {
        return $this->set('address', $address);
    }

    /**
     * Set the place URL.
     */
    public function url(string $url): static
    {
        return $this->set('url', $url);
    }
}
