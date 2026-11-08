<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Reflection\ForwardedModelMethodReflection;
use Hypervel\PHPStan\Reflection\MacroRegistrationMethodReflection;
use LogicException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Describe Eloquent builder methods forwarded through models.
 */
#[AutowiredService]
class ForwardedModelMethodExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a forwarded model method extension.
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ModelScopeMethodResolver $scopeMethods,
        private readonly BuilderMacroResolver $builderMacros,
        private readonly BuilderDependencies $builderDependencies,
        private readonly BuilderHelper $builderHelper,
        private readonly MacroMethodsClassReflectionExtension $macroMethods,
    ) {
        $this->scope = new OutOfClassScope;
    }

    /**
     * Determine whether the model exposes the forwarded builder method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->resolveMethod($classReflection, $methodName) !== null;
    }

    /**
     * Return the forwarded builder method.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->resolveMethod($classReflection, $methodName)
            ?? throw new LogicException(sprintf(
                'Forwarded model method [%s::%s] was not resolved.',
                $classReflection->getName(),
                $methodName,
            ));
    }

    /**
     * Resolve and cache a builder method forwarded through a model.
     */
    private function resolveMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        if (! $this->isModel($classReflection)) {
            return null;
        }

        // Keep the exact spelling: builder macro names are case-sensitive.
        $cacheKey = $classReflection->getCacheKey() . ':' . $methodName;

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $method = null;

        if (! $this->hasNativeOrDocumentedMethod($classReflection, $methodName)) {
            // Keep static, so PHPStan binds it to the called model.
            $builderType = $this->builderHelper->determineBuilderClass($classReflection->getName(), new StaticType($classReflection));

            foreach ($builderType->getObjectClassReflections() as $builderClass) {
                $this->builderDependencies->track($classReflection, $builderClass);
            }

            $scopeMethod = $this->scopeMethods->resolve($classReflection, $methodName);

            // Native builder methods and local and global macros run before named scopes.
            if (($scopeMethod === null
                    || $this->builderMethodTakesPrecedence($builderType, $methodName)
                    || $this->builderMacros->hasMacro($classReflection, $methodName))
                && $builderType->hasMethod($methodName)->yes()) {
                $builderMethod = $builderType->getMethod($methodName, $this->scope);

                // Model::__call() makes an instance call, so the builder's mixin() reaches the query's and returns it.
                $method = new ForwardedModelMethodReflection(
                    $classReflection,
                    $builderMethod,
                    $methodName === 'mixin' && $builderMethod->getPrototype() instanceof MacroRegistrationMethodReflection
                        ? $builderType
                        : null,
                );
            }
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Determine whether an active builder owns a native method or global macro.
     */
    private function builderMethodTakesPrecedence(Type $builderType, string $methodName): bool
    {
        foreach ($builderType->getObjectClassReflections() as $builderClass) {
            if ($builderClass->hasNativeMethod($methodName) || $this->macroMethods->hasMethod($builderClass, $methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a class owns a native or documented method.
     */
    private function hasNativeOrDocumentedMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if ($classReflection->hasNativeMethod($methodName)) {
            return true;
        }

        foreach (array_keys($classReflection->getMethodTags()) as $documentedMethod) {
            if (strcasecmp($documentedMethod, $methodName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the class is an Eloquent model.
     */
    private function isModel(ClassReflection $classReflection): bool
    {
        return $classReflection->getName() === Model::class
            || $classReflection->isSubclassOfClass($this->reflectionProvider->getClass(Model::class));
    }
}
