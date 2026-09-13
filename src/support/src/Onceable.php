<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hypervel\Contracts\Support\HasOnceHash;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use WeakMap;

class Onceable
{
    protected const int DEFAULT_LAST_OBJECT_TOKEN = 0;

    /**
     * The tokens identifying captured objects.
     *
     * @var null|WeakMap<object, int>
     */
    private static ?WeakMap $objectTokens = null;

    /**
     * The last token assigned to a captured object.
     */
    private static int $lastObjectToken = self::DEFAULT_LAST_OBJECT_TOKEN;

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

        $uses = array_map(
            static function (mixed $argument): mixed {
                if ($argument instanceof HasOnceHash) {
                    return $argument->onceHash();
                }

                if (is_object($argument)) {
                    // Object IDs can be reused after destruction; tokens remain unique without retaining the objects.
                    self::$objectTokens ??= new WeakMap;

                    // Keep object tokens distinct from captured scalars and arrays.
                    return (object) ['id' => self::$objectTokens[$argument] ??= ++self::$lastObjectToken];
                }

                return $argument;
            },
            $callable instanceof Closure ? (new ReflectionClosure($callable))->getClosureUsedVariables() : [],
        );

        $class = $callable instanceof Closure ? (new ReflectionClosure($callable))->getClosureCalledClass()?->getName() : null;

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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$objectTokens = null;
        self::$lastObjectToken = self::DEFAULT_LAST_OBJECT_TOKEN;
    }
}
