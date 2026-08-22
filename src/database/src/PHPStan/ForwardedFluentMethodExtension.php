<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder as QueryBuilder;
use LogicException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Preserve the receiving Eloquent builder or relation through fluent forwarding.
 */
class ForwardedFluentMethodExtension implements MethodsClassReflectionExtension
{
    /** @var list<string> */
    private const array RELATION_NON_DECORATED_METHODS = [
        'applyscopes',
        'clone',
    ];

    /** @var list<string> */
    private const array RELATION_DOCUMENTED_FLUENT_METHODS = [
        'wherecan',
        'withcan',
    ];

    /** @var list<string> */
    private readonly array $passthru;

    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a forwarded fluent method extension.
     */
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
        /** @var list<string> $passthru */
        $passthru = $this->reflectionProvider
            ->getClass(EloquentBuilder::class)
            ->getNativeReflection()
            ->getDefaultProperties()['passthru'];

        $this->passthru = $passthru;
        $this->scope = new OutOfClassScope;
    }

    /**
     * Determine whether the class exposes the forwarded fluent method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->resolveMethod($classReflection, $methodName) !== null;
    }

    /**
     * Return the forwarded fluent method.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->resolveMethod($classReflection, $methodName)
            ?? throw new LogicException(sprintf(
                'Forwarded fluent method [%s::%s] was not resolved.',
                $classReflection->getName(),
                $methodName,
            ));
    }

    /**
     * Resolve and cache a forwarded fluent method.
     */
    private function resolveMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $isEloquentHost = $this->isClassOrSubclassOf($classReflection, EloquentBuilder::class);
        $isRelationHost = ! $isEloquentHost
            && $this->isClassOrSubclassOf($classReflection, Relation::class);

        if (! $isEloquentHost && ! $isRelationHost) {
            return null;
        }

        $cacheKey = $classReflection->getCacheKey() . ':' . strtolower($methodName);

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $method = null;

        if (! $this->hasNativeOrDocumentedMethod($classReflection, $methodName)) {
            if ($isEloquentHost) {
                $method = $this->resolveEloquentBuilderMethod($classReflection, $methodName);
            } else {
                $method = $this->resolveRelationMethod($classReflection, $methodName);
            }
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Resolve a fluent method forwarded by an Eloquent builder.
     */
    private function resolveEloquentBuilderMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): ?MethodReflection {
        if (in_array(strtolower($methodName), $this->passthru, strict: true)) {
            return null;
        }

        $queryBuilder = $this->reflectionProvider->getClass(QueryBuilder::class);

        if (! $queryBuilder->hasNativeMethod($methodName)) {
            return null;
        }

        if (! $this->returnsStatic($queryBuilder->getNativeMethod($methodName))) {
            return null;
        }

        $modelType = $this->templateType($classReflection, EloquentBuilder::class, 'TModel');
        $method = $this->queryBuilderType($modelType)->getMethod($methodName, $this->scope);

        return new ForwardedFluentMethodReflection($classReflection, $method);
    }

    /**
     * Resolve a fluent method forwarded by a relation.
     */
    private function resolveRelationMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $normalizedMethodName = strtolower($methodName);
        $relatedType = $this->templateType($classReflection, Relation::class, 'TRelatedModel');
        $eloquentBuilder = $this->reflectionProvider->getClass(EloquentBuilder::class);

        if ($eloquentBuilder->hasNativeMethod($methodName)) {
            if (in_array($normalizedMethodName, self::RELATION_NON_DECORATED_METHODS, strict: true)
                || ! $this->returnsStatic($eloquentBuilder->getNativeMethod($methodName))) {
                return null;
            }

            $method = $this->eloquentBuilderType($relatedType)->getMethod($methodName, $this->scope);

            return new ForwardedFluentMethodReflection($classReflection, $method);
        }

        $queryBuilder = $this->reflectionProvider->getClass(QueryBuilder::class);

        if ($queryBuilder->hasNativeMethod($methodName)) {
            if (in_array(strtolower($methodName), $this->passthru, strict: true)
                || ! $this->returnsStatic($queryBuilder->getNativeMethod($methodName))) {
                return null;
            }

            $method = $this->queryBuilderType($relatedType)->getMethod($methodName, $this->scope);

            return new ForwardedFluentMethodReflection($classReflection, $method);
        }

        if (! in_array($normalizedMethodName, self::RELATION_DOCUMENTED_FLUENT_METHODS, strict: true)) {
            return null;
        }

        $method = $this->eloquentBuilderType($relatedType)->getMethod($methodName, $this->scope);

        return new ForwardedFluentMethodReflection($classReflection, $method);
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
     * Determine whether the class is or extends the target class.
     *
     * @param class-string $targetClass
     */
    private function isClassOrSubclassOf(ClassReflection $classReflection, string $targetClass): bool
    {
        return $classReflection->getName() === $targetClass
            || $classReflection->isSubclassOfClass($this->reflectionProvider->getClass($targetClass));
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
     * Create a generic Eloquent builder type.
     */
    private function eloquentBuilderType(Type $modelType): GenericObjectType
    {
        return new GenericObjectType(EloquentBuilder::class, [$modelType]);
    }

    /**
     * Create a generic query builder type.
     */
    private function queryBuilderType(Type $modelType): GenericObjectType
    {
        return new GenericObjectType(QueryBuilder::class, [new IntegerType, $modelType]);
    }

    /**
     * Determine whether every method variant returns static.
     */
    private function returnsStatic(MethodReflection $method): bool
    {
        foreach ($method->getVariants() as $variant) {
            if (! $variant->getReturnType() instanceof StaticType) {
                return false;
            }
        }

        return true;
    }
}
