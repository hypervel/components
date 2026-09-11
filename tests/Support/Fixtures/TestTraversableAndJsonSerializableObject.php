<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Fixtures;

use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class TestTraversableAndJsonSerializableObject implements IteratorAggregate, JsonSerializable
{
    public array $items;

    /**
     * Create a new fixture instance.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Get an iterator for the items.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Get the data to serialize as JSON.
     */
    public function jsonSerialize(): array
    {
        return json_decode(json_encode($this->items), true);
    }
}
