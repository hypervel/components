<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;

trait MatchesProperties
{
    /**
     * Resolve a truth-test callback or an array of expected properties.
     *
     * @param null|array<string, mixed>|callable $callback
     */
    protected function resolveTruthTest(callable|array|null $callback): ?callable
    {
        if (! is_array($callback) || is_callable($callback)) {
            return $callback;
        }

        return fn (object|string $object): bool => (new Collection($callback))->every(function (mixed $expected, string $property) use ($object): bool {
            if (! property_exists($object, $property) && ! isset($object->{$property})) {
                return false;
            }

            $actual = $object->{$property} ?? null;

            return $expected instanceof Model && $actual instanceof Model
                ? $expected->is($actual)
                : $actual === $expected;
        });
    }
}
