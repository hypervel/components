<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use Hypervel\Database\Eloquent\Model;
use LogicException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

/**
 * Describe Eloquent builder methods forwarded through models.
 */
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

        $cacheKey = $classReflection->getCacheKey() . ':' . strtolower($methodName);

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $method = null;

        if (! $this->hasNativeOrDocumentedMethod($classReflection, $methodName)) {
            $builderType = $this->activeBuilderType($classReflection);
            $scopeMethod = $this->scopeMethods->resolve($classReflection, $methodName);

            if (($scopeMethod === null || $this->hasNativeMethod($builderType, $methodName))
                && $builderType->hasMethod($methodName)->yes()) {
                $method = new ForwardedModelMethodReflection(
                    $classReflection,
                    $builderType->getMethod($methodName, $this->scope),
                );
            }
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Return the active query builder type for a model.
     *
     * Preserve the raw static type so PHPStan can rebind it to the called-on model.
     */
    private function activeBuilderType(ClassReflection $classReflection): Type
    {
        $variants = $classReflection->getMethod('query', $this->scope)->getVariants();

        return $variants[0]->getReturnType();
    }

    /**
     * Determine whether an active builder owns a native method.
     */
    private function hasNativeMethod(Type $builderType, string $methodName): bool
    {
        foreach ($builderType->getObjectClassReflections() as $builderClass) {
            if ($builderClass->hasNativeMethod($methodName)) {
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
