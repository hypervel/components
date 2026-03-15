<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Generator;
use Hypervel\Support\Collection;

class SortedMiddleware extends Collection
{
    /**
     * Cached class_implements() / class_parents() results.
     * Persists for worker lifetime — avoids repeated reflection per middleware class.
     *
     * @var array<string, array<string>>
     */
    protected static array $middlewareNamesCache = [];

    /**
     * Flush the static middleware names cache.
     */
    public static function flushCache(): void
    {
        static::$middlewareNamesCache = [];
    }

    /**
     * Create a new sorted middleware container.
     */
    public function __construct(array $priorityMap, Collection|array $middlewares)
    {
        if ($middlewares instanceof Collection) {
            $middlewares = $middlewares->all();
        }

        $this->items = $this->sortMiddleware($priorityMap, $middlewares);
    }

    /**
     * Sort the middlewares by the given priority map.
     *
     * Each call to this method makes one discrete middleware movement if necessary.
     */
    protected function sortMiddleware(array $priorityMap, array $middlewares): array
    {
        $lastIndex = 0;

        foreach ($middlewares as $index => $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $priorityIndex = $this->priorityMapIndex($priorityMap, $middleware);

            if (! is_null($priorityIndex)) {
                // This middleware is in the priority map. If we have encountered another middleware
                // that was also in the priority map and was at a lower priority than the current
                // middleware, we will move this middleware to be above the previous encounter.
                if (isset($lastPriorityIndex) && $priorityIndex < $lastPriorityIndex) {
                    return $this->sortMiddleware(
                        $priorityMap,
                        array_values($this->moveMiddleware($middlewares, $index, $lastIndex))
                    );
                }

                // This middleware is in the priority map; but, this is the first middleware we have
                // encountered from the map thus far. We'll save its current index plus its index
                // from the priority map so we can compare against them on the next iterations.
                $lastIndex = $index;

                $lastPriorityIndex = $priorityIndex;
            }
        }

        return Router::uniqueMiddleware($middlewares);
    }

    /**
     * Calculate the priority map index of the middleware.
     */
    protected function priorityMapIndex(array $priorityMap, string $middleware): ?int
    {
        foreach ($this->middlewareNames($middleware) as $name) {
            $priorityIndex = array_search($name, $priorityMap, true);

            if ($priorityIndex !== false) {
                return $priorityIndex;
            }
        }

        return null;
    }

    /**
     * Resolve the middleware names to look for in the priority array.
     */
    protected function middlewareNames(string $middleware): Generator
    {
        $stripped = head(explode(':', $middleware));

        if (isset(static::$middlewareNamesCache[$stripped])) {
            yield from static::$middlewareNamesCache[$stripped];
            return;
        }

        $names = [$stripped];

        $interfaces = @class_implements($stripped);

        if ($interfaces !== false) {
            foreach ($interfaces as $interface) {
                $names[] = $interface;
            }
        }

        $parents = @class_parents($stripped);

        if ($parents !== false) {
            foreach ($parents as $parent) {
                $names[] = $parent;
            }
        }

        static::$middlewareNamesCache[$stripped] = $names;

        yield from $names;
    }

    /**
     * Splice a middleware into a new position and remove the old entry.
     */
    protected function moveMiddleware(array $middlewares, int $from, int $to): array
    {
        array_splice($middlewares, $to, 0, $middlewares[$from]);

        unset($middlewares[$from + 1]);

        return $middlewares;
    }
}
