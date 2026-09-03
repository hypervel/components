<?php

declare(strict_types=1);

namespace Hypervel\Data\Contracts;

use IteratorAggregate;

/**
 * @template TKey of array-key
 * @template TValue of BaseData
 *
 * @extends IteratorAggregate<TKey, TValue>
 */
interface BaseDataCollectable extends IteratorAggregate
{
    /**
     * Get the data class stored by the collection.
     *
     * @return class-string<TValue>
     */
    public function getDataClass(): string;
}
