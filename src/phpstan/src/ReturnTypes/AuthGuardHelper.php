<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Factory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Concerns\LoadsAuthModel;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PhpParser\Node\Arg;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_any;
use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function is_string;
use function ucfirst;

/**
 * Types the guards that the booted auth manager creates, and the users they return.
 *
 * Mirrors AuthManager::resolve(): a registered custom creator wins, then the
 * manager's own create{Driver}Driver() method. Neither is invoked; their
 * declared return types describe the guard.
 */
#[AutowiredService]
class AuthGuardHelper
{
    use HasContainer;
    use LoadsAuthModel;

    /**
     * The AuthManager methods that turn a guard name into a guard.
     *
     * @var list<string>
     */
    private const array GUARD_SELECTION_METHODS = ['guard', 'resolve', 'getConfig'];

    /**
     * The AuthManager methods that turn a guard's configured provider into its user provider.
     *
     * @var list<string>
     */
    private const array PROVIDER_SELECTION_METHODS = ['createUserProvider', 'getProviderConfiguration', 'createEloquentProvider', 'createDatabaseProvider'];

    /**
     * The resolved guard types, keyed by driver.
     *
     * @var array<string, ?Type>
     */
    private array $driverTypes = [];

    /**
     * The bound auth manager and its reflection, or false when it is not an auth manager.
     *
     * @var null|array{AuthManager, ClassReflection}|false
     */
    private false|array|null $manager = null;

    /**
     * Create a new helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ClosureTypeFactory $closureTypeFactory,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the type of the guard selected by the given name argument.
     *
     * Returns null when the call's declared return already describes the guard.
     *
     * @param DependencyTracker&Scope $scope
     * @param list<string> $receiverClasses the receiving classes, empty for a call the bound manager receives
     */
    public function getGuardType(?Arg $name, Scope $scope, Type $declaredType, bool $acceptsEnums = true, array $receiverClasses = []): ?Type
    {
        $manager = $this->getManager($scope, $receiverClasses);

        if ($manager === null || $this->overrides($manager[1], self::GUARD_SELECTION_METHODS)) {
            return null;
        }

        $guardType = $this->getGuardsType($manager, $this->getGuardNames($name, $scope, $acceptsEnums));

        if ($guardType === null) {
            return null;
        }

        // A factory or facade declaring a narrower guard keeps its declaration.
        return $declaredType->isSuperTypeOf($guardType)->yes() ? $guardType : null;
    }

    /**
     * Get the type of the current default guard, which shouldUse() can set to any configured guard, for what a class declares.
     *
     * Returns null when a configured guard's type is unknown.
     *
     * @param list<string> $receiverClasses the receiving classes, empty for calls the bound manager receives
     */
    public function getDefaultGuardType(ClassReflection $classReflection, array $receiverClasses = []): ?Type
    {
        $manager = $this->getManager($classReflection, $receiverClasses);

        if ($manager === null || $this->overrides($manager[1], self::GUARD_SELECTION_METHODS)) {
            return null;
        }

        return $this->getGuardsType($manager, $this->getConfiguredGuardNames());
    }

    /**
     * Get the user models the given guards return, or every configured guard returns.
     *
     * Returns an empty list when any of those guards' users cannot be established.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     * @param ?list<string> $guardNames
     * @param list<string> $receiverClasses the classes receiving the call that selects the guards, empty for the bound manager
     * @return list<class-string>
     */
    public function getUserModels(Scope|ClassReflection $dependent, ?array $guardNames = null, array $receiverClasses = []): array
    {
        $manager = $this->getManager($dependent, $receiverClasses);

        // The configured providers name a guard's users only while the manager selects
        // guards and their providers as AuthManager does.
        if ($manager === null || $this->overrides($manager[1], [...self::GUARD_SELECTION_METHODS, ...self::PROVIDER_SELECTION_METHODS])) {
            return [];
        }

        return $this->getAuthModels($manager[0], $this->getContainer()->get('config'), $guardNames);
    }

    /**
     * Get the bound auth manager and its reflection when calls on the receiving classes reach it, declaring the
     * dependent's dependency on the booted guards and the manager's declaration.
     *
     * The container resolves the factory contract to the bound manager. Another implementation or manager
     * subclass selects guards in its own way, with its own registered creators, even when it overrides nothing.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     * @param list<string> $receiverClasses
     * @return null|array{AuthManager, ClassReflection}
     */
    private function getManager(Scope|ClassReflection $dependent, array $receiverClasses): ?array
    {
        $this->runtimeInputs->track($dependent);

        if ($this->manager === null) {
            $manager = $this->resolve('auth');
            $this->manager = $manager instanceof AuthManager ? [$manager, $this->reflectionProvider->getClass($manager::class)] : false;
        }

        if ($this->manager === false) {
            return null;
        }

        $this->runtimeInputs->trackClass($dependent, $this->manager[1]->getName());

        foreach ($receiverClasses as $receiverClass) {
            if (! in_array($receiverClass, [Factory::class, AuthManager::class, $this->manager[1]->getName()], true)) {
                return null;
            }
        }

        return $this->manager;
    }

