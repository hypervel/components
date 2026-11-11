<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\PHPStan\Concerns\HasContainer;
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

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function is_string;
use function ucfirst;

/**
 * Types the guards that the booted auth manager creates.
 *
 * Mirrors AuthManager::resolve(): a registered custom creator wins, then the
 * manager's own create{Driver}Driver() method. Neither is invoked; their
 * declared return types describe the guard.
 */
#[AutowiredService]
class AuthGuardHelper
{
    use HasContainer;

    /**
     * The resolved guard types, keyed by driver.
     *
     * @var array<string, ?Type>
     */
    private array $driverTypes = [];

    /**
     * The class of the bound auth manager, or false when it is not an auth manager.
     *
     * @var null|class-string<AuthManager>|false
     */
    private false|string|null $managerClass = null;

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
     */
    public function getGuardType(?Arg $name, Scope $scope, Type $declaredType, bool $acceptsEnums = true): ?Type
    {
        $this->track($scope);

        $guardType = $this->getGuardsType($this->getGuardNames($name, $scope, $acceptsEnums));

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
     */
    public function getDefaultGuardType(ClassReflection $classReflection): ?Type
    {
        $this->track($classReflection);

        return $this->getGuardsType($this->getConfiguredGuardNames());
    }

    /**
     * Declare the dependent's dependency on the booted guards and the bound auth manager's declaration.
     *
     * @param ClassReflection|(DependencyTracker&Scope) $dependent
     */
    private function track(Scope|ClassReflection $dependent): void
    {
        $this->runtimeInputs->track($dependent);

        if ($this->managerClass === null) {
            $manager = $this->resolve('auth');
            $this->managerClass = $manager instanceof AuthManager ? $manager::class : false;
        }

        if ($this->managerClass !== false) {
            $this->runtimeInputs->trackClass($dependent, $this->managerClass);
        }
    }

    /**
     * Get the union of the given configured guards' types, or null when one of them is unknown.
     *
     * @param list<string> $names
     */
    private function getGuardsType(array $names): ?Type
    {
        $types = [];

        foreach ($names as $name) {
            $type = $this->getConfiguredGuardType($name);

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
     */
    private function getConfiguredGuardType(string $name): ?Type
    {
        $driver = $this->getContainer()->get('config')->get('auth.guards')[$name]['driver'] ?? null;

        if (! is_string($driver)) {
            return null;
        }

        if (! array_key_exists($driver, $this->driverTypes)) {
            $this->driverTypes[$driver] = $this->resolveDriverType($driver);
        }

        return $this->driverTypes[$driver];
    }

    /**
     * Resolve the type of the guard the given driver creates.
     */
    private function resolveDriverType(string $driver): ?Type
    {
        $manager = $this->resolve('auth');

        if (! $manager instanceof AuthManager) {
            return null;
        }

        /** @var ?Closure $creator */
        $creator = (fn (): ?Closure => $this->customCreators[$driver] ?? null)->call($manager);

        if ($creator !== null) {
            $type = $this->closureTypeFactory->fromClosureObject($creator)->getReturnType();
        } else {
            $class = $this->reflectionProvider->getClass($manager::class);
            $method = 'create' . ucfirst($driver) . 'Driver';

            if (! $class->hasNativeMethod($method)) {
                return null;
            }

            $type = $class->getNativeMethod($method)->getOnlyVariant()->getReturnType();
        }

        // An untyped creator keeps the declared guard type.
        return (new ObjectType(Guard::class))->isSuperTypeOf($type)->yes() ? $type : null;
    }
}
