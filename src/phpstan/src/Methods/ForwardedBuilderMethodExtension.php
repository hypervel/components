<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\PHPStan\Reflection\DynamicWhereParameterReflection;
use Hypervel\PHPStan\Reflection\EloquentBuilderMethodReflection;
use Hypervel\PHPStan\Reflection\ForwardedFluentMethodReflection;
use Hypervel\PHPStan\Reflection\ForwardedMethodReflection;
use Hypervel\PHPStan\Reflection\MacroRegistrationMethodReflection;
use LogicException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\NeverType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Resolve declared builders while preserving Eloquent and relation forwarding.
 */
#[AutowiredService]
class ForwardedBuilderMethodExtension implements MethodsClassReflectionExtension
{
    /** @var list<string> */
    private const array RELATION_NON_DECORATED_METHODS = [
        'clonewithout',
        'clonewithoutbindings',
    ];

    /** @var list<string> */
    private const array RELATION_DOCUMENTED_FLUENT_METHODS = [
        'wherecan',
        'withcan',
    ];

    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a forwarded builder method extension.
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
        $isEloquentHost = $classReflection->is(EloquentBuilder::class);
        $isRelationHost = ! $isEloquentHost && $classReflection->is(Relation::class);

        if (! $isEloquentHost && ! $isRelationHost) {
            return null;
        }

        // Keep the exact spelling: builder macro names are case-sensitive.
        $cacheKey = $classReflection->getCacheKey() . ':' . $methodName;

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $method = null;

