<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** @internal */
trait InfersUnpackedHelperCalls
{
    /**
     * Get the type of a helper call that starts with unpacked lists, including the first parameter's default
     * when they may all be empty.
     *
     * Returns null when the call does not start with an unpack, or when a string-keyed unpack does not
     * definitely supply the first parameter, so PHPStan's result applies.
     *
     * @TODO PHPStan 2.3.5 resolves a conditional return type from an unpacked list's element type, so it
     * loses the default branch when the list may be empty. Remove the default once the minimum supported
     * PHPStan version keeps that branch.
     */
    protected function getUnpackedHelperType(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
        ?Type $defaultType = null,
    ): ?Type {
        $arguments = $functionCall->getArgs();

        if ($arguments === [] || ! $arguments[0]->unpack) {
            return null;
        }

        // PHP forbids positional arguments after an unpack, so the leading unpacks supply the first
        // parameter unless all of them are empty.
        $variants = $functionReflection->getVariants();
        $parametersAcceptor = ParametersAcceptorSelector::selectFromArgs($scope, $arguments, $variants);
        $returnType = $parametersAcceptor->getReturnType();

        foreach ($arguments as $argument) {
            if (! $argument->unpack) {
                break;
            }

            $type = $scope->getType($argument->value);

            if (! $type->isList()->yes()) {
                // String keys name parameters, so the unpack supplies the first parameter only when it has that key.
                $firstParameter = new ConstantStringType($parametersAcceptor->getParameters()[0]->getName());

                return $type->hasOffsetValueType($firstParameter)->yes() ? $returnType : null;
            }

            if ($type->isIterableAtLeastOnce()->yes()) {
                return $returnType;
            }
        }

        return TypeCombinator::union(
            $returnType,
            $defaultType ?? ParametersAcceptorSelector::selectFromArgs($scope, [], $variants)->getReturnType(),
        );
    }
}
