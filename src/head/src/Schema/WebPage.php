<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('WebPage')]
class WebPage extends SchemaObject
{
    /**
     * Set the page name.
     */
    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    /**
     * Set the page description.
     */
    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Set the page URL.
     */
    public function url(string $url): static
    {
        return $this->set('url', $url);
    }
}
