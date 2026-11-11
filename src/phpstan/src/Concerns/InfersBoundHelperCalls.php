<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Closure;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** @internal */
trait InfersBoundHelperCalls
{
    use InfersUnpackedHelperCalls;

    /**
     * Get the helper's declared return type for the call, with the contract it resolves replaced by the booted binding.
     *
     * The declared conditional return already describes which arguments return the contract,
     * including an explicit null, named and maybe-null arguments. Unpacked lists that may be empty
     * also return it.
     *
     * @param class-string $contract
     * @param Closure(): Type $boundType called only when the call can return the contract
     */
    protected function getBoundHelperType(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
        string $contract,
        Closure $boundType,
    ): Type {
        $returnType = $this->getUnpackedHelperType($functionReflection, $functionCall, $scope)
            ?? ParametersAcceptorSelector::selectFromArgs(
                $scope,
                $functionCall->getArgs(),
                $functionReflection->getVariants(),
            )->getReturnType();

        $contractType = new ObjectType($contract);

        if ($contractType->isSuperTypeOf($returnType)->no()) {
            return $returnType;
        }

        return TypeCombinator::union(TypeCombinator::remove($returnType, $contractType), $boundType());
    }
}
