<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Support;

/**
 * @template-covariant TReturn of array = array<array-key, mixed>
 */
interface Arrayable
{
    /**
     * Get the instance as an array.
     *
     * @return TReturn
     */
    public function toArray(): array;
}
