<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Support\ArrKeyLookup;
use Hypervel\Support\Arr;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function in_array;

/** @internal */
#[AutowiredService]
final class ArrGetDynamicStaticMethodReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Arr::class;
    }

    /**
     * Determine whether the method is get() or pull().
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['get', 'pull'], true);
    }

    /**
     * Get the type of the value the key can find, or of the default when it may be missing.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $arrayArg = $methodCall->getArg('array', 0);
        $keyArg = $methodCall->getArg('key', 1);

        if ($arrayArg === null || $keyArg === null || ! ArrKeyLookup::appliesTo($scope, $methodCall, [$methodReflection->getName(), ...ArrKeyLookup::GET_METHODS])) {
            return null;
        }

        $lookups = ArrKeyLookup::find($scope, $arrayArg->value, $keyArg->value);

        if ($lookups === null) {
            return null;
        }

        $types = [];
        $mayMiss = false;

        foreach ($lookups as $candidates) {
            $exists = TrinaryLogic::createNo();

            foreach ($candidates as [$found, $access]) {
                if (! $found->no()) {
                    $types[] = $scope->getType($access);
                }

                $exists = $exists->or($found);
            }

            $mayMiss = $mayMiss || ! $exists->yes();
        }

        if ($mayMiss) {
            $defaultArg = $methodCall->getArg('default', 2);

            // Arr::get() returns value($default), which calls a Closure default.
            $types[] = $defaultArg === null
                ? new NullType
                : $scope->getType(new FuncCall(new FullyQualified('value'), [new Arg($defaultArg->value)]));
        }

        return TypeCombinator::union(...$types);
    }
}
