<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\BuilderOf;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Types\RelationOf\RelationOfType;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\CompoundType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeReference;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;

use function array_merge;

/**
 * The builder a model's newQuery() creates, written builder-of<TModel>, or of a relation path's related
 * model, written builder-of<TModel, 'path'>.
 *
 * model-query-of<TModel> and model-query-of<TModel, 'path'> are the builder newModelQuery() creates, which
 * the unscoped queries, relationship existence queries and toQuery() run. It differs from newQuery()'s
 * when the model overrides newQuery() with another builder.
 */
// @phpstan-ignore phpstanApi.interface (PHPStan's own late-resolvable types are compound, so comparisons resolve them first.)
class BuilderOfType implements CompoundType, LateResolvableType
{
    use LateResolvableTypeTrait;

    /**
     * Create a builder-of type.
     *
     * @param bool $modelQuery whether the type is newModelQuery()'s builder, written model-query-of
     */
    public function __construct(
        private Type $type,
        private BuilderHelper $builderHelper,
        private ?Type $relationType = null,
        private bool $modelQuery = false,
    ) {
    }

    /**
     * Resolve the builder type of each model the type stands for.
     */
    protected function getResult(): Type
    {
        $relatedType = $this->type;

        if ($this->relationType !== null) {
            [$relationType, $unknown] = (new RelationOfType($this->type, $this->relationType))->resolveRelations();

            // Unlike upstream, a path that does not resolve queries an unknown model, never the declaring one.
            $relatedTypes = $relationType === null ? [] : [$relationType->getTemplateType(Relation::class, 'TRelatedModel')];

            if ($unknown) {
                $relatedTypes[] = new ObjectType(Model::class);
            }

            $relatedType = TypeCombinator::union(...$relatedTypes);
        }

        $results = [];

        foreach (TypeUtils::flattenTypes($relatedType) as $modelType) {
            foreach ($modelType->getObjectClassNames() as $className) {
                $builderType = $this->builderHelper->determineBuilderClass($className, $modelType, $this->modelQuery);

                if ($builderType === null) {
                    continue;
                }

                $results[] = $builderType;
            }
        }

        // Unlike upstream, which resolves to never, an unknown model such as a relation's `*` has an unknown
        // builder, as relation-of gives an unknown relation.
        if ($results === [] && (new ObjectType(Model::class))->isSuperTypeOf($relatedType)->maybe()) {
            return new GenericObjectType(EloquentBuilder::class, [new MixedType], null, null, [TemplateTypeVariance::createBivariant()]);
        }

        return TypeCombinator::union(...$results);
    }

    /**
     * Determine whether the type can be resolved, which waits for its template types.
     */
    public function isResolvable(): bool
    {
        return ! TypeUtils::containsTemplateType($this->type)
            && ($this->relationType === null || ! TypeUtils::containsTemplateType($this->relationType));
    }

    /**
     * Get the classes the type refers to.
     *
     * @return list<string>
     */
    public function getReferencedClasses(): array
    {
        return array_merge($this->type->getReferencedClasses(), $this->relationType?->getReferencedClasses() ?? []);
    }

    /**
     * Get the template types the type refers to.
     *
     * @return list<TemplateTypeReference>
     */
    public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
    {
        // Unlike upstream, the inner types are invariant, as Builder<TModel>'s model is, so a `*` argument resolves
        // as it does there instead of to never in a parameter.
        $variance = $positionVariance->compose(TemplateTypeVariance::createInvariant());

        return array_merge(
            $this->type->getReferencedTemplateTypes($variance),
            $this->relationType?->getReferencedTemplateTypes($variance) ?? [],
        );
    }

    /**
     * Determine whether the type equals another.
     */
    public function equals(Type $type): bool
    {
        return $type instanceof self && $this->modelQuery === $type->modelQuery && $this->type->equals($type->type)
            && ($this->relationType === null
                ? $type->relationType === null
                : $type->relationType !== null && $this->relationType->equals($type->relationType));
    }

    /**
     * Describe the type.
     */
    public function describe(VerbosityLevel $level): string
    {
        return $this->name() . '<' . $this->type->describe($level)
            . ($this->relationType === null ? '' : ', ' . $this->relationType->describe($level)) . '>';
    }

    /**
     * Map the inner types.
     *
     * @param callable(Type): Type $cb
     */
    public function traverse(callable $cb): Type
    {
        $type = $cb($this->type);
        $relationType = $this->relationType === null ? null : $cb($this->relationType);

        if ($this->type === $type && $this->relationType === $relationType) {
            return $this;
        }

        return new self($type, $this->builderHelper, $relationType, $this->modelQuery);
    }

    /**
     * Map the inner types together with another builder-of type's.
     */
    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        if (! $right instanceof self
            || $this->modelQuery !== $right->modelQuery
            || ($this->relationType === null) !== ($right->relationType === null)) {
            return $this;
        }

        $type = $cb($this->type, $right->type);
        $relationType = $this->relationType !== null && $right->relationType !== null
            ? $cb($this->relationType, $right->relationType)
            : null;

        if ($this->type === $type && $this->relationType === $relationType) {
            return $this;
        }

        return new self($type, $this->builderHelper, $relationType, $this->modelQuery);
    }

    /**
     * Convert the type to a PHPDoc node.
     */
    public function toPhpDocNode(): TypeNode
    {
        $types = [$this->type->toPhpDocNode()];

        if ($this->relationType !== null) {
            $types[] = $this->relationType->toPhpDocNode();
        }

        return new GenericTypeNode(new IdentifierTypeNode($this->name()), $types);
    }

    /**
     * Get the type's PHPDoc name.
     */
    private function name(): string
    {
        return $this->modelQuery ? 'model-query-of' : 'builder-of';
    }

    /**
     * Generalize the inner types.
     */
    public function generalize(GeneralizePrecision $precision): Type
    {
        return $this->traverse(static fn (Type $type) => $type->generalize($precision));
    }
}
