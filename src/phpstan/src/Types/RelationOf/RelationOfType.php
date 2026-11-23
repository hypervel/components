<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\RelationOf;

use Hypervel\Database\Eloquent\Relations\Relation;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\CompoundType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeReference;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;

use function array_fill;
use function array_merge;
use function explode;

/**
 * The relationship a model's relation path names, written relation-of<TModel, 'path'>.
 */
// @phpstan-ignore phpstanApi.interface (PHPStan's own late-resolvable types are compound, so comparisons resolve them first.)
class RelationOfType implements CompoundType, LateResolvableType
{
    use LateResolvableTypeTrait;

    /**
     * Create a relation-of type.
     */
    public function __construct(private Type $model, private Type $key)
    {
    }

    /**
     * Resolve the relationship type, or a relation of unknown models.
     */
    protected function getResult(): Type
    {
        [$relationType, $unknown] = $this->resolveRelations();

        $relations = $relationType === null ? [] : [$relationType];

        if ($unknown) {
            $relations[] = new GenericObjectType(
                Relation::class,
                [new MixedType, new MixedType, new MixedType],
                null,
                null,
                array_fill(0, 3, TemplateTypeVariance::createBivariant()),
            );
        }

        // Unlike upstream's benevolent union, a callback for several possible relations must accept each of them.
        $relation = TypeCombinator::union(...$relations);

        // Eager loading creates the relation on a fresh model instance.
        return TypeTraverser::map($relation, static fn (Type $type, callable $traverse): Type => $type instanceof StaticType ? $type->getStaticObjectType() : $traverse($type));
    }

    /**
     * Resolve the relationships the paths name.
     *
     * Unlike upstream, a path that does not resolve is unknown rather than missing, kept beside the
     * paths that do: the name can be a non-constant string, belong to an abstract model, or name a
     * relation that resolveRelationUsing() registers at boot.
     *
     * @internal
     *
     * @return array{null|Type, bool} the relationship type of the paths that resolve, and whether any path does not
     */
    public function resolveRelations(): array
    {
        $relations = $this->key->getConstantStrings();

        if ($relations === []) {
            return [null, true];
        }

        $results = [];
        $unknown = false;

        foreach ($relations as $relation) {
            [$relationType, $pathUnknown] = $this->followRelationPath($relation->getValue());
            $unknown = $unknown || $pathUnknown;

            if ($relationType !== null) {
                $results[] = $relationType;
            }
        }

        return [$results === [] ? null : TypeCombinator::union(...$results), $unknown];
    }

    /**
     * Follow a dotted relation path, ignoring a column list after a colon.
     *
     * Unlike upstream, a model alternative without the relation is unknown, as a path that does not resolve is.
     *
     * @return array{null|Type, bool} the relationship type of the alternatives the path resolves for, and whether
     *                                any does not resolve
     */
    private function followRelationPath(string $path): array
    {
        $relatedType = $this->model;
        $relationType = null;
        $unknown = false;

        foreach (explode('.', explode(':', $path, 2)[0]) as $relationName) {
            $relations = [];

            foreach (TypeUtils::flattenTypes($relatedType) as $modelType) {
                $returnType = $modelType->hasMethod($relationName)->yes()
                    ? ParametersAcceptorSelector::selectFromTypes([], $modelType->getMethod($relationName, new OutOfClassScope)->getVariants(), false)->getReturnType()
                    : null;

                if ($returnType === null || ! (new ObjectType(Relation::class))->isSuperTypeOf($returnType)->yes()) {
                    $unknown = true;

                    continue;
                }

                $relations[] = $returnType;
            }

            if ($relations === []) {
                return [null, true];
            }

            $relationType = TypeCombinator::union(...$relations);
            $relatedType = $relationType->getTemplateType(Relation::class, 'TRelatedModel');
        }

        return [$relationType, $unknown];
    }

    /**
     * Determine whether the type can be resolved, which waits for its template types.
     */
    public function isResolvable(): bool
    {
        return ! TypeUtils::containsTemplateType($this->model) && ! TypeUtils::containsTemplateType($this->key);
    }

    /**
     * Get the classes the type refers to.
     *
     * @return list<string>
     */
    public function getReferencedClasses(): array
    {
        return array_merge($this->model->getReferencedClasses(), $this->key->getReferencedClasses());
    }

    /**
     * Get the template types the type refers to.
     *
     * @return list<TemplateTypeReference>
     */
    public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
    {
        // Unlike upstream, the inner types are invariant, as a relation's declaring model is, so a `*` argument
        // resolves as it does there instead of to never in a parameter.
        $variance = $positionVariance->compose(TemplateTypeVariance::createInvariant());

        return array_merge(
            $this->model->getReferencedTemplateTypes($variance),
            $this->key->getReferencedTemplateTypes($variance),
        );
    }

    /**
     * Determine whether the type equals another.
     */
    public function equals(Type $type): bool
    {
        return $type instanceof self && $this->model->equals($type->model) && $this->key->equals($type->key);
    }

    /**
     * Describe the type.
     */
    public function describe(VerbosityLevel $level): string
    {
        return 'relation-of<' . $this->model->describe($level) . ', ' . $this->key->describe($level) . '>';
    }

    /**
     * Map the inner types.
     *
     * @param callable(Type): Type $cb
     */
    public function traverse(callable $cb): Type
    {
        $model = $cb($this->model);
        $key = $cb($this->key);

        return $model === $this->model && $key === $this->key ? $this : new self($model, $key);
    }

    /**
     * Map the inner types together with another relation-of type's.
     */
    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        if (! $right instanceof self) {
            return $this;
        }

        $model = $cb($this->model, $right->model);
        $key = $cb($this->key, $right->key);

        return $model === $this->model && $key === $this->key ? $this : new self($model, $key);
    }

    /**
     * Convert the type to a PHPDoc node.
     */
    public function toPhpDocNode(): TypeNode
    {
        return new GenericTypeNode(new IdentifierTypeNode('relation-of'), [$this->model->toPhpDocNode(), $this->key->toPhpDocNode()]);
    }

    /**
     * Generalize the inner types.
     */
    public function generalize(GeneralizePrecision $precision): Type
    {
        return $this->traverse(static fn (Type $type) => $type->generalize($precision));
    }
}
