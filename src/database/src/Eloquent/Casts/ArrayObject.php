<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use ArrayObject as BaseArrayObject;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use JsonSerializable;

/**
 * @template TKey of array-key
 * @template TItem
 *
 * @extends \ArrayObject<TKey, TItem>
 */
class ArrayObject extends BaseArrayObject implements Arrayable, JsonSerializable
{
    /**
     * Get a collection containing the underlying array.
     */
    public function collect(): Collection
    {
        return new Collection($this->getArrayCopy());
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->getArrayCopy();
    }

    /**
     * Get the array that should be JSON serialized.
     */
    public function jsonSerialize(): array
    {
        return $this->getArrayCopy();
    }
}
