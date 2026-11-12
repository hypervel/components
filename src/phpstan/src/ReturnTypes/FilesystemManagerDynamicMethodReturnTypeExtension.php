<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Filesystem\FilesystemManager;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function in_array;

/**
 * The filesystem Factory contract keeps its declaration, because another implementation
 * can resolve disks in its own way.
 */
#[AutowiredService]
class FilesystemManagerDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private FilesystemDiskHelper $diskHelper)
    {
    }

    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return FilesystemManager::class;
    }

    /**
     * Determine whether the method returns a disk.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['disk', 'drive'], true);
    }

    /**
     * Get the type of the selected disk.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        return $this->diskHelper->getDiskType(
            $methodReflection->getName(),
            $methodCall->getArgs()[0] ?? null,
            $scope,
            $methodReflection->getVariants()[0]->getReturnType(),
            $scope->getType($methodCall->var)->getObjectClassNames(),
        );
    }
}
