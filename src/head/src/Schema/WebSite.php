<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('WebSite')]
class WebSite extends SchemaObject
{
    /**
     * Set the website name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the website URL.
     */
    public function url(string $url): static
    {
        return $this->set('url', $url);
    }
}
