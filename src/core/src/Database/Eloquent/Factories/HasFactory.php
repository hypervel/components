<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Factories;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use ReflectionClass;

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
    public static function factory(array|callable|int|null $count = null, array|callable $state = []): Factory
    {
        $factory = static::newFactory() ?? Factory::factoryForModel(static::class);

        return $factory->count(is_numeric($count) ? $count : null)
            ->state(is_callable($count) || is_array($count) ? $count : $state);
    }

    /**
     * Create a new factory instance for the model.
     *
     * Resolution order:
     * 1. Static $factory property on the model
     * 2. #[UseFactory] attribute on the model class
     *
     * @return null|TFactory
     */
    protected static function newFactory(): ?Factory
    {
        if (isset(static::$factory)) {
            return static::$factory::new();
        }

        return static::getUseFactoryAttribute();
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

            $factory = $useFactory->class::new();

            $factory->guessModelNamesUsing(fn () => static::class);

            return $factory;
        }

        return null;
    }
}
