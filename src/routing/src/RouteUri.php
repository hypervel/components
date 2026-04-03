<?php

declare(strict_types=1);

namespace Hypervel\Routing;

class RouteUri
{
    /**
     * Create a new route URI instance.
     */
    public function __construct(
        public string $uri,
        public array $bindingFields = [],
    ) {
    }

    /**
     * Parse the given URI.
     */
    public static function parse(string $uri): static
    {
        preg_match_all('/\{([\w\:]+?)\??\}/', $uri, $matches);

        $bindingFields = [];

        foreach ($matches[0] as $match) {
            if (! str_contains($match, ':')) {
                continue;
            }

            $segments = explode(':', trim($match, '{}?'));

            $bindingFields[$segments[0]] = $segments[1];

            $uri = str_contains($match, '?')
                ? str_replace($match, '{' . $segments[0] . '?}', $uri)
                : str_replace($match, '{' . $segments[0] . '}', $uri);
        }

        return new static($uri, $bindingFields);
    }
}
