<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\EagerLoadMap;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Reflection\CallableParameterReflection;
use Hypervel\PHPStan\Types\BuilderOf\BuilderOfType;
use Hypervel\PHPStan\Types\RelationOf\RelationOfType;
use Hypervel\Support\Str;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\ArrayType;
use PHPStan\Type\CallableType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\Generic\TemplateTypeReference;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

use function array_fill;
use function array_merge;
use function count;
use function explode;
use function is_string;

/**
 * The expected type of a relation-keyed map, built per key from the map a call passes.
 *
 * The method template takes the whole argument, as in eager-loads<TModel, TRelations> with
 * `@template TRelations of array`, so each constant key's callback expects that key's own relation or builder.
 * Upstream's stubs union the keys instead, which accepts a callback written for another key's relation.
 * Integer keys, keys that name no model and maps without constant keys keep Laravel's value type. Optional
 * keys stay optional, and a supplied callback's type never becomes part of the expected type.
 */
// @phpstan-ignore phpstanApi.interface (PHPStan's own late-resolvable types are compound, so comparisons resolve them first.)
class EagerLoadMapType implements CompoundType, LateResolvableType
{
    use LateResolvableTypeTrait;

    /**
     * Create an eager-load map type.
     */
    public function __construct(
        private EagerLoadMapKind $kind,
        private ?Type $model,
        private Type $map,
        private BuilderHelper $builderHelper,
    ) {
    }

    /**
     * Build the expected map from the map's constant keys.
     */
    protected function getResult(): Type
    {
        if ($this->model === null) {
            return $this->morphEntries();
        }

        return $this->kind === EagerLoadMapKind::EagerLoads
            ? $this->eagerLoads($this->model, $this->map, '', $this->eagerLoadValue())
            : $this->relationAggregates($this->model, $this->map);
    }

    /**
     * Build the expected eager loads of a model's relations.
     *
     * Nested arrays follow Builder::prepareNestedWithRelationships(): their keys continue the path from the
     * parent key's name without its column list. Laravel declares nested values as any array, so nested
     * entries the keys do not type accept any value.
     */
    private function eagerLoads(Type $model, Type $map, string $prefix, Type $fallbackValue): Type
    {
        return $this->mapEntries($map, $fallbackValue, function (int|string $key, Type $value) use ($model, $prefix): ?Type {
            if (! is_string($key)) {
                return null;
            }

            $relationType = (new RelationOfType($model, new ConstantStringType($prefix . $key)))->resolve();

            return TypeCombinator::union(
                $this->callback($relationType),
                new StringType,
                $this->eagerLoads($model, $this->arrayPart($value), $prefix . explode(':', $key, 2)[0] . '.', new MixedType),
            );
        });
    }

    /**
     * Build the expected aggregate constraints of a model's relations.
     *
     * The relation name is read as QueriesRelationships::withAggregate() reads it, without an alias. Aggregates
     * do not follow nested arrays: an array value is only the empty, unconstrained form. A relation that does not
     * resolve keeps Laravel's value type and its Builder<*> callback, as an unknown eager load gets Laravel's
     * Relation<*, *, *>.
     */
    private function relationAggregates(Type $model, Type $map): Type
    {
        return $this->mapEntries($map, $this->relationAggregateValue(), function (int|string $key) use ($model): ?Type {
            if (! is_string($key)) {
                return null;
            }

            $segments = explode(' ', $key);
            $relation = count($segments) === 3 && Str::lower($segments[1]) === 'as' ? $segments[0] : $key;

            [$relationType, $unknown] = (new RelationOfType($model, new ConstantStringType($relation)))->resolveRelations();

            if ($relationType === null || $unknown) {
                return null;
            }

            $relatedModel = $relationType->getTemplateType(Relation::class, 'TRelatedModel');

            return TypeCombinator::union(
                ConstantArrayTypeBuilder::createEmpty()->getArray(),
                $this->callback((new BuilderOfType($relatedModel, $this->builderHelper))->resolve()),
                new StringType,
            );
        });
    }

