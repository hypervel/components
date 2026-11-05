<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\PHPStan\Reflection\StaticMethodReflection;
use Hypervel\Support\Facades\Storage;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;

#[AutowiredService]
class StorageMethodsClassReflectionExtension implements MethodsClassReflectionExtension
{
    /**
     * Create a storage methods extension.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Determine whether the Storage facade forwards the method to the filesystem manager or a disk.
     */
    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if ($classReflection->getName() !== Storage::class) {
            return false;
        }

        if ($this->reflectionProvider->getClass(FilesystemManager::class)->hasMethod($methodName)) {
            return true;
        }

        if ($this->reflectionProvider->getClass(FilesystemAdapter::class)->hasMethod($methodName)) {
            return true;
        }

        return false;
    }

    /**
     * Get the forwarded method as a static facade method.
     */
    public function getMethod(
        ClassReflection $classReflection,
        string $methodName,
    ): MethodReflection {
        if ($this->reflectionProvider->getClass(FilesystemManager::class)->hasMethod($methodName)) {
            return new StaticMethodReflection(
                $this->reflectionProvider->getClass(FilesystemManager::class)->getMethod($methodName, new OutOfClassScope),
            );
        }

        return new StaticMethodReflection(
            $this->reflectionProvider->getClass(FilesystemAdapter::class)->getMethod($methodName, new OutOfClassScope),
        );
    }
}
