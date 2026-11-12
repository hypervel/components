<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\PHPStan\Concerns\InfersBoundHelperCalls;
use Hypervel\PHPStan\ReturnTypes\AppMakeHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Type url()'s generator as the booted binding, as for app(UrlGenerator::class), so the
 * generator's own methods, such as full(), and its macros are available.
 *
 * @internal
 */
#[AutowiredService]
class UrlExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersBoundHelperCalls;

    /**
     * Create a url() return type extension.
     */
    public function __construct(
        private readonly AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Determine whether the function is url().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'url';
    }

    /**
     * Get the generator for a null path, and otherwise the URL.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        return $this->getBoundHelperType(
            $functionReflection,
            $functionCall,
            $scope,
            UrlGenerator::class,
            fn (): Type => $this->appMakeHelper->resolveAbstractType(UrlGenerator::class, $scope),
        );
    }
}
