<?php

declare(strict_types=1);

namespace AttributeServices\Extensions;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

#[AutowiredService]
class IdentifierReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Determine whether the function is the fixture's identifier().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'AttributeServices\identifier';
    }

    /**
     * Get the integer type of the identifier.
     */
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
    {
        return new IntegerType;
    }
}
