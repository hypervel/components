<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Http\Request;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Concerns\LoadsAuthModel;
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

/** @internal */
#[AutowiredService]
class RequestUserExtension implements DynamicMethodReturnTypeExtension
{
    use HasContainer;
    use LoadsAuthModel;

    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AuthGuardHelper $authGuardHelper,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return Request::class;
    }

    /**
     * Determine whether the method is the request's user().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'user';
    }

    /**
     * Get the user type of the requested guard, or of every guard.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        if ($methodReflection->getDeclaringClass()->getName() !== Request::class) {
            return null;
        }

        $this->runtimeInputs->track($scope);

        // Upstream reads one constant guard name, so user('') looked up an empty name
        // instead of the default guard that the request's user resolver uses.
        $authModels = $this->getAuthModels(
            $this->getContainer()->get('config'),
            $this->authGuardHelper->getGuardNames($methodCall->getArgs()[0] ?? null, $scope, acceptsEnums: false),
        );

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
