<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types;

use Hypervel\PHPStan\Support\ArrKeyLookup;
use Hypervel\Support\Arr;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\StaticMethodParameterOutTypeExtension;
use PHPStan\Type\Type;

use function count;

/**
 * Removes the pulled key from the array passed by reference to Arr::pull().
 *
 * Keys in "dot" notation keep the declared type unless the literal key always exists.
 *
 * @internal
 */
#[AutowiredService]
class ArrPullParameterOutTypeExtension implements StaticMethodParameterOutTypeExtension
{
    /**
     * Determine whether the parameter is the array of Arr::pull().
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $methodReflection->getName() === 'pull'
            && $parameter->getName() === 'array'
            && $methodReflection->getDeclaringClass()->getName() === Arr::class;
    }

    /**
     * Get the array type without the pulled key.
     */
    public function getParameterOutTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        ParameterReflection $parameter,
        Scope $scope,
    ): ?Type {
        $arrayArg = $methodCall->getArg('array', 0);
        $keyArg = $methodCall->getArg('key', 1);

        // pull() removes the key through forget(), which checks keys through accessible() and exists().
        if ($arrayArg === null || $keyArg === null || ! ArrKeyLookup::appliesTo($scope, $methodCall, ['pull', 'forget', 'accessible', 'exists'])) {
            return null;
        }

        $arrayType = $scope->getType($arrayArg->value);

        if (! $arrayType->isArray()->yes()) {
            return null;
        }

        $lookups = ArrKeyLookup::find($scope, $arrayArg->value, $keyArg->value);

        if ($lookups === null) {
            return null;
        }

        foreach ($lookups as $candidates) {
            // Arr::forget() may remove a "dot" path instead of the literal key.
            if (count($candidates) > 1) {
                return null;
            }
        }

        // unset() on a list mishandles numeric strings like '0', so pass the integer key instead.
        return $arrayType->unsetOffset($scope->getType($keyArg->value)->toArrayKey());
    }
}
