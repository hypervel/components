<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\EagerLoadMap;

use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Analyser\NameScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function count;

#[AutowiredService]
class EagerLoadMapTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $typeNodeResolver;

    /**
     * Create the eager-load map type node resolver.
     */
    public function __construct(
        private BuilderHelper $builderHelper,
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Resolve an eager-loads<TModel, TMap>, relation-aggregates<TModel, TMap> or morph map type node.
     */
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (! $typeNode instanceof GenericTypeNode) {
            return null;
        }

        $kind = EagerLoadMapKind::tryFrom((string) $typeNode->type);

        if ($kind === null || count($typeNode->genericTypes) !== ($kind->hasModel() ? 2 : 1)) {
            return null;
        }

        $model = null;

        if ($kind->hasModel()) {
            $model = $this->typeNodeResolver->resolve($typeNode->genericTypes[0], $nameScope);

            if ($model instanceof NeverType || (new ObjectType(Model::class))->isSuperTypeOf($model)->no()) {
                return null;
            }
        }

        $map = $this->typeNodeResolver->resolve($typeNode->genericTypes[count($typeNode->genericTypes) - 1], $nameScope);

        if ($map instanceof NeverType || $map->isArray()->no()) {
            return null;
        }

        // A morph map type reads the booted morph map, so what the declaring class declares depends on it.
        $className = $nameScope->getClassName();

        if (! $kind->hasModel() && $className !== null && $this->reflectionProvider->hasClass($className)) {
            $this->runtimeInputs->track($this->reflectionProvider->getClass($className));
        }

        return new EagerLoadMapType($kind, $model, $map, $this->builderHelper);
    }

    /**
     * Set the resolver used for the inner type nodes.
     */
    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->typeNodeResolver = $typeNodeResolver;
    }
}
