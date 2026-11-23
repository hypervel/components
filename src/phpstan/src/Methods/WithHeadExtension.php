<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Head\Routing\HeadRouteMacros;
use Hypervel\PHPStan\Methods\Macros\RoutingMacros;
use Hypervel\PHPStan\Reflection\WithHeadMethodReflection;
use Hypervel\Routing\PendingResourceRegistration;
use Hypervel\Routing\PendingSingletonResourceRegistration;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteRegistrar;
use Hypervel\Support\Facades\Route as RouteFacade;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;

#[AutowiredService]
class WithHeadExtension implements MethodsClassReflectionExtension
{
    /**
     * Create the routing macro reflection extension.
     */
    public function __construct(protected readonly ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Determine whether the class receives the withHead routing macro.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        // Head is optional; without it, no withHead() macro is registered.
        if ($methodName !== 'withHead'
            || $classReflection->hasNativeMethod($methodName)
            || ! $this->reflectionProvider->hasClass(HeadRouteMacros::class)) {
            return false;
        }

        foreach ([Router::class, RouteRegistrar::class, Route::class, PendingResourceRegistration::class, PendingSingletonResourceRegistration::class, RouteFacade::class] as $class) {
            if ($classReflection->getAncestorWithClassName($class) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reflect the macro signature with the receiving routing object's return type.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $method = $this->reflectionProvider->getClass(RoutingMacros::class)->getNativeMethod('withHead');
        $isStatic = $classReflection->getAncestorWithClassName(RouteFacade::class) !== null;
        $returnType = $isStatic || $classReflection->getAncestorWithClassName(Router::class) !== null
            ? new ObjectType(RouteRegistrar::class)
            : new ThisType($classReflection);

        return new WithHeadMethodReflection(
            $classReflection,
            $method,
            $returnType,
            $isStatic,
        );
    }
}