    /**
     * Build the expected morph-keyed map, typing each key's value for the models it applies to.
     */
    private function morphEntries(): Type
    {
        $fallbackValue = match ($this->kind) {
            EagerLoadMapKind::MorphEagerLoads => TypeCombinator::union(new ArrayType((new MixedType)->toArrayKey(), $this->eagerLoadValue()), new StringType),
            EagerLoadMapKind::MorphAggregates => TypeCombinator::union(new ArrayType((new MixedType)->toArrayKey(), $this->relationAggregateValue()), new StringType),
            // MorphTo declares its maps as any array. The mixed is explicit, as any value is accepted, so stub
            // validation does not read the value type as missing.
            default => new MixedType(true),
        };

        return $this->mapEntries($this->map, $fallbackValue, function (int|string $key, Type $value, ConstantArrayType $map): ?Type {
            $model = $this->morphModel($key, $map);

            if ($model === null) {
                return null;
            }

            return match ($this->kind) {
                EagerLoadMapKind::MorphTypeConstraints => $this->callback((new BuilderOfType($model, $this->builderHelper))->resolve()),
                EagerLoadMapKind::MorphAggregates, EagerLoadMapKind::MorphTypeAggregates => TypeCombinator::union(
                    $this->relationAggregates($model, $this->arrayPart($value)),
                    new StringType,
                ),
                default => TypeCombinator::union(
                    $this->eagerLoads($model, $this->arrayPart($value), '', $this->eagerLoadValue()),
                    new StringType,
                ),
            };
        });
    }

    /**
     * Get the models a morph map key applies to, or null when it names none.
     *
     * MorphTo::resolveMorphTypes() maps a key through the morph map before using it as a class name. loadMorph()
     * and its siblings read a model's entry by its class, then by its morph alias, so an alias key applies to its
     * model only when the map has no key for that class.
     */
    private function morphModel(int|string $key, ConstantArrayType $map): ?Type
    {
        $morphedModel = Relation::getMorphedModel($key);

        if ($this->kind->resolvesAliasFirst()) {
            return $this->modelType($morphedModel ?? (string) $key);
        }

        $models = [];

        if (($modelType = $this->modelType((string) $key)) !== null) {
            $models[] = $modelType;
        }

        if ($morphedModel !== null
            && (string) Relation::getMorphAlias($morphedModel) === (string) $key
            && ! $map->hasOffsetValueType(new ConstantStringType($morphedModel))->yes()
            && ($modelType = $this->modelType($morphedModel)) !== null) {
            $models[] = $modelType;
        }

        return $models === [] ? null : TypeCombinator::union(...$models);
    }

    /**
     * Get the type of a model class named exactly, as get_class() names it.
     */
    private function modelType(string $className): ?Type
    {
        $modelType = new ObjectType($className);

        return $modelType->getClassReflection()?->getName() === $className
            && (new ObjectType(Model::class))->isSuperTypeOf($modelType)->yes()
                ? $modelType
                : null;
    }

    /**
     * Build an expected map from a map's constant keys, keeping the fallback value for other entries.
     *
     * @param callable(int|string, Type, ConstantArrayType): ?Type $expectedValue
     */
    private function mapEntries(Type $map, Type $fallbackValue, callable $expectedValue): Type
    {
        $results = [];

        // Union members are read whole: flattening a shape would expand its optional keys.
        foreach ($map instanceof UnionType ? $map->getTypes() : [$map] as $mapType) {
            $constantArrays = $mapType->getConstantArrays();

            if ($constantArrays === []) {
                $results[] = new ArrayType((new MixedType)->toArrayKey(), $fallbackValue);

                continue;
            }

            foreach ($constantArrays as $constantArray) {
                $builder = ConstantArrayTypeBuilder::createEmpty();

                foreach ($constantArray->getKeyTypes() as $index => $keyType) {
                    $builder->setOffsetValueType(
                        $keyType,
                        $expectedValue($keyType->getValue(), $constantArray->getValueTypes()[$index], $constantArray) ?? $fallbackValue,
                        $constantArray->isOptionalKey($index),
                    );
                }

                if ($constantArray->isUnsealed()->yes()) {
                    $builder->makeUnsealed($constantArray->getUnsealedTypes()[0], $fallbackValue);
                }

                $results[] = $builder->getArray();
            }
        }

        return TypeCombinator::union(...$results);
    }

    /**
     * Get the array part of an entry's value, or any array when it has none.
     */
    private function arrayPart(Type $value): Type
    {
        $arrayType = new ArrayType(new MixedType, new MixedType);

        return $value->isArray()->no() ? $arrayType : TypeCombinator::intersect($value, $arrayType);
    }

