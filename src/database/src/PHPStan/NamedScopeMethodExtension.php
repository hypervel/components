<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use LogicException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;

/**
 * Describe model scopes exposed through models, builders, and relations.
 */
class NamedScopeMethodExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a named scope method extension.
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ModelScopeMethodResolver $scopeMethods,
    ) {
        $this->scope = new OutOfClassScope;
    }

    /**
     * Determine whether the receiver exposes the named scope.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->resolveMethod($classReflection, $methodName) !== null;
    }

    /**
     * Return the named scope method.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->resolveMethod($classReflection, $methodName)
            ?? throw new LogicException(sprintf(
                'Named scope method [%s::%s] was not resolved.',
                $classReflection->getName(),
                $methodName,
            ));
    }

    /**
     * Resolve and cache a named scope for an Eloquent receiver.
     */
    private function resolveMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $isModelHost = $this->isClassOrSubclassOf($classReflection, Model::class);
        $isBuilderHost = ! $isModelHost
            && $this->isClassOrSubclassOf($classReflection, Builder::class);
        $isRelationHost = ! $isModelHost && ! $isBuilderHost
            && $this->isClassOrSubclassOf($classReflection, Relation::class);

        if (! $isModelHost && ! $isBuilderHost && ! $isRelationHost) {
            return null;
        }

        $cacheKey = $classReflection->getCacheKey() . ':' . strtolower($methodName);

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $host = $this->host($classReflection, $isModelHost, $isBuilderHost);

        if ($host === null) {
            return null;
        }

        [$modelClass, $receiverType, $queryType, $static] = $host;
        $scopeMethod = $this->scopeMethods->resolve($modelClass, $methodName);
        $method = null;

        if ($scopeMethod !== null && ! $this->nativeMethodTakesPrecedence(
            $classReflection,
            $methodName,
            $queryType,
            $static,
        )) {
            $method = new NamedScopeMethodReflection(
                $classReflection,
                $modelClass,
                $scopeMethod,
                $methodName,
                $receiverType,
                $queryType,
                $static,
            );
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Resolve the model, receiver and query types, and static mode for an Eloquent host.
     *
     * @return null|array{ClassReflection, Type, Type, bool}
     */
    private function host(
        ClassReflection $classReflection,
        bool $isModelHost,
        bool $isBuilderHost,
    ): ?array {
        if ($isModelHost) {
            $queryType = $this->activeBuilderType($classReflection);

            return [
                $classReflection,
                $queryType,
                $queryType,
                true,
            ];
        }

        $templateName = $isBuilderHost ? 'TModel' : 'TRelatedModel';

        $modelType = $this->templateType(
            $classReflection,
            $isBuilderHost ? Builder::class : Relation::class,
            $templateName,
        );
        $modelClasses = $modelType->getObjectClassReflections();

        if (count($modelClasses) !== 1) {
            return null;
        }

        $receiverType = new ThisType($classReflection);

        return [
            $modelClasses[0],
            $receiverType,
            $isBuilderHost ? $receiverType : $this->activeBuilderType($modelClasses[0]),
            false,
        ];
    }

    /**
     * Determine whether a native method wins over dynamic scope dispatch.
     */
    private function nativeMethodTakesPrecedence(
        ClassReflection $classReflection,
        string $methodName,
        Type $queryType,
        bool $static,
    ): bool {
        if ($classReflection->hasNativeMethod($methodName)
            && (! $static || $classReflection->getNativeMethod($methodName)->isPublic())) {
            return true;
        }

        // Model and relation hosts also yield to methods owned by their active builder.
        foreach ($queryType->getObjectClassReflections() as $builderClass) {
            if ($builderClass->hasNativeMethod($methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the active query builder type for a model.
     *
     * Bind static here because scope returns are compared before PHPStan can rebind the synthesized receiver.
     */
    private function activeBuilderType(ClassReflection $modelClass): Type
    {
        $variants = $modelClass->getMethod('query', $this->scope)->getVariants();

        return ModelScopeTypeResolver::bindToModel($variants[0]->getReturnType(), $modelClass);
    }

    /**
     * Return an active template type from a class ancestor.
     *
     * @param class-string $ancestorClass
     */
    private function templateType(
        ClassReflection $classReflection,
        string $ancestorClass,
        string $templateName,
    ): Type {
        $type = $classReflection
            ->getAncestorWithClassName($ancestorClass)
            ?->getActiveTemplateTypeMap()
            ->getType($templateName);

        return $type ?? throw new LogicException(sprintf(
            'Template type [%s] is not available for [%s].',
            $templateName,
            $classReflection->getName(),
        ));
    }

    /**
     * Determine whether a class is or extends the target class.
     *
     * @param class-string $targetClass
     */
    private function isClassOrSubclassOf(ClassReflection $classReflection, string $targetClass): bool
    {
        return $classReflection->getName() === $targetClass
            || $classReflection->isSubclassOfClass($this->reflectionProvider->getClass($targetClass));
    }
}
