<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ClosureTypes;

use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Reflection\CallableParameterReflection;
use Hypervel\PHPStan\Reflection\ForwardedMethodReflection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\CallableType;
use PHPStan\Type\MethodParameterClosureTypeExtension;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;
use function in_array;
use function strtolower;

/**
 * Gives the callbacks of a relation's when() and unless() their builder as a "this" type.
 *
 * Relation::__call() returns the relation only when the call returns the relation's own builder.
 * Fluent calls keep a "this" type, while a clone or another query of the same class loses it, so
 * ConditionableExpressionTypeResolverExtension can tell a callback that returns the builder it
 * was given from one that returns a different builder.
 *
 * @internal
 */
#[AutowiredService]
class RelationConditionableCallbackExtension implements MethodParameterClosureTypeExtension
{
    /**
     * Determine whether the parameter is a callback of a when() or unless() call a relation forwards.
     */
    public function isMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return in_array(strtolower($methodReflection->getName()), ['when', 'unless'], true)
            && in_array($parameter->getName(), ['callback', 'default'], true)
            && $methodReflection->getDeclaringClass()->is(Relation::class)
            && $methodReflection->getPrototype() instanceof ForwardedMethodReflection;
    }

    /**
     * Return the callback type with the builder it receives as a "this" type.
     */
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, ParameterReflection $parameter, Scope $scope): Type
    {
        $type = $parameter->getType();
        $callables = TypeCombinator::removeNull($type)->getCallableParametersAcceptors($scope);
        $parameters = count($callables) === 1 ? $callables[0]->getParameters() : [];
        $builderClasses = isset($parameters[0]) ? $parameters[0]->getType()->getObjectClassReflections() : [];

        if (count($builderClasses) !== 1) {
            return $type;
        }

        // Only the first parameter is the relation's builder; the value passed second may be any other builder.
        $parameters[0] = new CallableParameterReflection($parameters[0]->getName(), new ThisType($builderClasses[0]));

        return TypeCombinator::addNull(new CallableType(
            $parameters,
            $callables[0]->getReturnType(),
            $callables[0]->isVariadic(),
            $callables[0]->getTemplateTypeMap(),
            $callables[0]->getResolvedTemplateTypeMap(),
        ));
    }
}
