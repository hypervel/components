<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Countable;
use IteratorAggregate;
use Traversable;

class RewindableGenerator implements Countable, IteratorAggregate
{
    /**
     * Create a new generator instance.
     */
    public function __construct(
        protected readonly callable $generator,
        protected callable|int $count,
    ) {
    }

    /**
     * Get an iterator from the generator.
     */
    public function getIterator(): Traversable
    {
        return ($this->generator)();
    }

    /**
     * Get the total number of tagged services.
     */
    public function count(): int
    {
        if (is_callable($count = $this->count)) {
            $this->count = $count();
        }

        return $this->count;
    }
}
