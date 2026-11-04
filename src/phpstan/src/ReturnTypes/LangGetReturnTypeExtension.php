<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\Facades\Lang;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/** @internal */
#[AutowiredService]
final class LangGetReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Get the class whose static calls are typed.
     */
    public function getClass(): string
    {
        return Lang::class;
    }

    /**
     * Determine whether the method is Lang::get().
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'get';
    }

    /**
     * Get the translation type.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $translation = new UnionType([
            new ArrayType(new MixedType, new MixedType),
            new StringType,
        ]);

        $declaredType = ParametersAcceptorSelector::selectFromArgs(
            $scope,
            $methodCall->getArgs(),
            $methodReflection->getVariants(),
        )->getReturnType();

        // Upstream always returns the union, which widens a facade whose get() declares a narrower type.
        if (! $declaredType->isSuperTypeOf($translation)->yes()) {
            return null;
        }

        return new BenevolentUnionType($translation->getTypes());
    }
}
