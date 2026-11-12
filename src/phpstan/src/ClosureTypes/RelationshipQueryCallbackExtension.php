<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ClosureTypes;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Types\BuilderOf\BuilderOfType;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Type\ClosureType;
use PHPStan\Type\MethodParameterClosureTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticMethodParameterClosureTypeExtension;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\TypeUtils;

use function in_array;

#[AutowiredService]
class RelationshipQueryCallbackExtension implements MethodParameterClosureTypeExtension, StaticMethodParameterClosureTypeExtension
{
    /**
     * Create the relationship query callback extension.
     */
    public function __construct(private BuilderHelper $builderHelper)
    {
    }

    /**
     * Determine whether the parameter is a polymorphic relationship query callback.
     */
    public function isMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        if ($this->queriedModel($methodReflection->getDeclaringClass()) === null) {
            return false;
        }

        return match ($parameter->getName()) {
            'callback' => in_array($methodReflection->getName(), ['hasMorph', 'doesntHaveMorph', 'whereHasMorph', 'orWhereHasMorph', 'whereDoesntHaveMorph', 'orWhereDoesntHaveMorph'], true),
            'column' => in_array($methodReflection->getName(), ['whereMorphRelation', 'orWhereMorphRelation', 'whereMorphDoesntHaveRelation', 'orWhereMorphDoesntHaveRelation'], true),
            default => false,
        };
    }

    /**
     * Determine whether the parameter is a polymorphic relationship query callback.
     */
    public function isStaticMethodSupported(MethodReflection $methodReflection, ParameterReflection $parameter): bool
    {
        return $this->isMethodSupported($methodReflection, $parameter);
    }

    /**
     * Return the callback type for an instance call.
     */
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, ParameterReflection $parameter, Scope $scope): ?Type
    {
        return $this->getCallbackType($methodReflection, $methodCall, $parameter, $scope);
    }

    /**
     * Return the callback type for a static call.
     */
    public function getTypeFromStaticMethodCall(MethodReflection $methodReflection, StaticCall $methodCall, ParameterReflection $parameter, Scope $scope): ?Type
    {
        return $this->getCallbackType($methodReflection, $methodCall, $parameter, $scope);
    }

    /**
     * Return the model a forwarded or native builder method queries.
     *
     * Models and relations declare the builder methods they forward, so their calls reach this
     * extension with the model or relation as the declaring class.
     */
    private function queriedModel(ClassReflection $declaringClass): ?Type
    {
        if ($declaringClass->is(Model::class)) {
            return new ObjectType($declaringClass->getName());
        }

        return $declaringClass->getAncestorWithClassName(Builder::class)?->getActiveTemplateTypeMap()->getType('TModel')
            ?? $declaringClass->getAncestorWithClassName(Relation::class)?->getActiveTemplateTypeMap()->getType('TRelatedModel');
    }

    /**
     * Return the callback type with the builders of the queried morph types.
     */
    private function getCallbackType(MethodReflection $methodReflection, MethodCall|StaticCall $call, ParameterReflection $parameter, Scope $scope): ?Type
    {
        $arguments = [];

        foreach ($call->getArgs() as $position => $argument) {
            // An unpacked argument hides which values the relation and types are.
            if ($argument->unpack) {
                return null;
            }

            $arguments[$argument->name?->toString() ?? $position] = $argument->value;
        }

        $relation = $arguments['relation'] ?? $arguments[0] ?? null;
        $types = $arguments['types'] ?? $arguments[1] ?? null;
        $model = $this->queriedModel($methodReflection->getDeclaringClass());

        if ($relation === null || $types === null || $model === null) {
            return null;
        }

        // The callbacks run on each queried model's newQueryWithoutRelationships().
        $fallback = [];

        foreach (TypeUtils::flattenTypes($scope->getType($relation)) as $relationType) {
            $fallback[] = $relationType->isString()->yes()
                ? (new BuilderOfType($model, $this->builderHelper, $relationType, modelQuery: true))->resolve()
                : (new BuilderOfType($relationType->getTemplateType(Relation::class, 'TRelatedModel'), $this->builderHelper, modelQuery: true))->resolve();
        }

        $fallback = TypeCombinator::union(...$fallback);
        $builders = [];

        foreach (TypeUtils::flattenTypes($scope->getType($types)) as $type) {
            if ($type->isArray()->yes()) {
                $type = $type->getIterableValueType();
            }

            foreach (TypeUtils::flattenTypes($type) as $target) {
                $objectType = $target->getClassStringObjectType();
                $builders[] = $target->isClassString()->yes() && (new ObjectType(Model::class))->isSuperTypeOf($objectType)->yes()
                    ? (new BuilderOfType($objectType, $this->builderHelper, modelQuery: true))->resolve()
                    : $fallback;
            }
        }

        $queryType = TypeCombinator::union(...$builders);

        return TypeTraverser::map($parameter->getType(), static function (Type $type, callable $traverse) use ($queryType): Type {
            if ($type instanceof ClosureType) {
                return $type->traverse(static fn (Type $parameterType): Type => TypeCombinator::union(new ObjectType(Builder::class), new ObjectType(Relation::class))->isSuperTypeOf($parameterType)->yes() ? $queryType : $parameterType);
            }

            return $traverse($type);
        });
    }
}
