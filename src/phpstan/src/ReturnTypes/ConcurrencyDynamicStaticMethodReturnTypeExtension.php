<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\Facades\Concurrency;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

#[AutowiredService]
class ConcurrencyDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConcurrencyDriverHelper $driverHelper)
    {
    }

    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return Concurrency::class;
    }

    /**
     * Determine whether the method returns a driver.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'driver';
    }

    /**
     * Get the type of the selected driver.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        return $this->driverHelper->getDriverType($methodCall->getArgs()[0] ?? null, $scope);
    }
}
