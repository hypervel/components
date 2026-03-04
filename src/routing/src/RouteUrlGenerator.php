<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use BackedEnum;
use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Routing\Exceptions\UrlGenerationException;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class RouteUrlGenerator
{
    /**
     * The URL generator instance.
     */
    protected UrlGenerator $url;

    /**
     * The named parameter defaults.
     */
    public array $defaultParameters = [];

    /**
     * Characters that should not be URL encoded.
     */
    public array $dontEncode = [
        '%2F' => '/',
        '%40' => '@',
        '%3A' => ':',
        '%3B' => ';',
        '%2C' => ',',
        '%3D' => '=',
        '%2B' => '+',
        '%21' => '!',
        '%2A' => '*',
        '%7C' => '|',
        '%3F' => '?',
        '%26' => '&',
        '%23' => '#',
        '%25' => '%',
    ];

    /**
     * Create a new route URL generator.
     */
    public function __construct(UrlGenerator $url)
    {
        $this->url = $url;
    }

    /**
     * Generate a URL for the given route.
     *
     * @throws \Hypervel\Routing\Exceptions\UrlGenerationException
     */
    public function to(Route $route, array $parameters = [], bool $absolute = false): string
    {
        $parameters = $this->formatParameters($route, $parameters);

        $domain = $this->getRouteDomain($route, $parameters);

        // First we will construct the entire URI including the root and query string. Once it
        // has been constructed, we'll make sure we don't have any missing parameters or we
        // will need to throw the exception to let the developers know one was not given.
        $uri = $this->addQueryString($this->url->format(
            $root = $this->replaceRootParameters($route, $domain, $parameters),
            $this->replaceRouteParameters($route->uri(), $parameters),
            $route
        ), $parameters);

        if (preg_match_all('/{(.*?)}/', $uri, $matchedMissingParameters)) {
            throw UrlGenerationException::forMissingParameters($route, $matchedMissingParameters[1]);
        }

        // Once we have ensured that there are no missing parameters in the URI we will encode
        // the URI and prepare it for returning to the developer. If the URI is supposed to
        // be absolute, we will return it as-is. Otherwise we will remove the URL's root.
        $uri = strtr(rawurlencode($uri), $this->dontEncode);

        if (! $absolute) {
            $uri = preg_replace('#^(//|[^/?])+#', '', $uri);

            if ($base = $this->url->getRequest()->getBaseUrl()) {
                $uri = preg_replace('#^' . $base . '#i', '', $uri);
            }

            return '/' . ltrim($uri, '/');
        }

        return $uri;
    }

    /**
     * Get the formatted domain for a given route.
     */
    protected function getRouteDomain(Route $route, array &$parameters): ?string
    {
        return $route->getDomain() ? $this->formatDomain($route, $parameters) : null;
    }

    /**
     * Format the domain and port for the route and request.
     */
    protected function formatDomain(Route $route, array &$parameters): string
    {
        return $this->addPortToDomain(
            $this->getRouteScheme($route) . $route->getDomain()
        );
    }

    /**
     * Get the scheme for the given route.
     */
    protected function getRouteScheme(Route $route): string
    {
        if ($route->httpOnly()) {
            return 'http://';
        }
        if ($route->httpsOnly()) {
            return 'https://';
        }

        return $this->url->formatScheme();
    }

    /**
     * Add the port to the domain if necessary.
     */
    protected function addPortToDomain(string $domain): string
    {
        $request = $this->url->getRequest();

        $secure = $request->isSecure();

        $port = (int) $request->getPort();

        return ($secure && $port === 443) || (! $secure && $port === 80)
            ? $domain
            : $domain . ':' . $port;
    }

    /**
     * Format the array of route parameters.
     */
    protected function formatParameters(Route $route, mixed $parameters): array
    {
        $parameters = Arr::wrap($parameters);

        $namedParameters = [];
        $namedQueryParameters = [];
        $requiredRouteParametersWithoutDefaultsOrNamedParameters = [];

        $routeParameters = $route->parameterNames();
        $optionalParameters = $route->getOptionalParameterNames();

        foreach ($routeParameters as $name) {
            if (isset($parameters[$name])) {
                // Named parameters don't need any special handling...
                $namedParameters[$name] = $parameters[$name];
                unset($parameters[$name]);

                continue;
            }
            $bindingField = $route->bindingFieldFor($name);
            $defaultParameterKey = $bindingField ? "{$name}:{$bindingField}" : $name;

            if (! isset($this->defaultParameters[$defaultParameterKey]) && ! isset($optionalParameters[$name])) {
                // No named parameter or default value for a required parameter, try to match to positional parameter below...
                array_push($requiredRouteParametersWithoutDefaultsOrNamedParameters, $name);
            }

            $namedParameters[$name] = '';
        }

        // Named parameters that don't have route parameters will be used for query string...
        foreach ($parameters as $key => $value) {
            if (is_string($key)) {
                $namedQueryParameters[$key] = $value;

                unset($parameters[$key]);
            }
        }

        // Match positional parameters to the route parameters that didn't have a value in order...
        if (count($parameters) === count($requiredRouteParametersWithoutDefaultsOrNamedParameters)) {
            foreach (array_reverse($requiredRouteParametersWithoutDefaultsOrNamedParameters) as $name) {
                if (count($parameters) === 0) {
                    break;
                }

                $namedParameters[$name] = array_pop($parameters);
            }
        }

        $offset = 0;
        $emptyParameters = array_filter($namedParameters, static fn ($val) => $val === '');

        if (count($requiredRouteParametersWithoutDefaultsOrNamedParameters) !== 0
            && count($parameters) !== count($emptyParameters)) {
            // Find the index of the first required parameter...
            $offset = array_search($requiredRouteParametersWithoutDefaultsOrNamedParameters[0], array_keys($namedParameters));

            // If more empty parameters remain, adjust the offset...
            $remaining = count($emptyParameters) - $offset - count($parameters);

            if ($remaining < 0) {
                // Effectively subtract the remaining count since it's negative...
                $offset += $remaining;
            }

            // Correct offset if it goes below zero...
            if ($offset < 0) {
                $offset = 0;
            }
        } elseif (count($requiredRouteParametersWithoutDefaultsOrNamedParameters) === 0 && count($parameters) !== 0) {
            // Handle the case where all passed parameters are for parameters that have default values...
            $remainingCount = count($parameters);

            // Loop over empty parameters backwards and stop when we run out of passed parameters...
            for ($i = count($namedParameters) - 1; $i >= 0; --$i) {
                if ($namedParameters[array_keys($namedParameters)[$i]] === '') {
                    $offset = $i;
                    --$remainingCount;

                    if ($remainingCount === 0) {
                        // If there are no more passed parameters, we stop here...
                        break;
                    }
                }
            }
        }

        // Starting from the offset, match any passed parameters from left to right...
        for ($i = $offset; $i < count($namedParameters); ++$i) {
            $key = array_keys($namedParameters)[$i];

            if ($namedParameters[$key] !== '') {
                continue;
            }
            if (! empty($parameters)) {
                $namedParameters[$key] = array_shift($parameters);
            }
        }

        // Fill leftmost parameters with defaults if the loop above was offset...
        foreach ($namedParameters as $key => $value) {
            $bindingField = $route->bindingFieldFor($key);
            $defaultParameterKey = $bindingField ? "{$key}:{$bindingField}" : $key;

            if ($value === '' && isset($this->defaultParameters[$defaultParameterKey])) {
                $namedParameters[$key] = $this->defaultParameters[$defaultParameterKey];
            }
        }

        // Any remaining values in $parameters are unnamed query string parameters...
        $parameters = array_merge($namedParameters, $namedQueryParameters, $parameters);

        $parameters = Collection::wrap($parameters)->map(function ($value, $key) use ($route) {
            return $value instanceof UrlRoutable && $route->bindingFieldFor($key)
                    ? $value->{$route->bindingFieldFor($key)}
                    : $value;
        })->all();

        array_walk_recursive($parameters, function (&$item) {
            if ($item instanceof BackedEnum) {
                $item = $item->value;
            }
        });

        return $this->url->formatParameters($parameters);
    }

    /**
     * Replace the parameters on the root path.
     */
    protected function replaceRootParameters(Route $route, ?string $domain, array &$parameters): string
    {
        $scheme = $this->getRouteScheme($route);

        return $this->replaceRouteParameters(
            $this->url->formatRoot($scheme, $domain),
            $parameters
        );
    }

    /**
     * Replace all of the wildcard parameters for a route path.
     */
    protected function replaceRouteParameters(string $path, array &$parameters): string
    {
        $path = $this->replaceNamedParameters($path, $parameters);

        $path = preg_replace_callback('/\{.*?\}/', function ($match) use (&$parameters) {
            // Reset only the numeric keys...
            $parameters = array_merge($parameters);

            return (! isset($parameters[0]) && ! str_ends_with($match[0], '?}'))
                ? $match[0]
                : Arr::pull($parameters, 0);
        }, $path);

        return trim(preg_replace('/\{.*?\?\}/', '', $path), '/');
    }

    /**
     * Replace all of the named parameters in the path.
     */
    protected function replaceNamedParameters(string $path, array &$parameters): string
    {
        return preg_replace_callback('/\{(.*?)(\?)?\}/', function ($m) use (&$parameters) {
            if (isset($parameters[$m[1]]) && $parameters[$m[1]] !== '') {
                return Arr::pull($parameters, $m[1]);
            }
            if (isset($this->defaultParameters[$m[1]])) {
                return $this->defaultParameters[$m[1]];
            }
            if (isset($parameters[$m[1]])) {
                Arr::pull($parameters, $m[1]);
            }

            return $m[0];
        }, $path);
    }

    /**
     * Add a query string to the URI.
     */
    protected function addQueryString(string $uri, array $parameters): string
    {
        // If the URI has a fragment we will move it to the end of this URI since it will
        // need to come after any query string that may be added to the URL else it is
        // not going to be available. We will remove it then append it back on here.
        if (! is_null($fragment = parse_url($uri, PHP_URL_FRAGMENT))) {
            $uri = preg_replace('/#.*/', '', $uri);
        }

        $uri .= $this->getRouteQueryString($parameters);

        return is_null($fragment) ? $uri : $uri . "#{$fragment}";
    }

    /**
     * Get the query string for a given route.
     */
    protected function getRouteQueryString(array $parameters): string
    {
        // First we will get all of the string parameters that are remaining after we
        // have replaced the route wildcards. We'll then build a query string from
        // these string parameters then use it as a starting point for the rest.
        if (count($parameters) === 0) {
            return '';
        }

        $query = Arr::query(
            $keyed = $this->getStringParameters($parameters)
        );

        // Lastly, if there are still parameters remaining, we will fetch the numeric
        // parameters that are in the array and add them to the query string or we
        // will make the initial query string if it wasn't started with strings.
        if (count($keyed) < count($parameters)) {
            $query .= '&' . implode(
                '&',
                $this->getNumericParameters($parameters)
            );
        }

        $query = trim($query, '&');

        return $query === '' ? '' : "?{$query}";
    }

    /**
     * Get the string parameters from a given list.
     */
    protected function getStringParameters(array $parameters): array
    {
        return array_filter($parameters, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Get the numeric parameters from a given list.
     */
    protected function getNumericParameters(array $parameters): array
    {
        return array_filter($parameters, 'is_numeric', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Set the default named parameters used by the URL generator.
     */
    public function defaults(array $defaults): void
    {
        $this->defaultParameters = array_merge(
            $this->defaultParameters,
            $defaults
        );
    }
}
