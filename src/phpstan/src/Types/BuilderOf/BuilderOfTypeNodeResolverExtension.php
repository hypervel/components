<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\BuilderOf;

use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Methods\BuilderHelper;
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
use function in_array;

#[AutowiredService]
class BuilderOfTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $typeNodeResolver;

    /**
     * Create the builder-of type node resolver.
     */
    public function __construct(private BuilderHelper $builderHelper)
    {
    }

    /**
     * Resolve a builder-of<TModel> or builder-of<TModel, 'path'> type node, or its model-query-of form.
     */
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (! $typeNode instanceof GenericTypeNode || ! in_array((string) $typeNode->type, ['builder-of', 'model-query-of'], true)) {
            return null;
        }

        if (count($typeNode->genericTypes) < 1 || count($typeNode->genericTypes) > 2) {
            return null;
        }

        $genericType = $this->typeNodeResolver->resolve($typeNode->genericTypes[0], $nameScope);

        if ($genericType instanceof NeverType || (new ObjectType(Model::class))->isSuperTypeOf($genericType)->no()) {
            return null;
        }

        $relationType = isset($typeNode->genericTypes[1])
            ? $this->typeNodeResolver->resolve($typeNode->genericTypes[1], $nameScope)
            : null;

        if ($relationType !== null && (! $relationType->isString()->yes() || $relationType instanceof NeverType)) {
            return null;
        }

        return new BuilderOfType($genericType, $this->builderHelper, $relationType, (string) $typeNode->type === 'model-query-of');
    }

    /**
     * Set the resolver used for the inner type nodes.
     */
    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->typeNodeResolver = $typeNodeResolver;
    }
}
