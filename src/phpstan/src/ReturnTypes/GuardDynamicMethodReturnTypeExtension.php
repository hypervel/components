<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Auth\Factory;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Types guard() on the auth factory the way the Auth facade's guard() is typed.
 *
 * Upstream types only the facade, leaving auth()->guard('web') with the
 * declared Guard while Auth::guard('web') resolves the session guard.
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
        );
    }
}
