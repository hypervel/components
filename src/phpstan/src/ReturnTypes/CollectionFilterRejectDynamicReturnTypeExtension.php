<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\Support\Enumerable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Return_;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\Expr\TypeExpr;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

use function count;

#[AutowiredService]
class CollectionFilterRejectDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * The methods the extension narrows, with the methods each runs to decide which values to keep.
     */
    private const array METHODS = [
        'filter' => ['filter'],
        'reject' => ['reject', 'useAsCallable', 'filter'],
        'where' => ['where', 'operatorForWhere', 'useAsCallable', 'filter'],
    ];

    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Enumerable::class;
    }

    /**
     * Determine whether the method narrows the collection's values.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return isset(self::METHODS[$methodReflection->getName()]);
    }

    /**
     * Get the collection type with the values the callback keeps.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        /** @var 'filter'|'reject'|'where' $methodName */
        $methodName = $methodReflection->getName();
        $calledOnType = TypeCombinator::removeNull($scope->getType($methodCall->var));
        $classReflections = $calledOnType->getObjectClassReflections();

        // The result is rebuilt from the key and value types, so the collection's own templates must be exactly those two.
        // A collection that overrides the methods may keep other values.
        if (count($classReflections) !== 1
            || count($classReflections[0]->getTemplateTypeMap()->getTypes()) !== 2
            || ! CollectionHelper::usesFrameworkMethods($classReflections[0], self::METHODS[$methodName])) {
            return null;
        }

        $templateTypeMap = $methodReflection->getDeclaringClass()->getActiveTemplateTypeMap();
        $keyType = $templateTypeMap->getType('TKey');
        $valueType = $templateTypeMap->getType('TValue');

        // Upstream narrows a template's bound instead, which drops the template and the static return type in generic code.
        if ($keyType === null || $valueType === null || $keyType->hasTemplateOrLateResolvableType() || $valueType->hasTemplateOrLateResolvableType()) {
            return null;
        }

        if (count($methodCall->getArgs()) < 1) {
            $modifiedType = match ($methodName) {
                'filter' => TypeCombinator::removeFalsey($valueType),
                'reject' => TypeCombinator::removeTruthy($valueType),
                'where' => null,
            };

            return $modifiedType !== null
                ? new GenericObjectType($classReflections[0]->getName(), [$keyType, $modifiedType])
                : null;
        }

        $callbackArg = $methodCall->getArgs()[0]->value;
        $callbackType = $scope->getType($callbackArg);

        // where() and reject() call only callables that are not strings, and compare anything else.
        if ($methodName !== 'filter' && ! ($callbackType->isCallable()->yes() && $callbackType->isString()->no())) {
            return null;
        }

        if ($methodName === 'reject') {
            $callbackArg = $this->negate($callbackArg);

            if ($callbackArg === null) {
                return null;
            }
        }

        // The collection's callbacks receive the value and the key, as array_filter() callbacks do with
        // ARRAY_FILTER_USE_BOTH, so PHPStan's array_filter() narrowing applies to both. Upstream narrows
        // only the value, through a MutatingScope method outside PHPStan's backward compatibility promise.
        $filteredType = $scope->getType(new FuncCall(new FullyQualified('array_filter'), [
            new Arg(new TypeExpr(new ArrayType($keyType, $valueType))),
            new Arg($callbackArg),
            new Arg(new ConstFetch(new FullyQualified('ARRAY_FILTER_USE_BOTH'))),
        ]));

        return new GenericObjectType($classReflections[0]->getName(), [
            $filteredType->getIterableKeyType(),
            $filteredType->getIterableValueType(),
        ]);
    }

    /**
     * Get a callback that returns the opposite of an inline callback's single returned expression.
     */
    private function negate(Expr $callback): ?ArrowFunction
    {
        if ($callback instanceof ArrowFunction) {
            $expr = $callback->expr;
        } elseif ($callback instanceof Closure && count($callback->stmts) === 1 && $callback->stmts[0] instanceof Return_ && $callback->stmts[0]->expr !== null) {
            $expr = $callback->stmts[0]->expr;
        } else {
            return null;
        }

        return new ArrowFunction([
            'params' => $callback->params,
            'expr' => new BooleanNot($expr),
        ]);
    }
}
