<?php

declare(strict_types=1);

namespace Hypervel\Routing;

trait FiltersControllerMiddleware
{
    /**
     * Determine if the given options exclude a particular method.
     */
    public static function methodExcludedByOptions(string $method, array $options): bool
    {
        return (isset($options['only']) && ! in_array($method, (array) $options['only'], true))
               || (! empty($options['except']) && in_array($method, (array) $options['except'], true));
    }
}
