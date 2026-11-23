<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\CollectionOf;

use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Support\CollectionHelper;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\CompoundType;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\Generic\TemplateTypeReference;
use PHPStan\Type\Generic\TemplateTypeVariance;
use PHPStan\Type\IntegerType;
use PHPStan\Type\LateResolvableType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Traits\LateResolvableTypeTrait;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;

use function array_merge;

/**
 * The collection a model's queries return, written collection-of<TModel> or collection-of<TKey, TModel>.
 */
// @phpstan-ignore phpstanApi.interface (PHPStan's own late-resolvable types are compound, so comparisons resolve them first.)
class CollectionOfType implements CompoundType, LateResolvableType
{
    use LateResolvableTypeTrait;

    /**
     * Create a collection-of type.
     */
    public function __construct(private Type $type, private CollectionHelper $collectionHelper, private ?Type $keyType = null)
    {
    }

    /**
     * Resolve the collection class of each model the type stands for.
     */
    protected function getResult(): Type
    {
        $keyType = $this->keyType ?? new BenevolentUnionType([new IntegerType, new StringType]);
        $results = [];

        foreach (TypeUtils::flattenTypes($this->type) as $modelType) {
            foreach ($modelType->getObjectClassNames() as $className) {
                if (! (new ObjectType(Model::class))->isSuperTypeOf(new ObjectType($className))->yes()) {
                    continue;
                }

                $results[] = $this->collectionHelper->determineCollectionClass($className, $modelType, $keyType);
            }
        }

        // Unlike upstream, which resolves to never, an unknown model, such as a relation's `*`, has the base
        // model's collection, as builder-of gives an unknown builder.
        if ($results === [] && (new ObjectType(Model::class))->isSuperTypeOf($this->type)->maybe()) {
            return $this->collectionHelper->determineCollectionClass(Model::class, keyType: $keyType);
        }

        return TypeCombinator::union(...$results);
    }

    /**
     * Determine whether the type can be resolved, which waits for its template types.
     */
    public function isResolvable(): bool
    {
        return ! TypeUtils::containsTemplateType($this->type)
            && ($this->keyType === null || ! TypeUtils::containsTemplateType($this->keyType));
    }

    /**
     * Get the classes the type refers to.
     *
     * @return list<string>
     */
    public function getReferencedClasses(): array
    {
        return array_merge($this->type->getReferencedClasses(), $this->keyType?->getReferencedClasses() ?? []);
    }

    /**
     * Get the template types the type refers to.
     *
     * @return list<TemplateTypeReference>
     */
    public function getReferencedTemplateTypes(TemplateTypeVariance $positionVariance): array
    {
        // Unlike upstream, the inner types are invariant, as the Eloquent collection's are, so a `*` argument
        // resolves as it does there instead of to never in a parameter.
        $variance = $positionVariance->compose(TemplateTypeVariance::createInvariant());

        return array_merge(
            $this->type->getReferencedTemplateTypes($variance),
            $this->keyType?->getReferencedTemplateTypes($variance) ?? [],
        );
    }

    /**
     * Determine whether the type equals another.
     */
    public function equals(Type $type): bool
    {
        if (! $type instanceof self || ! $this->type->equals($type->type)) {
            return false;
        }

        if ($this->keyType === null || $type->keyType === null) {
            return $this->keyType === $type->keyType;
        }

        return $this->keyType->equals($type->keyType);
    }

    /**
     * Describe the type.
     */
    public function describe(VerbosityLevel $level): string
    {
        if ($this->keyType !== null) {
            return 'collection-of<' . $this->keyType->describe($level) . ', ' . $this->type->describe($level) . '>';
        }

        return 'collection-of<' . $this->type->describe($level) . '>';
    }

    /**
     * Map the inner types.
     *
     * @param callable(Type): Type $cb
     */
    public function traverse(callable $cb): Type
    {
        $type = $cb($this->type);
        $keyType = $this->keyType !== null ? $cb($this->keyType) : null;

        if ($this->type === $type && $this->keyType === $keyType) {
            return $this;
        }

        return new self($type, $this->collectionHelper, $keyType);
    }

    /**
     * Map the inner types together with another collection-of type's.
     */
    public function traverseSimultaneously(Type $right, callable $cb): Type
    {
        if (! $right instanceof self) {
            return $this;
        }

        $type = $cb($this->type, $right->type);
        $keyType = $this->keyType !== null && $right->keyType !== null ? $cb($this->keyType, $right->keyType) : $this->keyType;

        if ($this->type === $type && $this->keyType === $keyType) {
            return $this;
        }

        return new self($type, $this->collectionHelper, $keyType);
    }

    /**
     * Convert the type to a PHPDoc node.
     */
    public function toPhpDocNode(): TypeNode
    {
        $genericTypes = $this->keyType !== null
            ? [$this->keyType->toPhpDocNode(), $this->type->toPhpDocNode()]
            : [$this->type->toPhpDocNode()];

        return new GenericTypeNode(new IdentifierTypeNode('collection-of'), $genericTypes);
    }

    /**
     * Generalize the inner types.
     */
    public function generalize(GeneralizePrecision $precision): Type
    {
        return $this->traverse(static fn (Type $type) => $type->generalize($precision));
    }
}
