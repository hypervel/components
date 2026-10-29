<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use PropertyHookType;
use ReflectionProperty;

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
            if (! is_object($object)) {
                return false;
            }

            if (! isset($object->{$property})) {
                if (! property_exists($object, $property)) {
                    return false;
                }

                $reflection = new ReflectionProperty($object, $property);

                if (! $reflection->isPublic() || $reflection->isStatic()
                    || (! $reflection->hasHook(PropertyHookType::Get) && ! $reflection->isInitialized($object))) {
                    return false;
                }
            }

            $actual = $object->{$property};

            return $expected instanceof Model && $actual instanceof Model
                ? $expected->is($actual)
                : $actual === $expected;
        });
    }
}
