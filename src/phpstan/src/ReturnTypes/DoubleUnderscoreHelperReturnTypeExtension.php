<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Concerns\InfersUnpackedHelperCalls;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeUtils;

/** @internal */
#[AutowiredService]
class DoubleUnderscoreHelperReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersUnpackedHelperCalls;

    /**
     * Determine whether the function is the __() helper.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === '__';
    }

    /**
     * Get the translation type for a key that cannot be null or comes from unpacked lists.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        if ($functionCall->getArgs()[0]->unpack ?? false) {
            $returnType = $this->getUnpackedHelperType($functionReflection, $functionCall, $scope);

            if ($returnType === null) {
                return null;
            }
        } else {
            $returnType = ParametersAcceptorSelector::selectFromArgs(
                $scope,
                $functionCall->getArgs(),
                $functionReflection->getVariants(),
                $functionReflection->getNamedArgumentsVariants(),
            )->getReturnType();
        }

        // Upstream returns null only for a call without arguments; a key that may be null keeps the
        // declared conditional type. A key that cannot be null returns a translation, whichever way it is passed.
        return $returnType->isNull()->no() ? TypeUtils::toBenevolentUnion($returnType) : $returnType;
    }
}
