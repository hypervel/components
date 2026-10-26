<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('Brand')]
class Brand extends SchemaObject
{
    /**
     * Set the brand name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the brand URL.
     */
    public function url(string $url): static
    {
        return $this->set('url', $url);
    }

    /**
     * Set the brand logo URL.
     */
    public function logo(string $url): static
    {
        return $this->set('logo', $url);
    }
}
