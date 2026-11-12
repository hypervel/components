<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Config\Repository;
use Hypervel\PHPStan\Internal\ConfigHelper;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

#[Conditional(attribute: new AutowiredService, if: '%checkConfigTypes%')]
class ConfigRepositoryDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConfigHelper $configHelper)
    {
    }

    /**
     * Get the class whose method calls are typed, which also covers its implementations.
     */
    public function getClass(): string
    {
        return Repository::class;
    }

    /**
     * Determine whether the method returns a configuration value.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $this->configHelper->isConfigMethod($methodReflection);
    }

    /**
     * Get the type of the configuration value the method returns.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        return $this->configHelper->getMethodReturnType($methodCall, $scope);
    }
}
