<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\CollectionOf;

use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Support\CollectionHelper;
use PHPStan\Analyser\NameScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

use function count;

#[AutowiredService]
class CollectionOfTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $typeNodeResolver;

    /**
     * Create the collection-of type node resolver.
     */
    public function __construct(
        private CollectionHelper $collectionHelper,
    ) {
    }

    /**
     * Resolve a collection-of<TModel> or collection-of<TKey, TModel> type node.
     */
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (! $typeNode instanceof GenericTypeNode || (string) $typeNode->type !== 'collection-of') {
            return null;
        }

        $genericTypes = $typeNode->genericTypes;

        if (count($genericTypes) !== 1 && count($genericTypes) !== 2) {
            return null;
        }

        // Like array<TKey, TValue>, the optional key type comes first.
        $keyType = count($genericTypes) === 2 ? $this->typeNodeResolver->resolve($genericTypes[0], $nameScope) : null;
        $genericType = $this->typeNodeResolver->resolve($genericTypes[count($genericTypes) - 1], $nameScope);

        if ($genericType instanceof NeverType || (new ObjectType(Model::class))->isSuperTypeOf($genericType)->no()) {
            return null;
        }

        if ($keyType !== null && (new UnionType([new IntegerType, new StringType]))->isSuperTypeOf($keyType)->no()) {
            return null;
        }

        return new CollectionOfType($genericType, $this->collectionHelper, $keyType);
    }

    /**
     * Set the resolver used for the inner type nodes.
     */
    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->typeNodeResolver = $typeNodeResolver;
    }
}
