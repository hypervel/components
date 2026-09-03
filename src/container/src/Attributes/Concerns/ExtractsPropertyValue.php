<?php

declare(strict_types=1);

namespace Hypervel\Container\Attributes\Concerns;

use Hypervel\Contracts\Container\BindingResolutionException;

trait ExtractsPropertyValue
{
    /**
     * Extract a property path from a contextual value.
     *
     * @throws BindingResolutionException
     */
    protected function extractPropertyValue(mixed $value, ?string $property): mixed
    {
        if ($property === null) {
            return $value;
        }

        if (is_scalar($value)) {
            throw new BindingResolutionException(sprintf(
                'Cannot extract property path [%s] from scalar [%s] resolved by [%s].',
                $property,
                get_debug_type($value),
                static::class,
            ));
        }

        return data_get($value, $property);
    }
}
