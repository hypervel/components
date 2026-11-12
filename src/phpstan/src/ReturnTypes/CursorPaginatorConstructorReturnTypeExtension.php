<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Pagination\CursorPaginator;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;

use function strtolower;

/**
 * Type the keys of a new cursor paginator as integers.
 *
 * CursorPaginator::setItems() renumbers the items with values(), so the input's keys do not decide
 * TKey. PHPDoc cannot bind a class template to a fixed type, so the constructor leaves it at its bound.
 */
#[AutowiredService]
class CursorPaginatorConstructorReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return CursorPaginator::class;
    }

    /**
     * Determine whether the method is the constructor.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === '__construct';
    }

    /**
     * Get the paginator type with integer keys and the value type the arguments infer.
     */
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        // A subclass declares its own templates.
        if (! $methodCall->class instanceof Name || strtolower($scope->resolveName($methodCall->class)) !== strtolower(CursorPaginator::class)) {
            return null;
        }

        $variant = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants());
        $valueType = $variant->getResolvedTemplateTypeMap()->getType('TValue');

        if ($valueType === null || $valueType instanceof ErrorType) {
            return null;
        }

        // The stored collection keeps its default, as for any hand-built paginator.
        $defaults = $methodReflection->getDeclaringClass()->getTemplateTypeMap()->resolveToBounds()->getTypes();

        return new GenericObjectType(CursorPaginator::class, [new IntegerType, $valueType, $defaults['TCollection']]);
    }
}
