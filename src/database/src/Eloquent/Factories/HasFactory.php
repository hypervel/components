<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use ReflectionClass;

/**
 * @template TFactory of \Hypervel\Database\Eloquent\Factories\Factory
 */
trait HasFactory
{
    /**
     * Get a new factory instance for the model.
     *
     * @param  (callable(array<string, mixed>, static|null): array<string, mixed>)|array<string, mixed>|int|null  $count
     * @param  (callable(array<string, mixed>, static|null): array<string, mixed>)|array<string, mixed>  $state
     * @return TFactory
     */
    public static function factory(callable|array|int|null $count = null, callable|array $state = []): Factory
    {
        $factory = static::newFactory() ?? Factory::factoryForModel(static::class);

        return $factory
            ->count(is_numeric($count) ? $count : null)
            ->state(is_callable($count) || is_array($count) ? $count : $state);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return TFactory|null
     */
    protected static function newFactory(): ?Factory
    {
        if (isset(static::$factory)) {
            return static::$factory::new();
        }

        return static::getUseFactoryAttribute() ?? null;
    }

    /**
     * Get the factory from the UseFactory class attribute.
     *
     * @return TFactory|null
     */
    protected static function getUseFactoryAttribute(): ?Factory
    {
        $attributes = (new ReflectionClass(static::class))
            ->getAttributes(UseFactory::class);

        if ($attributes !== []) {
            $useFactory = $attributes[0]->newInstance();

            $factory = $useFactory->factoryClass::new();

            $factory->guessModelNamesUsing(fn () => static::class);

            return $factory;
        }

        return null;
    }
}
