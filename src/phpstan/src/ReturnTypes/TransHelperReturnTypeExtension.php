<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Concerns\InfersUnpackedHelperCalls;
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
class TransHelperReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersUnpackedHelperCalls;

    /**
     * Determine whether the function is the trans() helper.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'trans';
    }

    /**
     * Get the translation type for a key that cannot be null or comes from unpacked lists.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        // Upstream returns the translator only for a call without arguments. An explicit
        // or possibly null key keeps the helper's declared conditional return type.
        $key = $functionCall->getArgs()[0] ?? null;

        if ($key !== null && $key->unpack) {
            return $this->getUnpackedHelperType($functionReflection, $functionCall, $scope);
        }

        if ($key === null || ! $scope->getType($key->value)->isNull()->no()) {
            return null;
        }

        return new BenevolentUnionType([
            new ArrayType(new MixedType, new MixedType),
            new StringType,
        ]);
    }
}
