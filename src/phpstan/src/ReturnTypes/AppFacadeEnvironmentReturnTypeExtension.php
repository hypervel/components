<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\Facades\App;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

#[AutowiredService]
class AppFacadeEnvironmentReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return App::class;
    }

    /**
     * Determine whether the method gets or checks the environment.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'environment';
    }

    /**
     * Get the environment name without arguments, or the result of checking it with them.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        return AppEnvironmentReturnTypeExtension::getEnvironmentType(
            $methodCall->getArgs(),
            $scope,
            $methodReflection->getVariants()[0]->getReturnType(),
        );
    }
}
