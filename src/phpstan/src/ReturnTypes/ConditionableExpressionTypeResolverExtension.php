<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Reflection\ForwardedMethodReflection;
use Hypervel\PHPStan\Reflection\ForwardedModelMethodReflection;
use Hypervel\Support\HigherOrderWhenProxy;
use Hypervel\Support\Traits\Conditionable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;

use function array_map;
use function array_search;
use function in_array;
use function max;

/**
 * Types Conditionable's when() and unless() by how many arguments they receive, as their
 * bodies do. Without a callback argument they return a proxy, which takes its condition
 * from a given value, or else from the next property or method it passes on. With one,
 * even null, they return the instance or what the callback or default returns.
 *
 * Models and relations forward them to a builder. Model::__call() and __callStatic() return
 * what the builder returns, while Relation::__call() returns the relation in place of its
 * own builder: the instance, or a callback's result that is known to be the builder it was given.
 *
 * Declared return types cannot tell an omitted argument from a null one, and a dynamic
 * return type extension cannot apply to methods that classes get from a trait.
 *
 * @internal
 */
#[AutowiredService]
class ConditionableExpressionTypeResolverExtension implements ExpressionTypeResolverExtension
{
    /**
     * The parameters of when() and unless(), in order.
     */
    private const array PARAMETERS = ['value', 'callback', 'default'];

    /**
     * Create a new extension instance.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Get the type of a when() or unless() call that runs Conditionable's implementation.
     */
    public function getType(Expr $expr, Scope $scope): ?Type
    {
        if (! ($expr instanceof MethodCall || $expr instanceof StaticCall)
            || ! $expr->name instanceof Identifier
            || ! in_array($expr->name->toLowerString(), ['when', 'unless'], true)
            || $expr->isFirstClassCallable()) {
            return null;
        }

        $argumentCount = $this->countArguments($expr->getArgs());

        if ($argumentCount === null) {
            return null;
        }

        $calledOnType = match (true) {
            $expr instanceof MethodCall => $scope->getType($expr->var),
            $expr->class instanceof Name => $scope->resolveTypeByName($expr->class),
            default => $scope->getType($expr->class)->getObjectTypeOrClassStringObjectType(),
        };
        $methodReflection = $scope->getMethodReflection($calledOnType, $expr->name->toString());

        if ($methodReflection === null) {
            return null;
        }

        $prototype = $methodReflection->getPrototype();
        $forwarded = $prototype instanceof ForwardedModelMethodReflection
            || ($prototype instanceof ForwardedMethodReflection
                && $expr instanceof MethodCall
                && (new ObjectType(Relation::class))->isSuperTypeOf($calledOnType)->yes())
            ? $prototype
            : null;
        $modelCall = $forwarded instanceof ForwardedModelMethodReflection;

        // Only Model::__callStatic() reaches Conditionable statically; a parent:: call keeps its declaration.
        if ($expr instanceof StaticCall && ! $modelCall) {
            return null;
        }

        if (! $this->isConditionable($forwarded?->getForwardedMethod() ?? $methodReflection)) {
            return null;
        }

        $variant = ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $methodReflection->getVariants());
        $runningType = $forwarded === null ? $calledOnType : $this->getCallbackSubjectType($variant, $scope);

        // Conditionable passes $this, so the proxy's target is the object running it.
        if ($argumentCount < 2) {
            return new GenericObjectType(HigherOrderWhenProxy::class, [$runningType, new ConstantBooleanType($argumentCount === 1)]);
        }

        // Conditionable declares these templates, so isConditionable() guarantees them.
        /** @var Type $callbackReturnType */
        $callbackReturnType = $variant->getResolvedTemplateTypeMap()->getType(
            $expr->name->toLowerString() === 'when' ? 'TWhenReturnType' : 'TUnlessReturnType',
        );
        $instanceType = $modelCall ? $runningType : $calledOnType;

        // The template holds what the callback or default returns other than null, and the call
        // returns the instance otherwise. PHPStan leaves the template an error type when no
        // callback or default returns a value, where its declared default is never.
        if ($callbackReturnType instanceof ErrorType) {
            return $instanceType;
        }

        if ($forwarded === null || $modelCall) {
            return TypeCombinator::union($instanceType, $callbackReturnType);
        }

        // Relation::__call() returns the relation in place of its own builder. Only the builder the callback
        // was given keeps the "this" type RelationConditionableCallbackExtension gives it; a clone is static.
        return TypeCombinator::union($instanceType, ...array_map(
            static fn (Type $type): Type => match (true) {
                ! $type instanceof StaticType || ! $runningType->isSuperTypeOf($type)->yes() => $type,
                $type instanceof ThisType => $calledOnType,
                default => $type->getStaticObjectType(),
            },
            TypeUtils::flattenTypes($callbackReturnType),
        ));
    }

    /**
     * Get the type of the object Conditionable passes to the callback as $this: the builder of a forwarded call.
     */
    private function getCallbackSubjectType(ParametersAcceptor $variant, Scope $scope): Type
    {
        return TypeCombinator::removeNull($variant->getParameters()[1]->getType())
            ->getCallableParametersAcceptors($scope)[0]
            ->getParameters()[0]
            ->getType();
    }

    /**
     * Count the arguments as func_num_args() does: up to the last parameter given.
     *
     * @param list<Arg> $args
     */
    private function countArguments(array $args): ?int
    {
        $count = 0;

        foreach ($args as $position => $arg) {
            if ($arg->unpack) {
                return null;
            }

            $index = $arg->name === null ? $position : array_search($arg->name->toString(), self::PARAMETERS, true);

            if ($index === false) {
                return null;
            }

            $count = max($count, $index + 1);
        }

        return $count;
    }

    /**
     * Determine whether the method is Conditionable's own implementation.
     */
    private function isConditionable(MethodReflection $methodReflection): bool
    {
        $declaringClass = $methodReflection->getDeclaringClass();

        // A trait method keeps the trait's file.
        return $declaringClass->hasNativeMethod($methodReflection->getName())
            && $declaringClass->getNativeReflection()->getMethod($methodReflection->getName())->getFileName()
                === $this->reflectionProvider->getClass(Conditionable::class)->getFileName();
    }
}
