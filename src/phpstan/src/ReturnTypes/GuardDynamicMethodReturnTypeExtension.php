<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Auth\Factory;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Types guard() on the auth factory like the Auth facade's guard(). Upstream types only
 * the facade, so auth()->guard('web') kept the declared Guard.
 */
#[AutowiredService]
class GuardDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AuthGuardHelper $authGuardHelper,
    ) {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return Factory::class;
    }

    /**
     * Determine whether the method is the factory's guard().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'guard';
    }

    /**
     * Get the type of the requested guard.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        return $this->authGuardHelper->getGuardType(
            $methodCall->getArgs()[0] ?? null,
            $scope,
            ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())->getReturnType(),
            receiverClasses: $scope->getType($methodCall->var)->getObjectClassNames(),
        );
    }
}