        if (! $this->builderHelper->hasNativeOrDocumentedMethod($classReflection, $methodName)) {
            if ($isEloquentHost) {
                $this->builderDependencies->track($classReflection, $classReflection);
                $method = $this->resolveEloquentBuilderMethod(
                    $classReflection,
                    $methodName,
                    $this->signatureModelType($classReflection, EloquentBuilder::class, 'TModel'),
                );
            } else {
                $method = $this->resolveRelationMethod($classReflection, $methodName);
            }
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Resolve a query method forwarded by an Eloquent builder, naming the model in its signature by the signature model type.
     */
    private function resolveEloquentBuilderMethod(
        ClassReflection $classReflection,
        string $methodName,
        Type $signatureModelType,
    ): ?MethodReflection {
        // Builder::__call() and __callStatic() register a local or global macro before any other dispatch.
        if ($methodName === 'macro') {
            return new MacroRegistrationMethodReflection(
                $classReflection,
                $this->reflectionProvider->getClass(QueryBuilder::class)->getNativeMethod($methodName),
            );
        }

        $modelType = $this->builderHelper->templateType($classReflection, EloquentBuilder::class, 'TModel');

        // Local macros run before named scopes in Builder::__call().
        $macro = $this->builderMacros->resolve($classReflection, $modelType, $signatureModelType, $methodName);

        if ($macro !== null) {
            return $macro;
        }

        // Global macros, then named scopes, run next. Their own extensions describe them, and relations reach
        // global macros through their builder mixin.
        if ($this->macroMethods->hasMethod($classReflection, $methodName)
            || $this->hasNamedScope($modelType, $methodName)) {
            return null;
        }

        [$storedType, $baseType] = $this->builderHelper->queryTypes($classReflection);
        $isPassthru = $this->builderHelper->isPassthru($classReflection, $methodName);
        // Passthru runs on the raw query toBase() returns, whose rows stay as declared.
        $queryType = $isPassthru ? $baseType : $this->builderHelper->bindQueryRows($storedType, $signatureModelType);

        // A query that never exists runs no method.
        if ($queryType instanceof NeverType) {
            return null;
        }

        $queryClasses = $queryType->getObjectClassReflections();

        // Query\Builder::__call() runs a macro, else handles a where clause the query does not declare.
        // ModelPropertyColumnRule checks a dynamic where's columns: a model-dependent answer here would make
        // PHPStan report a generic subclass's call as private.
        if (count($queryClasses) !== 1
            || ! ($queryClasses[0]->hasNativeMethod($methodName) || $this->macroMethods->hasMethod($queryClasses[0], $methodName))) {
            return str_starts_with($methodName, 'where')
                ? new ForwardedFluentMethodReflection(
                    $classReflection,
                    new EloquentBuilderMethodReflection($methodName, $classReflection, [new DynamicWhereParameterReflection], isVariadic: true),
                )
                : null;
        }

        $method = $this->unresolvedMethod($queryType, $methodName);

        if (! $method->isPublic()) {
            return null;
        }

        if ($isPassthru) {
            return new ForwardedMethodReflection($classReflection, $method);
        }

        // __callStatic() registers a mixin's methods as global macros, so the query's mixin() is static here too.
        if ($methodName === 'mixin') {
            return new MacroRegistrationMethodReflection($classReflection, $method);
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
        $relatedType = $this->builderHelper->templateType($classReflection, Relation::class, 'TRelatedModel');
        $builderClasses = $this->eloquentBuilderType($relatedType, $relatedType)->getObjectClassReflections();

        if (count($builderClasses) !== 1) {
            return null;
        }

        $eloquentBuilder = $builderClasses[0];

        // Relation callers depend on the related model, not on its builder.
        foreach ($relatedType->getObjectClassReflections() as $relatedClass) {
            $this->builderDependencies->track($relatedClass, $eloquentBuilder);
        }

        $signatureModelType = $this->signatureModelType($classReflection, Relation::class, 'TRelatedModel');
        $builderType = $this->eloquentBuilderType($relatedType, $signatureModelType);

        if ($eloquentBuilder->hasNativeMethod($methodName)) {
            $method = $this->unresolvedMethod($builderType, $methodName);

            if (! $method->isPublic()) {
                return null;
            }

            // Without scopes, applyScopes() returns the same builder, which Relation::__call() turns into the relation.
            if ($normalizedMethodName === 'applyscopes') {
                return new ForwardedFluentMethodReflection($classReflection, $method, mayReturnForwardedResult: true);
            }

            if (in_array($normalizedMethodName, self::RELATION_NON_DECORATED_METHODS, strict: true)
                || ! $this->returnsStatic($eloquentBuilder->getNativeMethod($methodName))) {
                return new ForwardedMethodReflection($classReflection, $method);
            }

            return new ForwardedFluentMethodReflection($classReflection, $method);
        }

        $method = $this->resolveEloquentBuilderMethod($eloquentBuilder, $methodName, $signatureModelType);

        if ($method !== null) {
            return $method instanceof ForwardedFluentMethodReflection
                ? new ForwardedFluentMethodReflection($classReflection, $method)
                : new ForwardedMethodReflection($classReflection, $method);
        }

        if ($this->hasNamedScope($relatedType, $methodName)
            || ! in_array($normalizedMethodName, self::RELATION_DOCUMENTED_FLUENT_METHODS, strict: true)) {
            return null;
        }

        return new ForwardedFluentMethodReflection($classReflection, $this->unresolvedMethod($builderType, $methodName));
    }

    /**
     * Return a class's ancestor template type as the class's own template types declare it.
     *
     * Forwarded signatures name the receiver's model this way. PHPStan binds the receiver's template types
     * after replacing its static types, so a late-static model is not mistaken for the receiver.
     *
     * @param class-string $ancestorClass
     */
    private function signatureModelType(ClassReflection $classReflection, string $ancestorClass, string $templateName): Type
    {
        return $this->builderHelper->templateType($this->reflectionProvider->getClass($classReflection->getName()), $ancestorClass, $templateName);
    }

    /**
     * Return a type's method, keeping the template types it is instantiated with instead of their bounds.
     */
    private function unresolvedMethod(Type $type, string $methodName): ExtendedMethodReflection
    {
        // @phpstan-ignore phpstanApi.method (ObjectType::getMethod() resolves template types to their bounds.), phpstanApi.method (Same lookup.)
        return $type->getUnresolvedMethodPrototype($methodName, $this->scope)->doNotResolveTemplateTypeMapToBounds()->getTransformedMethod();
    }

    /**
     * Resolve the related model's builder holding the given model type, or the base builder for several or unknown models.
     */
    private function eloquentBuilderType(Type $relatedType, Type $modelType): Type
    {
        $modelClassNames = $relatedType->getObjectClassNames();

        return (count($modelClassNames) === 1 ? $this->builderHelper->determineBuilderClass($modelClassNames[0], $modelType) : null)
            ?? new GenericObjectType(EloquentBuilder::class, [$modelType]);
    }

    /**
     * Determine whether a named scope owns dispatch before query forwarding.
     */
    private function hasNamedScope(Type $modelType, string $methodName): bool
    {
        return $this->scopeMethods->resolveForType($modelType, $methodName) !== null;
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
