<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\Auth\Factory;
use Hypervel\PHPStan\Concerns;
use Hypervel\PHPStan\ReturnTypes\AuthGuardHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function is_object;

/** @internal */
#[AutowiredService]
final class AuthExtension implements DynamicFunctionReturnTypeExtension
{
    use Concerns\HasContainer;

    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AuthGuardHelper $authGuardHelper,
    ) {
    }

    /**
     * Determine whether the function is the auth() helper.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'auth';
    }

    /**
     * Get the type of the auth factory or requested guard.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        $guard = $functionCall->getArgs()[0] ?? null;

        // Unlike guard(null), auth() and auth(null) return the factory rather than the default guard.
        if ($guard === null || (! $guard->unpack && $scope->getType($guard->value)->isNull()->yes())) {
            $factory = $this->resolve(Factory::class);

            return is_object($factory) ? new ObjectType($factory::class) : null;
        }

        // A maybe-null or unpacked guard can still return the factory, so the declared conditional applies.
        if ($guard->unpack || ! $scope->getType($guard->value)->isNull()->no()) {
            return null;
        }

        // Upstream types every named guard as StatefulGuard, which only the session guard is.
        return $this->authGuardHelper->getGuardType(
            $guard,
            $scope,
            ParametersAcceptorSelector::selectFromArgs($scope, $functionCall->getArgs(), $functionReflection->getVariants())->getReturnType(),
            acceptsEnums: false,
        );
    }
}
