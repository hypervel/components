<?php

declare(strict_types=1);

namespace Hypervel\Database\PHPStan;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Relations\Relation;
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
 * Resolve declared builders while preserving Eloquent and relation forwarding.
 */
class ForwardedBuilderMethodExtension implements MethodsClassReflectionExtension
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

    /** @var array<class-string, list<string>> */
    private array $passthru = [];

    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a forwarded builder method extension.
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ModelScopeMethodResolver $scopeMethods,
    ) {
        $this->scope = new OutOfClassScope;
    }

    /**
     * Determine whether the class exposes the forwarded builder method.
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
                'Forwarded builder method [%s::%s] was not resolved.',
                $classReflection->getName(),
                $methodName,
            ));
    }

    /**
     * Resolve and cache a forwarded builder method.
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
     * Resolve a query method forwarded by an Eloquent builder.
     */
    private function resolveEloquentBuilderMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): ?MethodReflection {
        $modelType = $this->templateType($classReflection, EloquentBuilder::class, 'TModel');

        if ($this->hasNamedScope($modelType, $methodName)) {
            return null;
        }

        $queryType = $this->queryBuilderType($classReflection, $modelType);
        $queryClasses = $queryType->getObjectClassReflections();

        if (count($queryClasses) !== 1 || ! $queryClasses[0]->hasNativeMethod($methodName)) {
            return null;
        }

        $method = $queryType->getMethod($methodName, $this->scope);

        if (! $method->isPublic()) {
            return null;
        }

        if (in_array(strtolower($methodName), $this->passthruMethods($classReflection), strict: true)) {
            return $method;
        }

        // Eloquent discards ordinary forwarded results, regardless of their declared type.
        return new ForwardedFluentMethodReflection($classReflection, $method);
    }

    /**
     * Resolve a builder method forwarded by a relation.
     */
    private function resolveRelationMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $normalizedMethodName = strtolower($methodName);
        $relatedType = $this->templateType($classReflection, Relation::class, 'TRelatedModel');
        $builderType = $this->eloquentBuilderType($relatedType);
        $builderClasses = $builderType->getObjectClassReflections();

        if (count($builderClasses) !== 1) {
            return null;
        }

        $eloquentBuilder = $builderClasses[0];

        if ($eloquentBuilder->hasNativeMethod($methodName)) {
            $method = $builderType->getMethod($methodName, $this->scope);

            if (! $method->isPublic()) {
                return null;
            }

            if (in_array($normalizedMethodName, self::RELATION_NON_DECORATED_METHODS, strict: true)
                || ! $this->returnsStatic($eloquentBuilder->getNativeMethod($methodName))) {
                return $method;
            }

            return new ForwardedFluentMethodReflection($classReflection, $method);
        }

        if ($this->hasNamedScope($relatedType, $methodName)) {
            return null;
        }

        $method = $this->resolveEloquentBuilderMethod($eloquentBuilder, $methodName);

        if ($method !== null) {
            return $method instanceof ForwardedFluentMethodReflection
                ? new ForwardedFluentMethodReflection($classReflection, $method)
                : $method;
        }

        if (! in_array($normalizedMethodName, self::RELATION_DOCUMENTED_FLUENT_METHODS, strict: true)) {
            return null;
        }

        $method = $builderType->getMethod($methodName, $this->scope);

        return new ForwardedFluentMethodReflection($classReflection, $method);
    }

    /**
     * Return the Eloquent builder passthru methods.
     *
     * @return list<string>
     */
    private function passthruMethods(ClassReflection $builderClass): array
    {
        $className = $builderClass->getName();

        if (! isset($this->passthru[$className])) {
            /** @var list<string> $passthru */
            $passthru = $builderClass->getNativeReflection()
                ->getDefaultProperties()['passthru'];

            $this->passthru[$className] = $passthru;
        }

        return $this->passthru[$className];
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
     * Resolve the related model's declared builder, retaining late-static model types.
     */
    private function eloquentBuilderType(Type $modelType): Type
    {
        $modelClasses = $modelType->getObjectClassReflections();

        if (count($modelClasses) === 1) {
            $modelClass = $modelClasses[0];
            $variants = $modelClass->getMethod('query', $this->scope)->getVariants();

            return ModelScopeTypeResolver::bindToModel($variants[0]->getReturnType(), $modelClass);
        }

        return new GenericObjectType(EloquentBuilder::class, [$modelType]);
    }

    /**
     * Bind forwarded query signatures to model rows without changing raw-query types.
     */
    private function queryBuilderType(ClassReflection $builderClass, Type $modelType): Type
    {
        $variants = $builderClass->getNativeMethod('getQuery')->getVariants();
        $queryType = $variants[0]->getReturnType();
        $queryClasses = $queryType->getObjectClassReflections();

        if (count($queryClasses) !== 1) {
            return $queryType;
        }

        $queryClass = $queryClasses[0];
        $templates = $queryClass->getTemplateTypeMap();

        if ($templates->getType('TKey') === null || $templates->getType('TValue') === null) {
            return $queryType;
        }

        $types = $queryClass->getActiveTemplateTypeMap()->map(
            static fn (string $name, Type $type): Type => match ($name) {
                'TKey' => new IntegerType,
                'TValue' => $modelType,
                default => $type,
            },
        );

        return new GenericObjectType($queryClass->getName(), $queryClass->typeMapToList($types));
    }

    /**
     * Determine whether a named scope owns dispatch before query forwarding.
     */
    private function hasNamedScope(Type $modelType, string $methodName): bool
    {
        $modelClasses = $modelType->getObjectClassReflections();

        return count($modelClasses) === 1
            && $this->scopeMethods->resolve($modelClasses[0], $methodName) !== null;
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
