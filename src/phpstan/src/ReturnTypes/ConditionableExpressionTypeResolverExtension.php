<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\HigherOrderWhenProxy;
use Hypervel\Support\Traits\Conditionable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\ExpressionTypeResolverExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_search;
use function in_array;
use function max;

/**
 * Types Conditionable's when() and unless() by how many arguments they receive, as their
 * bodies do. Without a callback argument they return a proxy, which takes its condition
 * from a given value, or else from the next property or method it passes on. With one,
 * even null, they return the instance or what the callback or default returns.
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
        if (! $expr instanceof MethodCall
            || ! $expr->name instanceof Identifier
            || ! in_array($expr->name->toLowerString(), ['when', 'unless'], true)
            || $expr->isFirstClassCallable()) {
            return null;
        }

        $argumentCount = $this->countArguments($expr->getArgs());

        if ($argumentCount === null) {
            return null;
        }

        $calledOnType = $scope->getType($expr->var);
        $methodReflection = $scope->getMethodReflection($calledOnType, $expr->name->toString());

        if ($methodReflection === null || ! $this->isConditionable($methodReflection)) {
            return null;
        }

        // Conditionable passes $this, so the proxy's target is the called object.
        if ($argumentCount < 2) {
            return new GenericObjectType(HigherOrderWhenProxy::class, [$calledOnType, new ConstantBooleanType($argumentCount === 1)]);
        }

        $variant = ParametersAcceptorSelector::selectFromArgs($scope, $expr->getArgs(), $methodReflection->getVariants());

        // Conditionable declares these templates, so isConditionable() guarantees them.
        /** @var Type $callbackReturnType */
        $callbackReturnType = $variant->getResolvedTemplateTypeMap()->getType(
            $expr->name->toLowerString() === 'when' ? 'TWhenReturnType' : 'TUnlessReturnType',
        );

        // The template holds what the callback or default returns other than null, and the call
        // returns the instance otherwise. PHPStan leaves the template an error type when no
        // callback or default returns a value, where its declared default is never.
        return $callbackReturnType instanceof ErrorType
            ? $calledOnType
            : TypeCombinator::union($calledOnType, $callbackReturnType);
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
    private function isConditionable(ExtendedMethodReflection $methodReflection): bool
    {
        $declaringClass = $methodReflection->getDeclaringClass();

        // A trait method keeps the trait's file.
        return $declaringClass->hasNativeMethod($methodReflection->getName())
            && $declaringClass->getNativeReflection()->getMethod($methodReflection->getName())->getFileName()
                === $this->reflectionProvider->getClass(Conditionable::class)->getFileName();
    }
}
