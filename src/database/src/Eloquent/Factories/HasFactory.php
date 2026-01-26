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
     * @param null|array<string, mixed>|(callable(array<string, mixed>, null|static): array<string, mixed>)|int $count
     * @param array<string, mixed>|(callable(array<string, mixed>, null|static): array<string, mixed>) $state
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
     * @return null|TFactory
     */
    protected static function newFactory(): ?Factory
    {
        if (isset(static::$factory)) { // @phpstan-ignore staticProperty.notFound (optional property for legacy factory pattern)
            return static::$factory::new();
        }

        return static::getUseFactoryAttribute() ?? null;
    }

    /**
     * Get the factory from the UseFactory class attribute.
     *
     * @return null|TFactory
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