    /**
     * Determine whether the manager overrides any of the given AuthManager methods.
     *
     * @param list<string> $methodNames
     */
    private function overrides(ClassReflection $manager, array $methodNames): bool
    {
        return array_any(
            $methodNames,
            static fn (string $methodName): bool => $manager->getNativeMethod($methodName)->getDeclaringClass()->getName() !== AuthManager::class,
        );
    }

    /**
     * Get the union of the given configured guards' types, or null when one of them is unknown.
     *
     * @param array{AuthManager, ClassReflection} $manager
     * @param list<string> $names
     */
    private function getGuardsType(array $manager, array $names): ?Type
    {
        $types = [];

        foreach ($names as $name) {
            $type = $this->getConfiguredGuardType($manager, $name);

            if ($type === null) {
                return null;
            }

            $types[] = $type;
        }

        return $types === [] ? null : TypeCombinator::union(...$types);
    }

    /**
     * Get the names of the guards a name argument can select.
     *
     * AuthManager::guard() treats a missing, null or empty name as the current default,
     * which shouldUse() can set to any configured guard per coroutine. An unknown name
     * can also be any configured guard, because resolving any other name throws.
     *
     * @param DependencyTracker&Scope $scope
     * @return list<string>
     */
    public function getGuardNames(?Arg $name, Scope $scope, bool $acceptsEnums): array
    {
        $type = $name === null || $name->unpack ? null : $scope->getType($name->value);
        $values = [];

        foreach ($type?->getConstantStrings() ?? [] as $constantString) {
            $values[] = $constantString->getValue();
        }

        if ($values === [] && $acceptsEnums) {
            foreach ($type?->getEnumCases() ?? [] as $enumCase) {
                // enum_value() resolves a backed case to its value and a pure case to its name.
                foreach ($enumCase->getBackingValueType()?->getConstantScalarValues() ?? [$enumCase->getEnumCaseName()] as $value) {
                    $values[] = (string) $value;
                }
            }
        }

        if ($values === [] || in_array('', $values, true)) {
            $this->runtimeInputs->track($scope);

            return $this->getConfiguredGuardNames();
        }

        return $values;
    }

    /**
     * Get the names of the configured guards.
     *
     * @return list<string>
     */
    private function getConfiguredGuardNames(): array
    {
        $guards = $this->getContainer()->get('config')->get('auth.guards');

        $names = [];

        foreach (is_array($guards) ? array_keys($guards) : [] as $name) {
            $names[] = (string) $name;
        }

        return $names;
    }

    /**
     * Get the type of the configured guard with the given name.
     *
     * @param array{AuthManager, ClassReflection} $manager
     */
    private function getConfiguredGuardType(array $manager, string $name): ?Type
    {
        $driver = $this->getContainer()->get('config')->get('auth.guards')[$name]['driver'] ?? null;

        if (! is_string($driver)) {
            return null;
        }

        if (! array_key_exists($driver, $this->driverTypes)) {
            $this->driverTypes[$driver] = $this->resolveDriverType($manager, $driver);
        }

        return $this->driverTypes[$driver];
    }

    /**
     * Resolve the type of the guard the given driver creates.
     *
     * @param array{AuthManager, ClassReflection} $manager
     */
    private function resolveDriverType(array $manager, string $driver): ?Type
    {
        [$manager, $managerReflection] = $manager;

        /** @var ?Closure $creator */
        $creator = (fn (): ?Closure => $this->customCreators[$driver] ?? null)->call($manager);

        if ($creator !== null) {
            // An overriding callCustomCreator() creates the guard instead of the creator.
            $type = $this->overrides($managerReflection, ['callCustomCreator'])
                ? $managerReflection->getNativeMethod('callCustomCreator')->getOnlyVariant()->getReturnType()
                : $this->closureTypeFactory->fromClosureObject($creator)->getReturnType();
        } else {
            $method = 'create' . ucfirst($driver) . 'Driver';

            if (! $managerReflection->hasNativeMethod($method)) {
                return null;
            }

            $type = $managerReflection->getNativeMethod($method)->getOnlyVariant()->getReturnType();
        }

        // An untyped creator keeps the declared guard type.
        return (new ObjectType(Guard::class))->isSuperTypeOf($type)->yes() ? $type : null;
    }
}
