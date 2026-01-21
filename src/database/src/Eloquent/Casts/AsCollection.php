<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

use Hypervel\Database\Contracts\Eloquent\Castable;
use Hypervel\Database\Contracts\Eloquent\CastsAttributes;
use Hyperf\Stringable\Str;
use Hypervel\Support\Collection;
use InvalidArgumentException;

class AsCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @param array $arguments
     * @return CastsAttributes<Collection<array-key, mixed>, iterable>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments) implements CastsAttributes
        {
            public function __construct(protected array $arguments)
            {
                $this->arguments = array_pad(array_values($this->arguments), 2, '');
            }

            public function get($model, $key, $value, $attributes)
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                $collectionClass = empty($this->arguments[0]) ? Collection::class : $this->arguments[0];

                if (! is_a($collectionClass, Collection::class, true)) {
                    throw new InvalidArgumentException('The provided class must extend [' . Collection::class . '].');
                }

                if (! is_array($data)) {
                    return null;
                }

                $instance = new $collectionClass($data);

                if (! isset($this->arguments[1]) || ! $this->arguments[1]) {
                    return $instance;
                }

                if (is_string($this->arguments[1])) {
                    $this->arguments[1] = Str::parseCallback($this->arguments[1]);
                }

                return is_callable($this->arguments[1])
                    ? $instance->map($this->arguments[1])
                    : $instance->mapInto($this->arguments[1][0]);
            }

            public function set($model, $key, $value, $attributes)
            {
                return [$key => Json::encode($value)];
            }
        };
    }

    /**
     * Specify the type of object each item in the collection should be mapped to.
     *
     * @param array{class-string, string}|class-string $map
     */
    public static function of(array|string $map): string
    {
        return static::using('', $map);
    }

    /**
     * Specify the collection type for the cast.
     *
     * @param class-string $class
     * @param array{class-string, string}|class-string|null $map
     */
    public static function using(string $class, array|string|null $map = null): string
    {
        if (is_array($map) && is_callable($map)) {
            $map = $map[0] . '@' . $map[1];
        }

        return static::class . ':' . implode(',', [$class, $map]);
    }
}
