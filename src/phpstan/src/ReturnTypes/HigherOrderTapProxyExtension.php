<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Support\HigherOrderTapProxy;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/** @internal */
#[AutowiredService]
final class HigherOrderTapProxyExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return HigherOrderTapProxy::class;
    }

    /**
     * Determine whether the method is supported: every call returns the tapped object.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return true;
    }

    /**
     * Get the type of the tapped object.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): Type {
        $targetType = $scope->getType($methodCall->var)->getTemplateType(HigherOrderTapProxy::class, 'TTarget');

        return $targetType->getObjectClassNames() === [] ? new MixedType : $targetType;
    }
}
