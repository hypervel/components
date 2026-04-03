<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Database\Eloquent;

use Hypervel\Database\Eloquent\Model;

interface DeviatesCastableAttributes
{
    /**
     * Increment the attribute.
     *
     * @param array<string, mixed> $attributes
     */
    public function increment(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Decrement the attribute.
     *
     * @param array<string, mixed> $attributes
     */
    public function decrement(Model $model, string $key, mixed $value, array $attributes): mixed;
}
