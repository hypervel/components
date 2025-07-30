<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

/**
 * @template TFactory of Factory
 */
trait HasFactory
{
    /**
     * Get a new factory instance for the model.
     *
     * @param null|array<string, mixed>|(callable(array<string, mixed>, null|static): array<string, mixed>)|int $count
     * @param array<string, mixed>|(callable(array<string, mixed>, null|static): array<string, mixed>) $state
     * @return TFactory
     */
    public static function factory(null|array|callable|int $count = null, array|callable $state = []): Factory
    {
        $factory = isset(static::$factory)
            ? static::$factory::new()
            : Factory::factoryForModel(static::class);

        return $factory->count(is_numeric($count) ? $count : null)
            ->state(is_callable($count) || is_array($count) ? $count : $state);
    }
}
