<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Support\Collection;

use function Hypervel\Support\enum_value;

trait CreatesRegularExpressionRouteConstraints
{
    /**
     * Specify that the given route parameters must be alphabetic.
     */
    public function whereAlpha(array|string $parameters): static
    {
        return $this->assignExpressionToParameters($parameters, '[a-zA-Z]+');
    }

    /**
     * Specify that the given route parameters must be alphanumeric.
     */
    public function whereAlphaNumeric(array|string $parameters): static
    {
        return $this->assignExpressionToParameters($parameters, '[a-zA-Z0-9]+');
    }

    /**
     * Specify that the given route parameters must be numeric.
     */
    public function whereNumber(array|string $parameters): static
    {
        return $this->assignExpressionToParameters($parameters, '[0-9]+');
    }

    /**
     * Specify that the given route parameters must be ULIDs.
     */
    public function whereUlid(array|string $parameters): static
    {
        return $this->assignExpressionToParameters($parameters, '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}');
    }

    /**
     * Specify that the given route parameters must be UUIDs.
     */
    public function whereUuid(array|string $parameters): static
    {
        return $this->assignExpressionToParameters($parameters, '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}');
    }

    /**
     * Specify that the given route parameters must be one of the given values.
     */
    public function whereIn(array|string $parameters, array $values): static
    {
        return $this->assignExpressionToParameters(
            $parameters,
            (new Collection($values))
                ->map(fn ($value) => enum_value($value))
                ->implode('|')
        );
    }

    /**
     * Apply the given regular expression to the given parameters.
     */
    protected function assignExpressionToParameters(array|string $parameters, string $expression): static
    {
        return $this->where(Collection::wrap($parameters)
            ->mapWithKeys(fn ($parameter) => [$parameter => $expression])
            ->all());
    }
}
