<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Closure;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\InstanceNames;
use Hypervel\Support\Str;
use InvalidArgumentException;
use PhpParser\Node\Arg;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ClosureTypeFactory;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function array_key_exists;
use function in_array;
use function is_string;

/**
 * Types the disks that the booted filesystem manager creates.
 *
 * Mirrors FilesystemManager's disk resolution: the selected disk's configuration
 * decides between a custom creator, a pooled disk and the manager's own
 * create{Driver}Driver() method. None of them is invoked; their declared return
 * types describe the disk, because building a disk can need credentials or I/O.
 */
#[AutowiredService]
class FilesystemDiskHelper
{
    use HasContainer;

    /**
     * The resolved disk types, keyed by disk name.
     *
     * @var array<string, ?Type>
     */
    private array $diskTypes = [];

    /**
     * The bound manager and its reflection, or false when neither follows the stock resolution.
     *
     * @var null|array{FilesystemManager, ClassReflection}|false
     */
    private false|array|null $manager = null;

    /**
     * Create a new helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ClosureTypeFactory $closureTypeFactory,
    ) {
    }

    /**
     * Get the type of the disk a disk() or drive() call selects.
     *
     * Returns null when the call's declared return already describes the disk.
     *
     * @param list<string> $receiverClasses the receiving manager classes, empty for the Storage facade
     */
    public function getDiskType(string $methodName, ?Arg $name, Scope $scope, Type $declaredType, array $receiverClasses = []): ?Type
    {
        $manager = $this->getManager();

        if ($manager === null) {
            return null;
        }

        [, $managerReflection] = $manager;

        foreach ($receiverClasses as $receiverClass) {
            // A subclass other than the bound one resolves its disks in its own way.
            if ($receiverClass !== FilesystemManager::class && $receiverClass !== $managerReflection->getName()) {
                return null;
            }
        }

        // drive() delegates to disk(), so an override of either replaces the stock selection.
        if ($this->overrides($managerReflection, 'disk') || $this->overrides($managerReflection, $methodName)) {
            return null;
        }

        $names = InstanceNames::fromArgument($name, $scope);

        if ($names === null) {
            return null;
        }

        $types = [];

        foreach ($names as $diskName) {
            $type = $this->getNamedDiskType($manager, $diskName);

            if ($type === null) {
                return null;
            }

            $types[] = $type;
        }

        $diskType = TypeCombinator::union(...$types);

        // A facade or manager declaring a narrower disk keeps its declaration.
        return $declaredType->isSuperTypeOf($diskType)->yes() ? $diskType : null;
    }

    /**
     * Get the type of the default disk, which the manager forwards unknown calls to.
     */
    public function getDefaultDiskType(): ?Type
    {
        $manager = $this->getManager();

        // __call() forwards to disk(), so an override of either replaces the stock forwarding.
        if ($manager === null || $this->overrides($manager[1], '__call') || $this->overrides($manager[1], 'disk')) {
            return null;
        }

        return $this->getNamedDiskType($manager, null);
    }

    /**
     * Get the type of the disk with the given name, resolving it once per name.
     *
     * @param array{FilesystemManager, ClassReflection} $manager
     */
    private function getNamedDiskType(array $manager, ?string $name): ?Type
    {
        if ($name === null || $name === '') {
            try {
                $name = $manager[0]->getDefaultDriver();
            } catch (InvalidArgumentException) {
                // There is no configured default disk.
                return null;
            }
        }

        if (! array_key_exists($name, $this->diskTypes)) {
            $this->diskTypes[$name] = $this->resolveDiskType($manager, $name);
        }

        return $this->diskTypes[$name];
    }

    /**
     * Resolve the declared type of the named disk, following FilesystemManager::resolveConstructionDescriptor().
     *
     * @param array{FilesystemManager, ClassReflection} $manager
     */
    private function resolveDiskType(array $manager, string $name): ?Type
    {
        [$manager, $managerReflection] = $manager;

        try {
            // getConfig() and prepareConstructionDescriptor() are internal to FilesystemManager. The latter
            // collapses scoped disks into the disk they scope, so their stock construction is that disk's.
            $facts = Closure::bind(function (string $name): array {
                $config = $this->getConfig($name);
                $driver = $this->prepareConstructionDescriptor($config, $name)['config']['driver'] ?? null;

                return [
                    'scoped' => ($config['driver'] ?? null) === 'scoped',
                    'driver' => $driver,
                    'creator' => is_string($driver) ? ($this->customCreators[$driver] ?? null) : null,
                    'poolable' => in_array($driver, $this->poolableDrivers, true),
                    'cached' => $this->disks[$name] ?? null,
                ];
            }, $manager, FilesystemManager::class)($name);
        } catch (InvalidArgumentException) {
            // The disk is not configured, or its configuration is invalid.
            return null;
        }

        $driver = $facts['driver'];

        if (! is_string($driver) || $driver === '' || ($facts['scoped'] && $this->overrides($managerReflection, 'createScopedDriver'))) {
            return null;
        }

        if ($facts['creator'] instanceof Closure) {
            $type = match (true) {
                $facts['poolable'] => $this->getReturnType($managerReflection, 'createDriverPooledDisk'),
                $this->overrides($managerReflection, 'callCustomCreator') => $this->getReturnType($managerReflection, 'callCustomCreator'),
                default => $this->closureTypeFactory->fromClosureObject($facts['creator'])->getReturnType(),
            };
        } elseif ($facts['poolable'] && in_array($driver, ['s3', 'gcs'], true)) {
            $type = $this->getReturnType($managerReflection, 'createClientPooledDisk');
        } else {
            $method = 'create' . Str::studly($driver) . 'Driver';

            if (! $managerReflection->hasNativeMethod($method)) {
                // The manager does not support the driver.
                return null;
            }

            $type = $this->getReturnType($managerReflection, $facts['poolable'] ? 'createDriverPooledDisk' : $method);
        }

        // disk() keeps returning a disk it already resolved or was given through set() or a fake.
        // Its class is exact, so a parent of the computed type does not fit either.
        if ($facts['cached'] !== null && ! $type->isSuperTypeOf(ConstantTypeHelper::getTypeFromValue($facts['cached']))->yes()) {
            return null;
        }

        return $type;
    }

    /**
     * Get the bound filesystem manager and its reflection when it resolves disks the stock way.
     *
     * @return null|array{FilesystemManager, ClassReflection}
     */
    private function getManager(): ?array
    {
        if ($this->manager === null) {
            $manager = $this->resolve('filesystem');
            $this->manager = false;

            if ($manager instanceof FilesystemManager) {
                $reflection = $this->reflectionProvider->getClass($manager::class);

                // These replace how a selected disk name becomes a disk.
                if (! $this->overrides($reflection, 'get') && ! $this->overrides($reflection, 'resolve') && ! $this->overrides($reflection, 'getConfig')) {
                    $this->manager = [$manager, $reflection];
                }
            }
        }

        return $this->manager === false ? null : $this->manager;
    }

    /**
     * Determine whether the manager overrides the given FilesystemManager method.
     */
    private function overrides(ClassReflection $classReflection, string $methodName): bool
    {
        return $classReflection->getNativeMethod($methodName)->getDeclaringClass()->getName() !== FilesystemManager::class;
    }

    /**
     * Get the declared return type of the manager's method.
     */
    private function getReturnType(ClassReflection $classReflection, string $methodName): Type
    {
        return $classReflection->getNativeMethod($methodName)->getOnlyVariant()->getReturnType();
    }
}
