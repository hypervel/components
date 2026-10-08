<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Resolve the builder macros that model traits add through their global scopes.
 *
 * Packages map each trait to an interface declaring its macros through the
 * eloquentBuilderMacros parameter. A generic interface receives the model type.
 */
class BuilderMacroResolver
{
    private readonly OutOfClassScope $scope;

    /**
     * Create a builder macro resolver.
     *
     * @param array<class-string, class-string> $macros
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $macros,
    ) {
        $this->scope = new OutOfClassScope;
    }

    /**
     * Determine whether the model's traits add the named builder macro.
     */
    public function hasMacro(ClassReflection $modelClass, string $methodName): bool
    {
        return $this->macroInterface($modelClass, $methodName) !== null;
    }

    /**
     * Resolve a trait macro called on a builder for the given model type.
     */
    public function resolve(ClassReflection $builderClass, Type $modelType, string $methodName): ?MethodReflection
    {
        $modelClasses = $modelType->getObjectClassReflections();

        if (count($modelClasses) !== 1) {
            return null;
        }

        $macroInterface = $this->macroInterface($modelClasses[0], $methodName);

        if ($macroInterface === null) {
            return null;
        }

        $interfaceType = $macroInterface->isGeneric()
            ? new GenericObjectType($macroInterface->getName(), [$modelType])
            : new ObjectType($macroInterface->getName());
        $method = $interfaceType->getMethod($methodName, $this->scope);

        // Fluent macros return the builder they run on.
        return $macroInterface->getNativeMethod($methodName)->getVariants()[0]->getReturnType() instanceof StaticType
            ? new ForwardedFluentMethodReflection($builderClass, $method)
            : $method;
    }

    /**
     * Return the interface declaring a macro added by one of the model's traits.
     */
    private function macroInterface(ClassReflection $modelClass, string $methodName): ?ClassReflection
    {
        $traits = null;

        foreach ($this->macros as $trait => $interface) {
            $interfaceClass = $this->reflectionProvider->getClass($interface);

            // Builder::hasMacro() matches local macro names exactly.
            if (! $interfaceClass->hasNativeMethod($methodName)
                || $interfaceClass->getNativeMethod($methodName)->getName() !== $methodName) {
                continue;
            }

            $traits ??= $modelClass->getTraits(true);

            if (isset($traits[$trait])) {
                return $interfaceClass;
            }
        }

        return null;
    }
}