    /**
     * Get a callable type that receives the given type.
     */
    private function callback(Type $parameterType): Type
    {
        return new CallableType(
            [new CallableParameterReflection('query', $parameterType)],
            new MixedType,
            false,
        );
    }

    /**
     * Get Laravel's eager-load value type: array|(callable(Relation<*, *, *>): mixed)|string.
     */
    private function eagerLoadValue(): Type
    {
        return TypeCombinator::union(
            new ArrayType(new MixedType, new MixedType),
            $this->callback(new GenericObjectType(
                Relation::class,
                [new MixedType, new MixedType, new MixedType],
                null,
                null,
                array_fill(0, 3, TemplateTypeVariance::createBivariant()),
            )),
            new StringType,
        );
    }

    /**
     * Get Laravel's aggregate value type: array{}|(callable(Builder<*>): mixed)|string.
     */
    private function relationAggregateValue(): Type
    {
        return TypeCombinator::union(
            ConstantArrayTypeBuilder::createEmpty()->getArray(),
            $this->callback(new GenericObjectType(EloquentBuilder::class, [new MixedType], null, null, [TemplateTypeVariance::createBivariant()])),
            new StringType,
        );
    }

    /**
     * Infer the map template from the whole argument, so each key keeps its own value.
     */
    public function inferTemplateTypes(Type $receivedType): TemplateTypeMap
    {
        return $this->map->inferTemplateTypes($receivedType);
    }

    /**
     * Determine whether the type can be resolved, which waits for its template types.
     */
    public function isResolvable(): bool
    {
        return ! TypeUtils::containsTemplateType($this->map)
            && ($this->model === null || ! TypeUtils::containsTemplateType($this->model));
    }

    /**
     * Get the classes the type refers to.
     *
     * @return list<string>
     */
    public function getReferencedClasses(): array
    {
        return array_merge($this->model?->getReferencedClasses() ?? [], $this->map->getReferencedClasses());
    }

    /**
     * Get the template types the type refers to.
     *
     * @return list<TemplateTypeReference>
     */
    public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
    {
        $variance = $positionVariance->compose(TemplateTypeVariance::createInvariant());

        return array_merge(
            $this->model?->getReferencedTemplateTypes($variance) ?? [],
            $this->map->getReferencedTemplateTypes($variance),
        );
    }

    /**
     * Determine whether the type equals another.
     */
    public function equals(Type $type): bool
    {
        return $type instanceof self
            && $this->kind === $type->kind
            && $this->map->equals($type->map)
            && ($this->model === null ? $type->model === null : $type->model !== null && $this->model->equals($type->model));
    }

    /**
     * Describe the type.
     */
    public function describe(VerbosityLevel $level): string
    {
        return $this->kind->value . '<'
            . ($this->model === null ? '' : $this->model->describe($level) . ', ')
            . $this->map->describe($level) . '>';
    }

    /**
     * Map the inner types.
     *
     * @param callable(Type): Type $cb
     */
    public function traverse(callable $cb): Type
    {
        $model = $this->model === null ? null : $cb($this->model);
        $map = $cb($this->map);

        if ($model === $this->model && $map === $this->map) {
            return $this;
        }

        return new self($this->kind, $model, $map, $this->builderHelper);
    }

    /**
     * Map the inner types together with another eager-load map type's.
     */
    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        if (! $right instanceof self || $right->kind !== $this->kind) {
            return $this;
        }

        $model = $this->model !== null && $right->model !== null ? $cb($this->model, $right->model) : $this->model;
        $map = $cb($this->map, $right->map);

        if ($model === $this->model && $map === $this->map) {
            return $this;
        }

        return new self($this->kind, $model, $map, $this->builderHelper);
    }

    /**
     * Convert the type to a PHPDoc node.
     */
    public function toPhpDocNode(): TypeNode
    {
        $types = $this->model === null ? [] : [$this->model->toPhpDocNode()];
        $types[] = $this->map->toPhpDocNode();

        return new GenericTypeNode(new IdentifierTypeNode($this->kind->value), $types);
    }

    /**
     * Generalize the inner types.
     */
    public function generalize(GeneralizePrecision $precision): Type
    {
        return $this->traverse(static fn (Type $type) => $type->generalize($precision));
    }
}
