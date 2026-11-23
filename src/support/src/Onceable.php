<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hypervel\Contracts\Support\HasOnceHash;
use Laravel\SerializableClosure\Support\ReflectionClosure;

class Onceable
{
    /**
     * Create a new onceable instance.
     *
     * @param callable $callable
     */
    public function __construct(
        public string $hash,
        public ?object $object,
        public $callable,
    ) {
    }

    /**
     * Try to create a new onceable instance from the given trace.
     *
     * @param array<int, array<string, mixed>> $trace
     */
    public static function tryFromTrace(array $trace, callable $callable): ?static
    {
        if (! is_null($hash = static::hashFromTrace($trace, $callable))) {
            $object = static::objectFromTrace($trace);

            return new static($hash, $object, $callable);
        }

        return null;
    }

    /**
     * Get the objects used by the callable, which are hashed by their identity.
     *
     * @return array<int, object>
     */
    public function objects(): array
    {
        if (! $this->callable instanceof Closure) {
            return [];
        }

        $objects = [];

        foreach ((new ReflectionClosure($this->callable))->getClosureUsedVariables() as $argument) {
            if (is_object($argument) && ! $argument instanceof HasOnceHash) {
                $objects[] = $argument;
            }
        }

        return $objects;
    }

    /**
     * Compute the object of the onceable from the given trace, if any.
     *
     * @param array<int, array<string, mixed>> $trace
     */
    protected static function objectFromTrace(array $trace): ?object
    {
        return $trace[1]['object'] ?? null;
    }

    /**
     * Compute the hash of the onceable from the given trace.
     *
     * @param array<int, array<string, mixed>> $trace
     */
    protected static function hashFromTrace(array $trace, callable $callable): ?string
    {
        if (str_contains($trace[0]['file'] ?? '', 'eval()\'d code')) {
            return null;
        }

        // Reuse the reflection for captures and called class on this frequently used path.
        $reflection = $callable instanceof Closure ? new ReflectionClosure($callable) : null;

        $uses = array_map(
            static function (mixed $argument): mixed {
                if ($argument instanceof HasOnceHash) {
                    return $argument->onceHash();
                }

                if (is_object($argument)) {
                    // Keep object identities distinct from captured scalars and arrays.
                    return (object) ['id' => spl_object_id($argument)];
                }

                return $argument;
            },
            $reflection?->getClosureUsedVariables() ?? [],
        );

        $class = $reflection?->getClosureCalledClass()?->getName();

        $class ??= isset($trace[1]['class']) ? $trace[1]['class'] : null;

        return hash('xxh128', sprintf(
            '%s@%s%s:%s (%s)',
            $trace[0]['file'],
            $class ? $class . '@' : '',
            $trace[1]['function'] ?? '',
            $trace[0]['line'],
            serialize($uses),
        ));
    }
}
