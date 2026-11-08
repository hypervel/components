<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\PHPStan\Internal\RecursionGuard;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\PHPStan\Reflection\StaticMethodReflection;
use Hypervel\Support\Facades\Facade;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use Throwable;

use function array_key_exists;
use function is_object;

/** @internal */
#[AutowiredService]
class FacadesMethodsExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, false|MethodReflection> */
    private array $cache = [];

    /** @var array<class-string, null|object> */
    private array $facadeRoots = [];

    /**
     * Create a new facade methods extension.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Determine whether the facade's booted root declares the method its PHPDoc omits.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (! $classReflection->is(Facade::class)) {
            return false;
        }

        $key = $classReflection->getName() . '-' . $methodName;

        if (isset($this->cache[$key])) {
            return $this->cache[$key] !== false;
        }

        $result = RecursionGuard::run($key, function () use ($classReflection, $methodName, $key) {
            if (ReflectionHelper::hasMethodTag($classReflection, $methodName)) {
                return false;
            }

            $facadeClass = $classReflection->getName();

            $concrete = $this->getFacadeRoot($facadeClass);

            if ($concrete !== null) {
                $concreteClass = $concrete::class;

                if ($this->reflectionProvider->hasClass($concreteClass)) {
                    $concreteReflection = $this->reflectionProvider->getClass($concreteClass);

                    // Use hasNativeMethod() instead of hasMethod() to avoid
                    // re-entering registered MethodsClassReflectionExtensions
                    // (including this one), which would cause infinite recursion.
                    if ($concreteReflection->hasNativeMethod($methodName)) {
                        $this->cache[$key] = new StaticMethodReflection(
                            $concreteReflection->getNativeMethod($methodName),
                        );

                        return true;
                    }
                }
            }

            return false;
        });

        if ($result === false) {
            $this->cache[$key] = false;
        }

        return $result ?? false;
    }

    /**
     * Get the booted root object behind the facade.
     *
     * @param class-string $facadeClass
     */
    private function getFacadeRoot(string $facadeClass): ?object
    {
        if (array_key_exists($facadeClass, $this->facadeRoots)) {
            return $this->facadeRoots[$facadeClass];
        }

        $concrete = null;

        try {
            $concrete = $facadeClass::getFacadeRoot();
        } catch (Throwable) {
        }

        return $this->facadeRoots[$facadeClass] = is_object($concrete) ? $concrete : null;
    }

    /**
     * Get the root's method as a static facade method.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        // hasMethod() returns true only after caching the method.
        /** @var MethodReflection $method */
        $method = $this->cache[$classReflection->getName() . '-' . $methodName];

        return $method;
    }
}
