<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\PHPStan\Internal\ConfigHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;

#[Conditional(attribute: new AutowiredService, if: '%checkConfigTypes%')]
class ConfigFunctionDynamicFunctionReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(private ConfigHelper $configHelper)
    {
    }

    /**
     * Determine whether the function is the config() helper.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'config';
    }

    /**
     * Get the type of the configuration value the helper returns for a key.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        return $this->configHelper->getValueType($functionCall, $scope);
    }
}
