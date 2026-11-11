<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Reflection\NamedScopeMethodReflection;
use LogicException;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\StaticType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;

/**
 * Describe model scopes exposed through models, builders, and relations.
 */
#[AutowiredService]
class NamedScopeMethodExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    /**
     * Create a named scope method extension.
     */
    public function __construct(
        private readonly ModelScopeMethodResolver $scopeMethods,
        private readonly BuilderMacroResolver $builderMacros,
        private readonly BuilderHelper $builderHelper,
        private readonly MacroMethodsClassReflectionExtension $macroMethods,
        private readonly DynamicRelationMethodExtension $dynamicRelations,
    ) {
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
        $isModelHost = $classReflection->is(Model::class);
        $isBuilderHost = ! $isModelHost && $classReflection->is(Builder::class);
        $isRelationHost = ! $isModelHost && ! $isBuilderHost && $classReflection->is(Relation::class);

        if (! $isModelHost && ! $isBuilderHost && ! $isRelationHost) {
            return null;
        }

        // Keep the exact spelling: builder macro names are case-sensitive.
        $cacheKey = $classReflection->getCacheKey() . ':' . $methodName;

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $host = $this->host($classReflection, $isModelHost, $isBuilderHost);

        if ($host === null) {
            return null;
        }

        [$modelType, $receiverType, $queryType, $static] = $host;
        $scope = $this->scopeMethods->resolveForType($modelType, $methodName);
        $method = null;

        // Local builder macros run before named scopes in Builder::__call().
        if ($scope !== null
            && ! $this->builderMacros->hasMacro($scope[0], $methodName)
            && ! $this->methodTakesPrecedence($classReflection, $methodName, $queryType, $static)) {
            $method = new NamedScopeMethodReflection(
                $classReflection,
                $scope[0],
                $scope[1],
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
     * @return null|array{Type, Type, Type, bool}
     */
    private function host(
        ClassReflection $classReflection,
        bool $isModelHost,
        bool $isBuilderHost,
    ): ?array {
        if ($isModelHost) {
            $queryType = $this->activeBuilderType($classReflection);

            return [
                (new StaticType($classReflection))->getStaticObjectType(),
                $queryType,
                $queryType,
                true,
            ];
        }

        $modelType = $this->builderHelper->templateType(
            $classReflection,
            $isBuilderHost ? Builder::class : Relation::class,
            $isBuilderHost ? 'TModel' : 'TRelatedModel',
        );
        $receiverType = new ThisType($classReflection);

        if ($isBuilderHost) {
            return [$modelType, $receiverType, $receiverType, false];
        }

        // An intersection such as Model&HasTimeline still names one model, whose builder the relation forwards to.
        $modelClasses = array_values(array_filter(
            $modelType->getObjectClassReflections(),
            static fn (ClassReflection $modelClass): bool => $modelClass->is(Model::class),
        ));

        return count($modelClasses) === 1
            ? [$modelType, $receiverType, $this->activeBuilderType($modelClasses[0]), false]
            : null;
    }

    /**
     * Determine whether a native method or a global builder macro wins over dynamic scope dispatch.
     */
    private function methodTakesPrecedence(
        ClassReflection $classReflection,
        string $methodName,
        Type $queryType,
        bool $static,
    ): bool {
        // PHPStan asks this extension only for calls that cannot reach the native method, and a protected
        // method is unreachable from outside, so the host's __call() runs the scope.
        if ($classReflection->hasNativeMethod($methodName) && $classReflection->getNativeMethod($methodName)->isPublic()) {
            return true;
        }

        // A model's __call() runs dynamic relations and through{Relation}() before forwarding to its builder.
        if ($static && $this->dynamicRelations->hasMethod($classReflection, $methodName)) {
            return true;
        }

        // Every host also yields to its active builder's public methods (a protected one is unreachable from outside,
        // so Builder::__call() runs), and Builder::__call() runs global macros first.
        foreach ($queryType->getObjectClassReflections() as $builderClass) {
            if (($builderClass->hasNativeMethod($methodName) && $builderClass->getNativeMethod($methodName)->isPublic())
                || $this->macroMethods->hasMethod($builderClass, $methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the model's builder type.
     *
     * Bind the model here because scope returns are compared before PHPStan can rebind the synthesized receiver.
     */
    private function activeBuilderType(ClassReflection $modelClass): Type
    {
        return $this->builderHelper->determineBuilderClass($modelClass->getName(), (new StaticType($modelClass))->getStaticObjectType());
    }
}
