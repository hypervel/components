<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\View\Factory;
use Hypervel\Contracts\View\View;
use Hypervel\PHPStan\Concerns\InfersFactoryHelperCalls;
use Hypervel\PHPStan\ReturnTypes\AppMakeHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Upstream's view() stub returns the factory for an explicit null view name, which
 * makes a view and fails. view() returns its factory only when called without arguments.
 *
 * @internal
 */
#[AutowiredService]
class ViewExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersFactoryHelperCalls;

    private ?ObjectType $viewType = null;

    /**
     * Create a view() return type extension.
     */
    public function __construct(
        private readonly AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Determine whether the function is view().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'view';
    }

    /**
     * Get the factory for a call without arguments, and otherwise the view.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        // The factory is the booted binding, as for app(Factory::class), rather than the contract.
        return $this->getFactoryHelperType(
            $functionCall,
            fn (): Type => $this->appMakeHelper->resolveAbstractType(Factory::class, $scope),
            $this->viewType ??= new ObjectType(View::class),
        );
    }
}
