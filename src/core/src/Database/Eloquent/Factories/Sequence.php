<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Closure;
use Countable;

class Sequence implements Countable
{
    /**
     * The sequence of return values.
     *
     * @var array<int, array<string, mixed>|Closure(static): array<string, mixed>>
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
     *
     * @param array<string, mixed>|callable ...$sequence
     */
    public function __construct(
        ...$sequence
    ) {
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
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return tap(
            value($this->sequence[$this->index % $this->count], $this),
            fn () => $this->index++,
        );
    }
}
