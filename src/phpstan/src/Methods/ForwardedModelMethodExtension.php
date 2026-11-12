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
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

/**
 * Describe the methods an Eloquent model's __call() and __callStatic() handle.
 */
#[AutowiredService]
class ForwardedModelMethodExtension implements MethodsClassReflectionExtension
{
    // Model::__call() dispatches these protected methods; the list must stay in sync with it.
    private const array MODEL_CALL_FORWARDED_METHODS = [
        'increment',
        'decrement',
        'incrementQuietly',
        'decrementQuietly',
        'incrementEach',
        'decrementEach',
        'incrementEachQuietly',
        'decrementEachQuietly',
    ];

    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a forwarded model method extension.
     */
    public function __construct(
        private readonly ModelScopeMethodResolver $scopeMethods,
        private readonly BuilderMacroResolver $builderMacros,
        private readonly BuilderDependencies $builderDependencies,
        private readonly BuilderHelper $builderHelper,
        private readonly MacroMethodsClassReflectionExtension $macroMethods,
        private readonly DynamicRelationMethodExtension $dynamicRelations,
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
        if (! $classReflection->is(Model::class)) {
            return null;
        }

        // Keep the exact spelling: builder macro names are case-sensitive.
        $cacheKey = $classReflection->getCacheKey() . ':' . $methodName;

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $method = null;

        // Unlike upstream, these are static too: __callStatic() calls them on a new model from the model's scope.
        if (in_array($methodName, self::MODEL_CALL_FORWARDED_METHODS, true) && $classReflection->hasNativeMethod($methodName)) {
            $method = new ForwardedModelMethodReflection($classReflection, $classReflection->getNativeMethod($methodName));
        } elseif (! $this->builderHelper->hasNativeOrDocumentedMethod($classReflection, $methodName)
            // Model::__call() runs dynamic relations and through{Relation}() before forwarding to the builder.
            && ! $this->dynamicRelations->hasMethod($classReflection, $methodName)) {
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

                // Model::__call() cannot reach a protected builder method: the builder's own __call() runs and throws.
                if ($builderMethod->isPublic()) {
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
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Determine whether an active builder owns a public native method or global macro.
     *
     * A protected builder method is unreachable from Model::__call(), so the builder's __call() runs a scope of that name.
     */
    private function builderMethodTakesPrecedence(Type $builderType, string $methodName): bool
    {
        foreach ($builderType->getObjectClassReflections() as $builderClass) {
            if (($builderClass->hasNativeMethod($methodName) && $builderClass->getNativeMethod($methodName)->isPublic())
                || $this->macroMethods->hasMethod($builderClass, $methodName)) {
                return true;
            }
        }

        return false;
    }
}
