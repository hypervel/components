<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Countable;
use Hypervel\Database\Eloquent\Model;

class Sequence implements Countable
{
    /**
     * The sequence of return values.
     */
    protected array $sequence;

    /**
     * The count of the sequence items.
     */
    public int $count;

    /**
     * The current index of the sequence iteration.
     */
    public int $index = 0;

    /**
     * Create a new sequence instance.
     */
    public function __construct(mixed ...$sequence)
    {
        $this->sequence = $sequence;
        $this->count = count($sequence);
    }

    /**
     * Get the current count of the sequence items.
     */
    public function count(): int
    {
        return $this->count;
    }

    /**
     * Get the next value in the sequence.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(array $attributes = [], ?Model $parent = null): mixed
    {
        return tap(value($this->sequence[$this->index % $this->count], $this, $attributes, $parent), function () {
            $this->index = $this->index + 1;
        });
    }
}
