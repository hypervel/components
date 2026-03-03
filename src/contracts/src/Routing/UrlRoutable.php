<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Routing;

use Hypervel\Database\Eloquent\Model;

interface UrlRoutable
{
    /**
     * Get the value of the model's route key.
     */
    public function getRouteKey(): mixed;

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string;

    /**
     * Retrieve the model for a bound value.
     */
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?Model;

    /**
     * Retrieve the child model for a bound value.
     */
    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field): ?Model;
}
