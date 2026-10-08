<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\GetsCast;
use Hypervel\Data\Casts\BuiltinTypeCast;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Casts\CastableCast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Casts\EnumCast;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Types\NamedType;

/**
 * The casts, normalizers and automatic lazy attributes resolved for one creation operation.
 *
 * Everything an operation creates shares one instance, including nested values and deferred lazy
 * collection items, so each extension is resolved once and the same instance handles every matching value.
 */
class CreationExtensions implements Transient
{
    /** @var array<int, Cast> */
    protected array $attributeCasts = [];

    /** @var array<class-string<Cast>, Cast> */
    protected array $casts = [];

    /** @var array<class-string, list<Normalizer>> */
    protected array $classNormalizers = [];

    /** @var array<class-string<Normalizer>, Normalizer> */
    protected array $normalizers = [];

    /** @var array<int, AutoLazy> */
    protected array $autoLazies = [];

    /** @var array<string, CastableCast> */
    protected array $castableCasts = [];

    /** @var array<string, DateTimeInterfaceCast> */
    protected array $dateCasts = [];

    /** @var array<string, EnumCast> */
    protected array $enumCasts = [];

    /** @var array<string, BuiltinTypeCast> */
    protected array $builtinCasts = [];

    /**
     * Create the extensions for one creation operation.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly DataConfig $config,
    ) {
    }

    /**
     * Get the ordered custom casts applicable to a property.
     *
     * @return list<Cast>
     */
    public function propertyCasts(DataProperty $property, CreationContext $context): array
    {
        $casts = [];

        if ($property->cast !== null) {
            $key = spl_object_id($property->cast);

            if (! isset($this->attributeCasts[$key])) {
                /** @var GetsCast $attribute */
                $attribute = $property->cast->newInstance();
                $this->attributeCasts[$key] = $attribute->get();
            }

            $casts[] = $this->attributeCasts[$key];
        }

        foreach ($context->casts as $baseType => $cast) {
            if ($property->type->findAcceptedTypeForBaseType($baseType) !== null) {
                $casts[] = $this->cast($cast);
            }
        }

        foreach ($property->configuredCasts as $cast) {
            $casts[] = $this->cast($cast);
        }

        return $casts;
    }

    /**
     * Get the factory and configured casts for one plain iterable type's items.
     *
     * @return list<Cast>
     */
    public function itemCasts(DataProperty $property, NamedType $type, CreationContext $context): array
    {
        $casts = [];

        foreach ($context->casts as $baseType => $cast) {
            if ($type->iterableItemType->findAcceptedTypeForBaseType($baseType) !== null) {
                $casts[] = $this->cast($cast);
            }
        }

        foreach ($property->configuredItemCasts[$type->name] ?? [] as $cast) {
            $casts[] = $this->cast($cast);
        }

        return $casts;
    }

    /**
     * Get the custom normalizers for one data class.
     *
     * @return list<Normalizer>
     */
    public function normalizers(DataClass $dataClass, CreationContext $context): array
    {
        // Avoid building a lookup on the common path with no custom normalizers.
        if ($context->normalizers === []
            && $this->config->normalizers === []
            && ! $dataClass->hasLifecycleMethod('normalizers')
        ) {
            return [];
        }

        if (isset($this->classNormalizers[$dataClass->name])) {
            return $this->classNormalizers[$dataClass->name];
        }

        $normalizers = [];

        if ($dataClass->hasLifecycleMethod('normalizers')) {
            $class = $dataClass->name;
            /** @var list<class-string<Normalizer>|Normalizer> $normalizers */
            $normalizers = $this->container->call($class::normalizers(...));
        }

        array_push($normalizers, ...$context->normalizers, ...$this->config->normalizers);

        foreach ($normalizers as $index => $normalizer) {
            if (! $normalizer instanceof Normalizer) {
                /** @var Normalizer $resolved */
                $resolved = $this->normalizers[$normalizer] ??= $this->container->make($normalizer);
                $normalizers[$index] = $resolved;
            }
        }

        return $this->classNormalizers[$dataClass->name] = array_values($normalizers);
    }

    /**
     * Get the automatic lazy attribute instance for a property.
     */
    public function autoLazy(DataProperty $property): AutoLazy
    {
        $attribute = $property->autoLazy;

        /** @var AutoLazy */
        return $this->autoLazies[spl_object_id($attribute)] ??= $attribute->newInstance();
    }

    /**
     * Get the cast for a Castable class.
     *
     * @param class-string<Castable> $class
     */
    public function castableCast(string $class): CastableCast
    {
        return $this->castableCasts[$class] ??= new CastableCast($class);
    }

    /**
     * Get the built-in cast for a date type.
     */
    public function dateCast(string $type): DateTimeInterfaceCast
    {
        return $this->dateCasts[$type] ??= new DateTimeInterfaceCast(type: $type);
    }

    /**
     * Get the built-in cast for a backed enum type.
     */
    public function enumCast(string $type): EnumCast
    {
        return $this->enumCasts[$type] ??= new EnumCast($type);
    }

    /**
     * Get the built-in cast for a scalar or array type.
     */
    public function builtinCast(string $type): BuiltinTypeCast
    {
        return $this->builtinCasts[$type] ??= new BuiltinTypeCast($type);
    }

    /**
     * Resolve one cast once for the operation.
     *
     * @param Cast|class-string<Cast> $cast
     */
    protected function cast(Cast|string $cast): Cast
    {
        if ($cast instanceof Cast) {
            return $cast;
        }

        /** @var Cast */
        return $this->casts[$cast] ??= $this->container->make($cast);
    }
}
