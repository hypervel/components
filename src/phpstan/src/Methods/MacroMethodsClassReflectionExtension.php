<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\PHPStan\Reflection\ProxiedMethodReflection;
use Hypervel\PHPStan\Reflection\StaticMethodReflection;
use Hypervel\PHPStan\ReturnTypes\AuthGuardHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Traits\Macroable;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\Type;
use ReflectionException;
use Throwable;

use function array_any;
use function array_key_exists;
use function array_keys;
use function class_exists;
use function explode;
use function in_array;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function str_contains;

#[AutowiredService]
class MacroMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /** @var array<string, false|MethodReflection> */
    private array $methods = [];

    /** @var array<string, array<string, bool>> */
    private array $traitCache = [];

    /** @var array<string, array{class-string[], null|string}> */
    private array $macroSources = [];

    /**
     * Create a macro methods extension.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ClosureTypeFactory $closureTypeFactory,
        private WithHeadExtension $withHeadExtension,
        private AuthGuardHelper $authGuardHelper,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Determine whether a macro registered for the class types the method.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if ($this->findMacro($classReflection, $methodName) === null) {
            return false;
        }

        // Hypervel: Head's withHead() signature and an @method tag on the class or the macro's host type the
        // macro more precisely than its callable, whose declared signature is all the analysis reads, such as
        // Request::validate()'s array and the Eloquent builder's $this returns. MacroThrowTypeExtension adds the
        // macro's exceptions. A mixin does not count, because the macro runs before a call reaches the mixin.
        return ! $this->withHeadExtension->hasMethod($classReflection, $methodName)
            && ! $this->hasMethodTag($classReflection, $methodName);
    }

    /**
     * Get the macro found for the class.
     */
    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        // hasMethod() returns true only for a found macro.
        /** @var MethodReflection $method */
        $method = $this->findMacro($classReflection, $methodName);

        return $method;
    }

    /**
     * Get the exception type of the macro registered for the class's method.
     */
    public function getThrowType(ClassReflection $classReflection, string $methodName): ?Type
    {
        return $this->findMacro($classReflection, $methodName)?->getThrowType();
    }

    /**
     * Find the macro registered for the class's method.
     */
    private function findMacro(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $cacheKey = $classReflection->getName() . '-' . $methodName;

        if (array_key_exists($cacheKey, $this->methods)) {
            return $this->methods[$cacheKey] === false ? null : $this->methods[$cacheKey];
        }

        $methodReflection = $this->forwardsToGuard($classReflection)
            ? $this->findGuardMacro($classReflection, $methodName)
            : $this->findHostMacro($classReflection, $methodName);

        $this->methods[$cacheKey] = $methodReflection ?? false;

        return $methodReflection;
    }

    /**
     * Find the macro registered on the classes whose macros apply to the given class.
     *
     * @throws ReflectionException
     * @throws ShouldNotHappenException
     * @throws MissingMethodFromReflectionException
     */
    private function findHostMacro(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        [$classNames, $macroTraitProperty] = $this->getMacroSources($classReflection);

        $methodReflection = null;

        if ($classNames !== [] && $macroTraitProperty) {
            foreach ($classNames as $className) {
                $macroClassReflection = $this->reflectionProvider->getClass($className);

                if (! $macroClassReflection->getNativeReflection()->hasProperty($macroTraitProperty)) {
                    continue;
                }

                $refProperty = $macroClassReflection->getNativeReflection()->getProperty($macroTraitProperty);

                // Hypervel: macros are registered at runtime, so the registry of an owner the booted application
                // never loaded, such as a class that only analyzed files declare, is unavailable.
                if (! class_exists($refProperty->getDeclaringClass()->getName(), false)) {
                    continue;
                }

                $macros = $refProperty->getValue();

                if (! array_key_exists($methodName, $macros)) {
                    continue;
                }

                $macroDefinition = $macros[$methodName];

                // Hypervel: Macroable also accepts an invokable object, which it calls directly.
                if (is_object($macroDefinition) && ! $macroDefinition instanceof Closure) {
                    $macroDefinition = [$macroDefinition, '__invoke'];
                }

                // Hypervel: Macroable dispatches every macro through both __call() and __callStatic() with its
                // argument values, so each form is callable both ways (StaticMethodReflection for methods).
                if (is_string($macroDefinition)) {
                    if (str_contains($macroDefinition, '::')) {
                        $macroDefinition = explode('::', $macroDefinition, 2);
                        $macroClassName = $macroDefinition[0];

                        if (! $this->reflectionProvider->hasClass($macroClassName) || ! $this->reflectionProvider->getClass($macroClassName)->hasNativeMethod($macroDefinition[1])) {
                            throw new ShouldNotHappenException('Class ' . $macroClassName . ' does not exist');
                        }

                        $methodReflection = new StaticMethodReflection(
                            $this->reflectionProvider->getClass($macroClassName)->getNativeMethod($macroDefinition[1]),
                        );
                    } elseif (is_callable($macroDefinition)) {
                        $methodReflection = new Macro(
                            $macroClassReflection,
                            $methodName,
                            $this->closureTypeFactory->fromClosureObject(Closure::fromCallable($macroDefinition)),
                        );

                        $methodReflection->setIsStatic(true);
                    } else {
                        throw new ShouldNotHappenException('Function ' . $macroDefinition . ' does not exist');
                    }
                } elseif (is_array($macroDefinition)) {
                    if (is_string($macroDefinition[0])) {
                        $macroClassName = $macroDefinition[0];
                    } else {
                        $macroClassName = $macroDefinition[0]::class;
                    }

                    if (! $this->reflectionProvider->hasClass($macroClassName) || ! $this->reflectionProvider->getClass($macroClassName)->hasNativeMethod($macroDefinition[1])) {
                        throw new ShouldNotHappenException('Class ' . $macroClassName . ' does not exist');
                    }

                    $methodReflection = new StaticMethodReflection(
                        $this->reflectionProvider->getClass($macroClassName)->getNativeMethod($macroDefinition[1]),
                    );
                } else {
                    $methodReflection = new Macro(
                        $macroClassReflection,
                        $methodName,
                        $this->closureTypeFactory->fromClosureObject($macroDefinition),
                    );

                    $methodReflection->setIsStatic(true);
                }

                break;
            }
        }

        return $methodReflection;
    }

    /**
     * Determine whether the class's calls reach AuthManager's own __call(), which forwards them to the current guard.
     */
    private function forwardsToGuard(ClassReflection $classReflection): bool
    {
        // A facade, such as Auth or a custom one over the auth binding, forwards to its booted root, which getMacroSources() resolves.
        if ($classReflection->is(Facade::class)) {
            [$classNames] = $this->getMacroSources($classReflection);

            if ($classNames === []) {
                return false;
            }

            $classReflection = $this->reflectionProvider->getClass($classNames[0]);
        }

        // A subclass's Macroable or custom __call() keeps its own API.
        return $classReflection->is(AuthManager::class)
            && $classReflection->getNativeMethod('__call')->getDeclaringClass()->getName() === AuthManager::class;
    }

    /**
     * Find a guard macro that the class forwards to the current guard.
     *
     * Hypervel: replaces upstream's fixed SessionGuard and RequestGuard hosts, which claimed a macro the current
     * guard may lack. shouldUse() can make any configured guard the current one, so each must have a public
     * method of that name, natively or as a macro. At least one must supply it as a macro; the guards' other
     * methods are not this extension's.
     */
    private function findGuardMacro(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        $guardType = $this->authGuardHelper->getDefaultGuardType($classReflection);

        if ($guardType === null) {
            return null;
        }

        // The guard classes supply this class's forwarded methods, so its callers depend on them.
        foreach ($guardType->getObjectClassNames() as $guardClass) {
            $this->runtimeInputs->trackClass($classReflection, $guardClass);
        }

        if (! $guardType->hasMethod($methodName)->yes()) {
            return null;
        }

        $suppliesMacro = array_any(
            $guardType->getObjectClassReflections(),
            fn (ClassReflection $guard): bool => $this->findMacro($guard, $methodName) !== null,
        );

        if (! $suppliesMacro) {
            return null;
        }

        // Each guard's own lookup keeps its signature, including a native method or an @method tag.
        $method = $guardType->getMethod($methodName, new OutOfClassScope);

        // AuthManager::__call() runs outside the guard, so it reaches only public methods.
        if (! $method->isPublic()) {
            return null;
        }

        // The facade's call is static, while AuthManager has no __callStatic(), so its own calls are instance calls.
        return $classReflection->is(Facade::class)
            ? new StaticMethodReflection($method)
            : new ProxiedMethodReflection($classReflection, $method, static fn (Type $returnType): Type => $returnType);
    }

    /**
     * Determine whether the class or the macro's host declares an @method tag for the method.
     */
    private function hasMethodTag(ClassReflection $classReflection, string $methodName): bool
    {
        [$classNames] = $this->getMacroSources($classReflection);

        foreach ([$classReflection->getName(), ...$classNames] as $className) {
            foreach ($this->reflectionProvider->getClass($className)->getAncestors() as $ancestor) {
                if (array_key_exists($methodName, $ancestor->getMethodTags())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine which classes' macro definitions apply to the given class.
     *
     * This only depends on the class itself, so it is memoized — the trait
     * scans and facade root lookups involved are too expensive to repeat
     * for every method lookup.
     *
     * Only a macro registry or a facade root comes from the boot, so only those
     * classes depend on it; any other class has no macros whatever the boot
     * registers. A class's dependency holds for the whole process, so declaring
     * it here, once, covers every lookup.
     *
     * @return array{class-string[], null|string}
     */
    private function getMacroSources(ClassReflection $classReflection): array
    {
        $cacheKey = $classReflection->getName();

        if (array_key_exists($cacheKey, $this->macroSources)) {
            return $this->macroSources[$cacheKey];
        }

        /** @var class-string[] $classNames */
        $classNames = [];
        $macroTraitProperty = null;

        // REMOVED: upstream's contract branch, which gives a Hypervel\Contracts interface the macros of its bound
        // implementation, as ContractsMethodsExtension gives it the implementation's methods. Another implementation
        // need not have them; explicit resolution such as app() or response() is typed from the binding instead.
        if (
            $this->hasIndirectTraitUse($classReflection, Macroable::class)
            || $classReflection->is(Builder::class)
            || $classReflection->is(QueryBuilder::class)
        ) {
            $this->runtimeInputs->track($classReflection);

            // REMOVED: upstream's extra base Builder registry for subclasses. The subclass's reflected $macros
            // already is the inherited registry, unless the subclass redeclares it to keep its own.
            $classNames = [$classReflection->getName()];
            $macroTraitProperty = 'macros';
        } elseif ($classReflection->is(Facade::class)) {
            // REMOVED: upstream's fixed hosts for the Auth and Cache facades. findGuardMacro() handles Auth. The
            // Cache facade's root, CacheManager, is not Macroable, and the facade's mixins already reach the
            // store Repository's macros.
            $this->runtimeInputs->track($classReflection);

            $facadeClass = $classReflection->getName();

            // The facade's own accessor selects its root.
            $this->runtimeInputs->trackSources($classReflection, $facadeClass, Facade::class);

            $concrete = null;

            try {
                $concrete = $facadeClass::getFacadeRoot();
            } catch (Throwable) {
            }

            if (is_object($concrete)) {
                $this->runtimeInputs->trackClass($classReflection, $concrete::class);

                $classNames = [$concrete::class];
                $macroTraitProperty = 'macros';
            }
        }

        return $this->macroSources[$cacheKey] = [$classNames, $macroTraitProperty];
    }

    /**
     * Determine whether the class or one of its ancestors uses the trait.
     */
    private function hasIndirectTraitUse(ClassReflection $class, string $traitName): bool
    {
        $className = $class->getName();

        if (array_key_exists($className, $this->traitCache) && array_key_exists($traitName, $this->traitCache[$className])) {
            return $this->traitCache[$className][$traitName];
        }

        $this->traitCache[$className][$traitName] = in_array($traitName, array_keys($class->getTraits(true)), true);

        return $this->traitCache[$className][$traitName];
    }
}
