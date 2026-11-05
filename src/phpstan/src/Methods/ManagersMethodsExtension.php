<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Closure;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Support\Manager;
use InvalidArgumentException;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Type;

use function array_key_exists;

/**
 * Type the calls a manager forwards to its default driver.
 *
 * The booted manager selects the default driver, so a different environment can select
 * a driver with other methods. The declared return type of whatever creates that driver
 * types it, so analysis never builds a driver, which can need secrets, extensions or
 * connections.
 *
 * @internal
 */
#[AutowiredService]
final class ManagersMethodsExtension implements MethodsClassReflectionExtension
{
    use HasContainer;

    /** @var array<string, MethodReflection> */
    private array $cache = [];

    /** @var array<string, null|Type> */
    private array $driverTypes = [];

    /**
     * Create a managers methods extension.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ClosureTypeFactory $closureTypeFactory,
    ) {
    }

    /**
     * Determine whether the manager's default driver has the public method the manager forwards.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (! $classReflection->is(Manager::class) || $classReflection->isAbstract()) {
            return false;
        }

        // Hypervel: keyed by the specialization, because a generic manager's declared driver can depend on its template types.
        $key = $classReflection->getCacheKey() . '-' . $methodName;

        if (isset($this->cache[$key])) {
            return true;
        }

        // Hypervel: @method tags and mixins declare the forwarded API, so they win over the booted driver.
        if (ReflectionHelper::hasMethodTag($classReflection, $methodName)) {
            return false;
        }

        // Hypervel: typed from the driver's declared type, while upstream builds the driver.
        $driverType = $this->getDriverType($classReflection);

        if ($driverType === null || ! $driverType->hasMethod($methodName)->yes()) {
            return false;
        }

        $method = $driverType->getMethod($methodName, new OutOfClassScope);

        // Hypervel: __call() runs in the manager, so it reaches only the driver's public methods.
        if (! $method->isPublic()) {
            return false;
        }

        // Hypervel: an instance call with argument values, unlike upstream's StaticMethodReflection, because
        // Manager has no __callStatic(). The driver's own static returns stay the driver's.
        $this->cache[$key] = new ProxiedMethodReflection(
            $classReflection,
            $method,
            static fn (Type $returnType): Type => $returnType,
        );

        return true;
    }

    /**
     * Get the driver method the manager forwards.
     */
    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        return $this->cache[$classReflection->getCacheKey() . '-' . $methodName];
    }

    /**
     * Get the declared type of the booted manager's default driver, resolving it once per manager.
     */
    private function getDriverType(ClassReflection $classReflection): ?Type
    {
        $key = $classReflection->getCacheKey();

        if (! array_key_exists($key, $this->driverTypes)) {
            $this->driverTypes[$key] = $this->resolveDriverType($classReflection);
        }

        return $this->driverTypes[$key];
    }

    /**
     * Resolve the declared type of the booted manager's default driver, following Manager::driver().
     */
    private function resolveDriverType(ClassReflection $classReflection): ?Type
    {
        $manager = $this->resolve($classReflection->getName());

        // The container can bind the manager to a subclass, whose methods then do the forwarding. A manager
        // that does not resolve still types an overriding driver() from its declaration.
        $managerReflection = $manager instanceof Manager && $manager::class !== $classReflection->getName()
            ? $this->reflectionProvider->getClass($manager::class)
            : $classReflection;

        // A Macroable or custom __call() keeps its own API instead of forwarding to the driver.
        if ($this->overrides($managerReflection, '__call')) {
            return null;
        }

        // An override's declared return types what it selects or creates. Its body is never interpreted.
        if ($this->overrides($managerReflection, 'driver')) {
            return $this->getReturnType($managerReflection, 'driver');
        }

        if (! $manager instanceof Manager) {
            return null;
        }

        try {
            $driver = $manager->getDefaultDriver();
        } catch (InvalidArgumentException) {
            // The manager has no default driver, so nothing is forwarded.
            return null;
        }

        if ($driver === null) {
            return null;
        }

        /** @var null|Closure $creator */
        $creator = (fn (): ?Closure => $this->customCreators[$driver] ?? null)->call($manager);

        if ($this->overrides($managerReflection, 'createDriver')) {
            $type = $this->getReturnType($managerReflection, 'createDriver');
        } elseif ($creator !== null) {
            $type = $this->overrides($managerReflection, 'callCustomCreator')
                ? $this->getReturnType($managerReflection, 'callCustomCreator')
                : $this->closureTypeFactory->fromClosureObject($creator)->getReturnType();
        } elseif ($this->overrides($managerReflection, 'getCreatorMethod')) {
            return null;
        } else {
            $method = (fn (): ?string => $this->getCreatorMethod($driver))->call($manager);

            if ($method === null) {
                return null;
            }

            $type = $this->getReturnType($managerReflection, $method);
        }

        // Manager::driver() keeps returning a driver it already created, even after extend() registers another creator.
        // Its class is exact, so a parent of the computed type does not fit either.
        $cached = $manager->getDrivers()[$driver] ?? null;

        if ($cached !== null && ! $type->isSuperTypeOf(ConstantTypeHelper::getTypeFromValue($cached))->yes()) {
            return null;
        }

        return $type;
    }

    /**
     * Determine whether the manager overrides the given Manager method.
     */
    private function overrides(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getNativeMethod($methodName)->getDeclaringClass()->getName() !== Manager::class;
    }

    /**
     * Get the declared return type of the manager's method.
     */
    private function getReturnType(ClassReflection $classReflection, string $methodName): Type
    {
        return $classReflection->getNativeMethod($methodName)->getOnlyVariant()->getReturnType();
    }
}
