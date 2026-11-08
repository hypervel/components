<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/** @internal */
#[AutowiredService]
class DoubleUnderscoreHelperReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Determine whether the function is the __() helper.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === '__';
    }

    /**
     * Get the translation type for a key that cannot be null.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        // Upstream returns null only for a call without arguments. An explicit or
        // possibly null key keeps the helper's declared conditional return type.
        $key = $functionCall->getArgs()[0] ?? null;

        if ($key === null || $key->unpack || ! $scope->getType($key->value)->isNull()->no()) {
            return null;
        }

        return new BenevolentUnionType([
            new ArrayType(new MixedType, new MixedType),
            new StringType,
        ]);
    }
}
