<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation\References;

use Hypervel\Data\Exceptions\CannotResolveRouteParameterReference;

class RouteParameterReference implements ExternalReference
{
    /**
     * Create a route parameter reference.
     */
    public function __construct(
        public readonly string $routeParameter,
        public readonly ?string $property = null,
        public readonly bool $nullable = false,
    ) {
    }

    /**
     * Resolve the route parameter, or the given property of it, from the current request.
     *
     * @throws CannotResolveRouteParameterReference
     */
    public function getValue(): mixed
    {
        $parameter = request()->route($this->routeParameter);

        if ($parameter === null && $this->nullable === false) {
            throw CannotResolveRouteParameterReference::parameterNotFound($this->routeParameter, $this->property);
        }

        if ($parameter === null) {
            return null;
        }

        if ($this->property === null) {
            return $parameter;
        }

        $value = data_get($parameter, $this->property);

        if ($value === null) {
            throw CannotResolveRouteParameterReference::propertyOnParameterNotFound($this->routeParameter, $this->property);
        }

        return $value;
    }
}
