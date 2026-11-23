<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\Support\Enumerable;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\BooleanType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;

#[AutowiredService]
class CollectionWhereNotNullDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Enumerable::class;
    }

    /**
     * Determine whether the method is whereNotNull().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'whereNotNull';
    }

    /**
     * Get the collection type without the values whereNotNull() removes.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $calledOnType = TypeCombinator::removeNull($scope->getType($methodCall->var));
        $classReflections = $calledOnType->getObjectClassReflections();

        // The result is rebuilt from the key and value types, so the collection's own templates must be exactly those two.
        // A collection that overrides whereNotNull() or the methods it runs may keep other values.
        if (count($classReflections) !== 1
            || count($classReflections[0]->getTemplateTypeMap()->getTypes()) !== 2
            || ! CollectionHelper::usesFrameworkMethods($classReflections[0], ['whereNotNull', 'where', 'operatorForWhere', 'useAsCallable', 'filter'])) {
            return null;
        }

        $templateTypeMap = $methodReflection->getDeclaringClass()->getActiveTemplateTypeMap();
        $keyType = $templateTypeMap->getType('TKey');
        $valueType = $templateTypeMap->getType('TValue');

        // Upstream narrows a template's bound instead, which drops the template and the static return type in generic code.
        if ($keyType === null || $valueType === null || $keyType->hasTemplateOrLateResolvableType() || $valueType->hasTemplateOrLateResolvableType()) {
            return null;
        }

        $nonNullType = TypeCombinator::removeNull($valueType);

        if (! $this->argumentIsString($methodCall, $scope)) {
            return new GenericObjectType($classReflections[0]->getName(), [$keyType, $nonNullType]);
        }

        // A keyed lookup on a scalar value finds nothing, so scalar values are removed.
        $scalarTypes = TypeCombinator::union(
            new StringType,
            new IntegerType,
            new FloatType,
            new BooleanType,
        );

        return new GenericObjectType($classReflections[0]->getName(), [$keyType, TypeCombinator::remove($nonNullType, $scalarTypes)]);
    }

    /**
     * Determine whether the call passes a key to compare.
     */
    public function argumentIsString(MethodCall $methodCall, Scope $scope): bool
    {
        if (count($methodCall->getArgs()) === 0) {
            return false;
        }

        $argType = $scope->getType($methodCall->getArgs()[0]->value);

        return $argType->isNull()->no();
    }
}
