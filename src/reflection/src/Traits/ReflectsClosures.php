<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Closure;
use Hypervel\Support\Collection;
use Hypervel\Support\Reflector;
use ReflectionException;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

trait ReflectsClosures
{
    /**
     * Get the class name of the first parameter of the given Closure.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     */
    protected function firstClosureParameterType(Closure $closure): string
    {
        $types = array_values($this->closureParameterTypes($closure));

        if (! $types) {
            throw new RuntimeException('The given Closure has no parameters.');
        }

        if ($types[0] === null) {
            throw new RuntimeException('The first parameter of the given Closure is missing a type hint.');
        }

        return $types[0];
    }

    /**
     * Get the class names of the first parameter of the given Closure, including union types.
     *
     * @return list<class-string>
     *
     * @throws ReflectionException
     * @throws RuntimeException
     */
    protected function firstClosureParameterTypes(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);

        /** @var list<array<class-string>> $types */
        $types = Collection::make($reflection->getParameters())->mapWithKeys(function ($parameter) {
            if ($parameter->isVariadic()) {
                return [$parameter->getName() => null];
            }

            return [$parameter->getName() => Reflector::getParameterClassNames($parameter)];
        })->filter()->values()->all();

        if (empty($types)) {
            throw new RuntimeException('The given Closure has no parameters.');
        }

        if (empty($types[0])) {
            throw new RuntimeException('The first parameter of the given Closure is missing a type hint.');
        }

        return $types[0];
    }

    /**
     * Get the class names / types of the parameters of the given Closure.
     *
     * @return array<string, null|string>
     */
    protected function closureParameterTypes(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);

        return Collection::make($reflection->getParameters())->mapWithKeys(function ($parameter) {
            if ($parameter->isVariadic()) {
                return [$parameter->getName() => null];
            }

            return [$parameter->getName() => Reflector::getParameterClassName($parameter)];
        })->all();
    }

    /**
     * Get the class names / types of the return type of the given Closure.
     *
     * @return list<class-string>
     */
    protected function closureReturnTypes(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);

        if ($reflection->getReturnType() === null
            || $reflection->getReturnType() instanceof ReflectionIntersectionType) {
            return [];
        }

        $types = $reflection->getReturnType() instanceof ReflectionUnionType
            ? $reflection->getReturnType()->getTypes()
            : [$reflection->getReturnType()];

        /** @var Collection<int, ReflectionNamedType> $namedTypes */
        $namedTypes = Collection::make($types)
            ->filter(fn ($type) => $type instanceof ReflectionNamedType);

        return $namedTypes
            ->reject(fn (ReflectionNamedType $type) => $type->isBuiltin())
            ->reject(fn (ReflectionNamedType $type) => in_array($type->getName(), ['static', 'self']))
            ->map(fn (ReflectionNamedType $type) => $type->getName())
            ->values()
            ->all();
    }
}
