<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\PHPStan\Concerns;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_map;
use function count;

#[AutowiredService]
class AuthManagerExtension implements DynamicMethodReturnTypeExtension
{
    use Concerns\HasContainer;
    use Concerns\LoadsAuthModel;

    /**
     * Create a new extension instance.
     */
    public function __construct(
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return AuthManager::class;
    }

    /**
     * Determine whether the method is the default guard's user().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // Unlike upstream, a subclass's own user() method keeps its declaration.
        return $methodReflection->getName() === 'user'
            && $methodReflection->getDeclaringClass()->getName() === Guard::class;
    }

    /**
     * Get the user type of every configured guard.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $this->runtimeInputs->track($scope);

        $authModels = $this->getAuthModels($this->getContainer()->get('config'));

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
