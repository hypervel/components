<?php

declare(strict_types=1);

use Hypervel\Container\Container;
use Hypervel\Contracts\Support\DeferringDisplayableValue;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Arr;
use Hypervel\Support\Env;
use Hypervel\Support\Environment;
use Hypervel\Support\Fluent;
use Hypervel\Support\HigherOrderTapProxy;
use Hypervel\Support\Once;
use Hypervel\Support\Onceable;
use Hypervel\Support\Optional;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable as SupportStringable;

if (! function_exists('append_config')) {
    /**
     * Assign high numeric IDs to a config item to force appending.
     */
    function append_config(array $array): array
    {
        $start = 9999;

        foreach ($array as $key => $value) {
            if (is_numeric($key)) {
                ++$start;

                $array[$start] = Arr::pull($array, $key);
            }
        }

        return $array;
    }
}

if (! function_exists('blank')) {
    /**
     * Determine if the given value is "blank".
     *
     * @phpstan-assert-if-false !=null|'' $value
     *
     * @phpstan-assert-if-true !=numeric|bool $value
     *
     * @param mixed $value
     */
    function blank($value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_numeric($value) || is_bool($value)) {
            return false;
        }

        if ($value instanceof Model) {
            return false;
        }

        if ($value instanceof Countable) {
            return count($value) === 0;
        }

        if ($value instanceof Stringable) {
            return trim((string) $value) === '';
        }

        return empty($value);
    }
}

if (! function_exists('class_basename')) {
    /**
     * Get the class "basename" of the given object / class.
     *
     * @param object|string $class
     */
    function class_basename($class): string
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }
}

if (! function_exists('class_uses_recursive')) {
    /**
     * Returns all traits used by a class, its parent classes and trait of their traits.
     *
     * @param object|string $class
     * @return array<string, string>
     */
    function class_uses_recursive($class): array
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class) ?: []) + [$class => $class] as $class) {
            $results += trait_uses_recursive($class);
        }

        return array_unique($results);
    }
}

if (! function_exists('e')) {
    /**
     * Encode HTML special characters in a string.
     */
    function e(\BackedEnum|DeferringDisplayableValue|\Stringable|float|Htmlable|int|string|null $value, bool $doubleEncode = true): string
    {
        if ($value instanceof DeferringDisplayableValue) {
            $value = $value->resolveDisplayableValue();
        }

        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
    }
}

if (! function_exists('env')) {
    /**
     * Gets the value of an environment variable.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (! function_exists('filled')) {
    /**
     * Determine if a value is "filled".
     *
     * @phpstan-assert-if-true !=null|'' $value
     *
     * @phpstan-assert-if-false !=numeric|bool $value
     *
     * @param mixed $value
     */
    function filled($value): bool
    {
        return ! blank($value);
    }
}

if (! function_exists('fluent')) {
    /**
     * Create a Fluent object from the given value.
     *
     * @param null|iterable|object $value
     */
    function fluent($value = null): Fluent
    {
        return new Fluent($value ?? []);
    }
}

if (! function_exists('literal')) {
    /**
     * Return a new literal or anonymous object using named arguments.
     *
     * @return mixed
     */
    function literal(...$arguments)
    {
        if (count($arguments) === 1 && array_is_list($arguments)) {
            return $arguments[0];
        }

        return (object) $arguments;
    }
}

if (! function_exists('object_get')) {
    /**
     * Get an item from an object using "dot" notation.
     *
     * @template TValue of object
     *
     * @param TValue $object
     * @param null|string $key
     * @param mixed $default
     * @return ($key is empty ? TValue : mixed)
     */
    function object_get($object, $key, $default = null)
    {
        if (is_null($key) || trim($key) === '') {
            return $object;
        }

        foreach (explode('.', $key) as $segment) {
            if (! is_object($object) || ! isset($object->{$segment})) {
                return value($default);
            }

            $object = $object->{$segment};
        }

        return $object;
    }
}

if (! function_exists('environment')) {
    /**
     * Get the environment instance or check if the environment matches.
     *
     * @throws TypeError
     */
    function environment(mixed ...$environments): bool|Environment
    {
        $container = Container::getInstance();
        $environment = $container->has(Environment::class)
            ? $container->make(Environment::class)
            : new Environment();

        if (count($environments) > 0) {
            return $environment->is(...$environments);
        }

        return $environment;
    }
}

if (! function_exists('once')) {
    /**
     * Ensures a callable is only called once, and returns the result on subsequent calls.
     *
     * @template  TReturnType
     *
     * @param callable(): TReturnType $callback
     * @return TReturnType
     */
    function once(callable $callback)
    {
        $onceable = Onceable::tryFromTrace(
            debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2),
            $callback,
        );

        return $onceable ? Once::instance()->value($onceable) : call_user_func($callback);
    }
}

if (! function_exists('optional')) {
    /**
     * Provide access to optional objects.
     *
     * @template TValue
     * @template TReturn
     *
     * @param TValue $value
     * @param null|(callable(TValue): TReturn) $callback
     * @return ($callback is null ? \Hypervel\Support\Optional : ($value is null ? null : TReturn))
     */
    function optional($value = null, ?callable $callback = null)
    {
        if (is_null($callback)) {
            return new Optional($value);
        }

        if (! is_null($value)) {
            return $callback($value);
        }

        return null;
    }
}

