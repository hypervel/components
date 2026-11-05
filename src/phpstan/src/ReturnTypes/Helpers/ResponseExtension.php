<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Http\Response;
use Hypervel\PHPStan\Concerns\InfersFactoryHelperCalls;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** @internal */
#[AutowiredService]
final class ResponseExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersFactoryHelperCalls;

    private ?ObjectType $responseFactoryType = null;

    private ?ObjectType $responseType = null;

    /**
     * Determine whether the function is response().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'response';
    }

    /**
     * Get the factory for a call without arguments, and otherwise the response.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        // Upstream counts arguments, so it types every call with unpacked arguments as a response, though they may be empty.
        return $this->getFactoryHelperType(
            $functionCall,
            $this->responseFactoryType ??= new ObjectType(ResponseFactory::class),
            $this->responseType ??= new ObjectType(Response::class),
        );
    }
}
