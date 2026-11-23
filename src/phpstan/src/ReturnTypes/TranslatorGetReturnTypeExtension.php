<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Contracts\Translation\Translator;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/** @internal */
#[AutowiredService]
class TranslatorGetReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Get the class whose method calls are typed.
     */
    public function getClass(): string
    {
        return Translator::class;
    }

    /**
     * Determine whether the method is the translator's get().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'get';
    }

    /**
     * Get the translation type.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
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

        // Upstream always returns the union, which widens a translator whose get() declares a narrower type.
        if (! $declaredType->isSuperTypeOf($translation)->yes()) {
            return null;
        }

        return new BenevolentUnionType($translation->getTypes());
    }
}
