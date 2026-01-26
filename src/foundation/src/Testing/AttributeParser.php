<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Foundation\Testing\Contracts\Attributes\Resolvable;
use Hypervel\Foundation\Testing\Contracts\Attributes\TestingFeature;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Parses PHPUnit test case attributes for testing features.
 */
class AttributeParser
{
    /**
     * Parse attributes for a class.
     *
     * @param class-string $className
     * @return array<int, array{key: class-string, instance: object}>
     */
    public static function forClass(string $className): array
    {
        $attributes = [];
        $reflection = new ReflectionClass($className);

        foreach ($reflection->getAttributes() as $attribute) {
            if (! static::validAttribute($attribute->getName())) {
                continue;
            }

            [$name, $instance] = static::resolveAttribute($attribute);

            if ($name !== null && $instance !== null) {
                $attributes[] = ['key' => $name, 'instance' => $instance];
            }
        }

        $parent = $reflection->getParentClass();

        if ($parent !== false && $parent->isSubclassOf(TestCase::class)) {
            $attributes = [...static::forClass($parent->getName()), ...$attributes];
        }

        return $attributes;
    }

    /**
     * Parse attributes for a method.
     *
     * @param class-string $className
     * @return array<int, array{key: class-string, instance: object}>
     */
    public static function forMethod(string $className, string $methodName): array
    {
        $attributes = [];

        foreach ((new ReflectionMethod($className, $methodName))->getAttributes() as $attribute) {
            if (! static::validAttribute($attribute->getName())) {
                continue;
            }

            [$name, $instance] = static::resolveAttribute($attribute);

            if ($name !== null && $instance !== null) {
                $attributes[] = ['key' => $name, 'instance' => $instance];
            }
        }

        return $attributes;
    }

    /**
     * Validate if a class is a valid testing attribute.
     *
     * @param class-string|object $class
     */
    public static function validAttribute(object|string $class): bool
    {
        if (\is_string($class) && ! class_exists($class)) {
            return false;
        }

        $implements = class_implements($class);

        return isset($implements[TestingFeature::class])
            || isset($implements[Resolvable::class]);
    }

    /**
     * Resolve the given attribute.
     *
     * @return array{0: null|class-string, 1: null|object}
     */
    protected static function resolveAttribute(ReflectionAttribute $attribute): array
    {
        /** @var array{0: null|class-string, 1: null|object} */
        return rescue(static function () use ($attribute): array { // @phpstan-ignore argument.unresolvableType
            $instance = isset(class_implements($attribute->getName())[Resolvable::class])
                ? transform($attribute->newInstance(), static fn (Resolvable $instance) => $instance->resolve())
                : $attribute->newInstance();

            if ($instance === null) {
                return [null, null];
            }

            return [$instance::class, $instance];
        }, [null, null], false);
    }
}