if (! function_exists('preg_replace_array')) {
    /**
     * Replace a given pattern with each value in the array in sequentially.
     *
     * @param string $pattern
     * @param string $subject
     */
    function preg_replace_array($pattern, array $replacements, $subject): string
    {
        return preg_replace_callback($pattern, function () use (&$replacements) {
            foreach ($replacements as $value) {
                return array_shift($replacements);
            }
        }, $subject);
    }
}

if (! function_exists('retry')) {
    /**
     * Retry an operation a given number of times.
     *
     * @template TValue
     *
     * @param array<int, int>|int $times
     * @param callable(int): TValue $callback
     * @param \Closure(int, \Throwable): int|int $sleepMilliseconds
     * @param null|(callable(\Throwable): bool) $when
     * @return TValue
     *
     * @throws \Throwable
     */
    function retry($times, callable $callback, $sleepMilliseconds = 0, $when = null)
    {
        $attempts = 0;

        $backoff = [];

        if (is_array($times)) {
            $backoff = $times;

            $times = count($times) + 1;
        }

        while (true) {
            ++$attempts;
            --$times;

            try {
                return $callback($attempts);
            } catch (Throwable $e) {
                if ($times < 1 || ($when && ! $when($e))) {
                    throw $e;
                }

                $sleepMilliseconds = $backoff[$attempts - 1] ?? $sleepMilliseconds;

                if ($sleepMilliseconds) {
                    Sleep::usleep(value($sleepMilliseconds, $attempts, $e) * 1000);
                }
            }
        }
    }
}

if (! function_exists('str')) {
    /**
     * Get a new stringable object from the given string.
     *
     * @param null|string $string
     * @return ($string is null ? object : \Hypervel\Support\Stringable)
     */
    function str($string = null)
    {
        if (func_num_args() === 0) {
            return new class {
                public function __call($method, $parameters)
                {
                    return Str::$method(...$parameters);
                }

                public function __toString()
                {
                    return '';
                }
            };
        }

        return new SupportStringable($string);
    }
}

if (! function_exists('tap')) {
    /**
     * Call the given Closure with the given value then return the value.
     *
     * @template TValue
     *
     * @param TValue $value
     * @param null|(callable(TValue): mixed) $callback
     * @return ($callback is null ? \Hypervel\Support\HigherOrderTapProxy : TValue)
     */
    function tap($value, $callback = null)
    {
        if (is_null($callback)) {
            return new HigherOrderTapProxy($value);
        }

        $callback($value);

        return $value;
    }
}

if (! function_exists('throw_if')) {
    /**
     * Throw the given exception if the given condition is true.
     *
     * @template TValue
     * @template TParams of mixed
     * @template TException of \Throwable
     * @template TExceptionValue of TException|class-string<TException>|string
     *
     * @param TValue $condition
     * @param Closure(TParams): TExceptionValue|TExceptionValue $exception
     * @param TParams ...$parameters
     * @return ($condition is true ? never : ($condition is non-empty-mixed ? never : TValue))
     *
     * @throws TException
     */
    function throw_if($condition, $exception = 'RuntimeException', ...$parameters)
    {
        if ($condition) {
            if ($exception instanceof Closure) {
                $exception = $exception(...$parameters);
            }

            if (is_string($exception) && class_exists($exception)) {
                $exception = new $exception(...$parameters);
            }

            throw is_string($exception) ? new RuntimeException($exception) : $exception;
        }

        return $condition;
    }
}

if (! function_exists('throw_unless')) {
    /**
     * Throw the given exception unless the given condition is true.
     *
     * @template TValue
     * @template TParams of mixed
     * @template TException of \Throwable
     * @template TExceptionValue of TException|class-string<TException>|string
     *
     * @param TValue $condition
     * @param Closure(TParams): TExceptionValue|TExceptionValue $exception
     * @param TParams ...$parameters
     * @return ($condition is false ? never : ($condition is non-empty-mixed ? TValue : never))
     *
     * @throws TException
     */
    function throw_unless($condition, $exception = 'RuntimeException', ...$parameters)
    {
        throw_if(! $condition, $exception, ...$parameters);

        return $condition;
    }
}

if (! function_exists('trait_uses_recursive')) {
    /**
     * Returns all traits used by a trait and its traits.
     *
     * @param object|string $trait
     * @return array<string, string>
     */
    function trait_uses_recursive($trait): array
    {
        $traits = class_uses($trait) ?: [];

        foreach ($traits as $trait) {
            $traits += trait_uses_recursive($trait);
        }

        return $traits;
    }
}

if (! function_exists('transform')) {
    /**
     * Transform the given value if it is present.
     *
     * @template TValue
     * @template TReturn
     * @template TDefault
     *
     * @param TValue $value
     * @param callable(TValue): TReturn $callback
     * @param callable(TValue): TDefault|TDefault $default
     * @return ($value is empty ? TDefault : TReturn)
     */
    function transform($value, callable $callback, $default = null)
    {
        if (filled($value)) {
            return $callback($value);
        }

        if (is_callable($default)) {
            return $default($value);
        }

        return $default;
    }
}

if (! function_exists('windows_os')) {
    /**
     * Determine whether the current environment is Windows based.
     */
    function windows_os(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}

if (! function_exists('with')) {
    /**
     * Return the given value, optionally passed through the given callback.
     *
     * @template TValue
     * @template TReturn
     *
     * @param TValue $value
     * @param null|(callable(TValue): (TReturn)) $callback
     * @return ($callback is null ? TValue : TReturn)
     */
    function with($value, ?callable $callback = null)
    {
        return is_null($callback) ? $value : $callback($value);
    }
}
