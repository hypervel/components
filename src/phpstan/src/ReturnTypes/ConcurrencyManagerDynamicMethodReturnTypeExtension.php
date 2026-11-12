<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Concurrency\ConcurrencyManager;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

#[AutowiredService]
class ConcurrencyManagerDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConcurrencyDriverHelper $driverHelper)
    {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return ConcurrencyManager::class;
    }

    /**
     * Determine whether the method returns a driver.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'driver';
    }

    /**
     * Get the type of the selected driver.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        // A subclass may resolve or create its drivers differently.
        foreach ($scope->getType($methodCall->var)->getObjectClassNames() as $receiverClass) {
            if ($receiverClass !== ConcurrencyManager::class) {
                return null;
            }
        }

        return $this->driverHelper->getDriverType($methodCall->getArgs()[0] ?? null, $scope);
    }
}
