<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Filesystem\FilesystemManager;
use Hypervel\PHPStan\Reflection\StaticMethodReflection;
use Hypervel\PHPStan\ReturnTypes\FilesystemDiskHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Facades\Storage;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

#[AutowiredService]
class StorageMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /**
     * Create a storage methods extension.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private FilesystemDiskHelper $diskHelper,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Determine whether the Storage facade forwards the method to the filesystem manager or a disk.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if ($classReflection->getName() !== Storage::class) {
            return false;
        }

        return $this->getTargetType($classReflection, $methodName)->hasMethod($methodName)->yes();
    }

    /**
     * Get the forwarded method as a static facade method.
     */
    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        return new StaticMethodReflection($this->getTargetType($classReflection, $methodName)->getMethod($methodName, new OutOfClassScope));
    }

    /**
     * Get the type whose method a facade call runs: the manager's own, else the default disk's.
     *
     * Hypervel: upstream checks the manager with its adapter mixins first, then FilesystemAdapter. Those
     * mixins would shadow the default disk, whose methods differ for a pooled disk, so the manager's
     * declared mixins only apply when the default disk cannot be typed.
     */
    private function getTargetType(ClassReflection $classReflection, string $methodName): Type
    {
        $manager = $this->reflectionProvider->getClass(FilesystemManager::class);

        if ($manager->hasNativeMethod($methodName)) {
            return new ObjectType(FilesystemManager::class);
        }

        $diskType = $this->diskHelper->getDefaultDiskType($classReflection);

        if ($diskType === null) {
            return new ObjectType(FilesystemManager::class);
        }

        // The disk classes supply the facade's forwarded methods, so its callers depend on them.
        foreach ($diskType->getObjectClassNames() as $diskClass) {
            $this->runtimeInputs->trackClass($classReflection, $diskClass);
        }

        return $diskType;
    }
}
