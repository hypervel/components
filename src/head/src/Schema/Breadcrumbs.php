<?php

declare(strict_types=1);

namespace Hypervel\Head\Schema;

use Hypervel\Head\SchemaType;

#[SchemaType('BreadcrumbList')]
class Breadcrumbs extends SchemaObject
{
    /** @var array<int, array{'@type': string, position: int, name: string, item: string}> */
    protected array $items = [];

    /**
     * Append a breadcrumb item.
     */
    public function item(string $name, string $url): static
    {
        $this->items[] = [
            '@type' => 'ListItem',
            'position' => count($this->items) + 1,
            'name' => $name,
            'item' => $url,
        ];

        return $this->set('itemListElement', $this->items);
    }

    /**
     * Append breadcrumb items in order.
     *
     * @param array<string, string> $items breadcrumb names keyed to their URLs
     */
    public function items(array $items): static
    {
        foreach ($items as $name => $url) {
            $this->item($name, $url);
        }

        return $this;
    }
}
