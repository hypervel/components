<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('Person')]
class Person extends SchemaObject
{
    /**
     * Set the person's name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the person's URL.
     */
    public function url(string $url): static
    {
        return $this->set('url', $url);
    }
}
