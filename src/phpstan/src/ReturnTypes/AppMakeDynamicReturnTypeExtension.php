<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\Facades\App;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function in_array;

#[AutowiredService]
class AppMakeDynamicReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return App::class;
    }

    /**
     * Determine whether the method resolves a container entry.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        // Hypervel's get() and makeTransient() resolve the same bindings as make(), and
        // factory() returns a closure that does.
        return in_array($methodReflection->getName(), ['make', 'makeWith', 'makeTransient', 'get', 'factory'], true);
    }

    /**
     * Get the type of the resolved container entry.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, Scope $scope): ?Type
    {
        return $methodReflection->getName() === 'factory'
            ? $this->appMakeHelper->resolveFactoryTypeFromCall($methodCall, $scope)
            : $this->appMakeHelper->resolveTypeFromCall($methodCall, $scope);
    }
}
