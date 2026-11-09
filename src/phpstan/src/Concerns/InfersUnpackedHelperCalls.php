<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** @internal */
trait InfersUnpackedHelperCalls
{
    /**
     * Get the type of a helper call whose unpacked lists may all be empty, leaving the first parameter at its default.
     *
     * Returns null when the call is not of that form, so the declared return type applies.
     *
     * @TODO PHPStan 2.3.5 resolves a conditional return type from an unpacked list's element type, so it
     * loses the default branch when the list may be empty. Remove this once the minimum supported PHPStan
     * version keeps that branch. String-keyed unpacks still get PHPStan's result.
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
        foreach ($arguments as $argument) {
            if (! $argument->unpack) {
                break;
            }

            $type = $scope->getType($argument->value);

            if (! $type->isList()->yes() || $type->isIterableAtLeastOnce()->yes()) {
                return null;
            }
        }

        $variants = $functionReflection->getVariants();

        return TypeCombinator::union(
            ParametersAcceptorSelector::selectFromArgs($scope, $arguments, $variants)->getReturnType(),
            $defaultType ?? ParametersAcceptorSelector::selectFromArgs($scope, [], $variants)->getReturnType(),
        );
    }
}
