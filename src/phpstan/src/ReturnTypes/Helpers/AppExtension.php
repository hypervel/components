<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\PHPStan\ReturnTypes\AppMakeHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;

#[AutowiredService]
class AppExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Create a new extension instance.
     */
    public function __construct(
        private AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Determine whether the function resolves a container entry.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'app' || $functionReflection->getName() === 'resolve';
    }

    /**
     * Get the type of the resolved container entry.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        // A missing or null abstract keeps the helper's declared Application return,
        // so upstream's separate no-argument branch is unnecessary.
        return $this->appMakeHelper->resolveTypeFromCall($functionCall, $scope);
    }
}
