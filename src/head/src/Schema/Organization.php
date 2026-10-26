<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('Organization')]
class Organization extends SchemaObject
{
    /**
     * Set the organization name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the organization URL.
     */
    public function url(string $url): static
    {
        return $this->set('url', $url);
    }

    /**
     * Set the organization logo URL.
     */
    public function logo(string $url): static
    {
        return $this->set('logo', $url);
    }
}
