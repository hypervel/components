<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Auth\Guard;
use Hypervel\Support\Facades\Auth;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function count;

/** @internal */
#[AutowiredService]
class AuthExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AuthGuardHelper $authGuardHelper,
    ) {
    }

    /**
     * Get the class whose static method calls are typed.
     */
    public function getClass(): string
    {
        return Auth::class;
    }

    /**
     * Determine whether the method is the facade's user().
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        // Unlike upstream, a facade subclass's own user() method keeps its declaration.
        return $methodReflection->getName() === 'user'
            && $methodReflection->getDeclaringClass()->getName() === Guard::class;
    }

    /**
     * Get the user type of every configured guard.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $authModels = $this->authGuardHelper->getUserModels($scope);

        if (count($authModels) === 0) {
            return null;
        }

        return TypeCombinator::addNull(
            TypeCombinator::union(...array_map(
                static fn (string $authModel): Type => new ObjectType($authModel),
                $authModels,
            )),
        );
    }
}
