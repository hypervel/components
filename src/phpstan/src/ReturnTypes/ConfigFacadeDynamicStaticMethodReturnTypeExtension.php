<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Internal\ConfigHelper;
use Hypervel\Support\Facades\Config;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Upstream's ConfigFacadeCollectionDynamicStaticMethodReturnTypeExtension types only
 * collection(); this also types get() and the typed getters, like the repository.
 */
#[Conditional(attribute: new AutowiredService, if: '%checkConfigTypes%')]
class ConfigFacadeDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConfigHelper $configHelper)
    {
    }

    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return Config::class;
    }

    /**
     * Determine whether the method returns a configuration value.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $this->configHelper->isConfigMethod($methodReflection);
    }

    /**
     * Get the type of the configuration value the method returns.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        return $this->configHelper->getMethodReturnType($methodCall, $scope);
    }
}
