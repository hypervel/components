<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Http\Request;
use Hypervel\Support\Arr;

class RouteParameterBinder
{
    /**
     * Create a new Route parameter binder instance.
     */
    public function __construct(
        protected Route $route,
    ) {
    }

    /**
     * Get the parameters for the route.
     */
    public function parameters(Request $request): array
    {
        $parameters = $this->bindPathParameters($request);

        // If the route has a regular expression for the host part of the URI, we will
        // compile that and get the parameter matches for this domain. We will then
        // merge them into this parameters array so that this array is completed.
        if (! is_null($this->route->compiled->getHostRegex())) {
            $parameters = $this->bindHostParameters(
                $request,
                $parameters
            );
        }

        return $this->replaceDefaults($parameters);
    }

    /**
     * Get the parameter matches for the path portion of the URI.
     */
    protected function bindPathParameters(Request $request): array
    {
        $path = '/' . ltrim($request->decodedPath(), '/');

        preg_match($this->route->compiled->getRegex(), $path, $matches);

        return $this->matchToKeys(array_slice($matches, 1));
    }

    /**
     * Extract the parameter list from the host part of the request.
     */
    protected function bindHostParameters(Request $request, array $parameters): array
    {
        preg_match($this->route->compiled->getHostRegex(), $request->getHost(), $matches);

        return array_merge($this->matchToKeys(array_slice($matches, 1)), $parameters);
    }

    /**
     * Combine a set of parameter matches with the route's keys.
     */
    protected function matchToKeys(array $matches): array
    {
        if (empty($parameterNames = $this->route->parameterNames())) {
            return [];
        }

        $parameters = array_intersect_key($matches, array_flip($parameterNames));

        return array_filter($parameters, function ($value) {
            return is_string($value) && strlen($value) > 0;
        });
    }

    /**
     * Replace null parameters with their defaults.
     */
    protected function replaceDefaults(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            $parameters[$key] = $value ?? Arr::get($this->route->defaults, $key);
        }

        foreach ($this->route->defaults as $key => $value) {
            if (! isset($parameters[$key])) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }
}
