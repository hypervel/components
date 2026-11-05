<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\Facades\Storage;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function in_array;

/**
 * Upstream types every disk as FilesystemAdapter. Pooled and custom disks are other
 * filesystems, so each disk is typed from the booted configuration instead, and
 * cloud() is not supported.
 */
#[AutowiredService]
class StorageDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private FilesystemDiskHelper $diskHelper)
    {
    }

    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return Storage::class;
    }

    /**
     * Determine whether the method returns a disk.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['disk', 'drive'], strict: true);
    }

    /**
     * Get the type of the selected disk.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        return $this->diskHelper->getDiskType(
            $methodReflection->getName(),
            $methodCall->getArgs()[0] ?? null,
            $scope,
            $methodReflection->getVariants()[0]->getReturnType(),
        );
    }
}
