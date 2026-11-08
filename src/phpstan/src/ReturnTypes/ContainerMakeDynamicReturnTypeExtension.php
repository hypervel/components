<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Container\Container;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function in_array;

/**
 * This also covers the Application contract, which extends the container contract, so upstream's
 * ApplicationMakeDynamicReturnTypeExtension is omitted. Upstream's ContainerArrayAccessDynamicMethodReturnTypeExtension
 * is omitted because the container does not support ArrayAccess.
 */
#[AutowiredService]
class ContainerMakeDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return Container::class;
    }

    /**
     * Determine whether the method resolves a container entry.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // Hypervel's get() and makeTransient() resolve the same bindings as make(), and
        // factory() returns a closure that does.
        return in_array($methodReflection->getName(), ['make', 'makeWith', 'makeTransient', 'get', 'resolve', 'factory'], true);
    }

    /**
     * Get the type of the resolved container entry.
     */
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        return $methodReflection->getName() === 'factory'
            ? $this->appMakeHelper->resolveFactoryTypeFromCall($methodCall, $scope)
            : $this->appMakeHelper->resolveTypeFromCall($methodCall, $scope);
    }
}
