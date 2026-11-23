<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\RelationOf;

use Hypervel\Database\Eloquent\Model;
use PHPStan\Analyser\NameScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function count;

#[AutowiredService]
class RelationOfTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $typeNodeResolver;

    /**
     * Resolve a relation-of<TModel, 'path'> type node.
     */
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (! $typeNode instanceof GenericTypeNode || (string) $typeNode->type !== 'relation-of' || count($typeNode->genericTypes) !== 2) {
            return null;
        }

        $model = $this->typeNodeResolver->resolve($typeNode->genericTypes[0], $nameScope);
        $key = $this->typeNodeResolver->resolve($typeNode->genericTypes[1], $nameScope);

        if ($model instanceof NeverType || (new ObjectType(Model::class))->isSuperTypeOf($model)->no()) {
            return null;
        }

        if ($key instanceof NeverType || ! $key->isString()->yes()) {
            return null;
        }

        return new RelationOfType($model, $key);
    }

    /**
     * Set the resolver used for the inner type nodes.
     */
    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->typeNodeResolver = $typeNodeResolver;
    }
}
