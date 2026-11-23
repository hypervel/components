<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\PHPStan\Reflection\MacroRegistrationMethodReflection;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticMethodParameterClosureThisExtension;
use PHPStan\Type\Type;

/**
 * Type the call forms of an Eloquent builder's macro() and mixin() that differ: Builder::__callStatic() binds
 * a global macro to the builder, and an instance mixin() reaches the query's, returning the builder.
 */
#[AutowiredService]
class MacroRegistrationExtension implements DynamicMethodReturnTypeExtension, StaticMethodParameterClosureThisExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return EloquentBuilder::class;
    }

    /**
     * Determine whether the call is an instance mixin().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'mixin'
            && $methodReflection->getPrototype() instanceof MacroRegistrationMethodReflection;
    }

    /**
     * Return the builder an instance mixin() returns.
     */
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type
    {
        return $scope->getType($methodCall->var);
    }

    /**
     * Determine whether the call registers a global macro.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $methodReflection->getName() === 'macro'
            && $methodReflection->getPrototype() instanceof MacroRegistrationMethodReflection;
    }

    /**
     * Bind a global macro to the builder it is registered on.
     */
    public function getClosureThisTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        ParameterReflection $parameter,
        Scope $scope,
    ): Type {
        return new ObjectType($methodReflection->getDeclaringClass()->getName());
    }
}
