<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\PendingHasThroughRelationship;
use Hypervel\PHPStan\Properties\ModelHelper;
use Hypervel\PHPStan\Reflection\DynamicRelationMethodReflection;
use LogicException;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\Constant\ConstantStringType;
use ReflectionException;

use function array_key_exists;
use function lcfirst;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Describe the relation methods that __call() handles: a model's relations that resolveRelationUsing() registers
 * and its through{Relation}() calls, and a pending has-through relationship's has{Relation}() calls.
 */
#[AutowiredService]
class DynamicRelationMethodExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    /**
     * Create a dynamic relation method extension.
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ModelHelper $modelHelper,
        private readonly BuilderHelper $builderHelper,
        private readonly ClosureTypeFactory $closureTypeFactory,
    ) {
    }

    /**
     * Determine whether the class's __call() handles the method as a relation.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->resolveMethod($classReflection, $methodName) !== null;
    }

    /**
     * Return the relation method.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->resolveMethod($classReflection, $methodName)
            ?? throw new LogicException(sprintf(
                'Dynamic relation method [%s::%s] was not resolved.',
                $classReflection->getName(),
                $methodName,
            ));
    }

    /**
     * Resolve and cache a relation method that __call() handles.
     */
    private function resolveMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $isModel = $classReflection->is(Model::class);

        if (! $isModel && ! $classReflection->is(PendingHasThroughRelationship::class)) {
            return null;
        }

        // Keep the exact spelling: resolver names and the prefixes are case-sensitive.
        $cacheKey = $classReflection->getCacheKey() . ':' . $methodName;

        if (array_key_exists($cacheKey, $this->methods)) {
            $cachedMethod = $this->methods[$cacheKey];

            return $cachedMethod === false ? null : $cachedMethod;
        }

        $method = null;

        if ($isModel) {
            $method = $this->resolveModelMethod($classReflection, $methodName);
        } elseif (str_starts_with($methodName, 'has') && ! $this->builderHelper->hasNativeOrDocumentedMethod($classReflection, $methodName)) {
            // PendingHasThroughRelationship::__call() turns any has{Relation}() into has('relation').
            $method = $this->selectorMethod($classReflection, $methodName, 'has', lcfirst(substr($methodName, 3)), false);
        }

        $this->methods[$cacheKey] = $method ?? false;

        return $method;
    }

    /**
     * Resolve a relation that Model::__call() handles before forwarding to the builder: a registered resolver,
     * then through{Relation}() when the model has the relation's method.
     *
     * Model::__callStatic() calls these on a new model, so they are static too.
     */
    private function resolveModelMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        if ($this->builderHelper->hasNativeOrDocumentedMethod($classReflection, $methodName)) {
            return null;
        }

        try {
            $resolver = $this->modelHelper->getRelationResolver($classReflection, $methodName);
        } catch (ReflectionException) {
            $resolver = null;
        }

        // The resolver is never run; the model call passes the model to it, so it takes no arguments.
        if ($resolver !== null) {
            return new DynamicRelationMethodReflection(
                $classReflection,
                $methodName,
                $this->closureTypeFactory->fromClosureObject($resolver)->getReturnType(),
                true,
            );
        }

        // The prefix is case-sensitive, as Str::startsWith() is.
        if (! str_starts_with($methodName, 'through')) {
            return null;
        }

        $relationName = lcfirst(substr($methodName, 7));

        return $classReflection->hasNativeMethod($relationName)
            ? $this->selectorMethod($classReflection, $methodName, 'through', $relationName, true)
            : null;
    }

    /**
     * Describe a call that __call() turns into the selector method with a relation name, typed by what the
     * selector method declares for a string.
     */
    private function selectorMethod(
        ClassReflection $classReflection,
        string $methodName,
        string $selectorMethod,
        string $relationName,
        bool $isStatic,
    ): MethodReflection {
        // Read the declaration as the class declares it: PHPStan binds the receiver's template types after replacing
        // its $this and static types, so a receiver's late-static model is not mistaken for the receiver.
        $variants = $this->reflectionProvider->getClass($classReflection->getName())->getNativeMethod($selectorMethod)->getVariants();

        return new DynamicRelationMethodReflection(
            $classReflection,
            $methodName,
            ParametersAcceptorSelector::selectFromTypes([new ConstantStringType($relationName)], $variants, false)->getReturnType(),
            $isStatic,
        );
    }
}
