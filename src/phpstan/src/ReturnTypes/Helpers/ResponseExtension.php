<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Http\Response;
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

/** @internal */
#[AutowiredService]
class ResponseExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersFactoryHelperCalls;

    private ?ObjectType $responseType = null;

    /**
     * Create a response() return type extension.
     */
    public function __construct(
        private readonly AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Determine whether the function is response().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'response';
    }

    /**
     * Get the factory for a call without arguments, and otherwise the response.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        // Upstream counts arguments, so it types every call with unpacked arguments as a response, though they may be empty.
        // Hypervel: the factory is the booted binding, as for app(ResponseFactory::class), rather than the contract.
        return $this->getFactoryHelperType(
            $functionCall,
            fn (): Type => $this->appMakeHelper->resolveAbstractType(ResponseFactory::class, $scope),
            $this->responseType ??= new ObjectType(Response::class),
        );
    }
}
