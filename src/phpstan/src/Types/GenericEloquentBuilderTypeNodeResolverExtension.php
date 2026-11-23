<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Methods\BuilderHelper;
use PHPStan\Analyser\NameScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_key_exists;
use function count;

/**
 * Read a PHPDoc union of a builder and a model, such as `Builder|User`, as `Builder<User>`.
 */
#[AutowiredService]
class GenericEloquentBuilderTypeNodeResolverExtension implements TypeNodeResolverExtension
{
    private const int KIND_MODEL = 1;

    private const int KIND_BUILDER = 2;

    /**
     * This extension is consulted for every PHPDoc type node, so the verdict
     * for a resolved identifier (model class, builder class, or neither) is
     * memoized to avoid repeated reflection lookups for common union members
     * like `string` or `null`.
     *
     * @var array<string, null|int>
     */
    private array $classKind = [];

    /**
     * Create the generic Eloquent builder type node resolver.
     */
    public function __construct(private ReflectionProvider $provider, private BuilderHelper $builderHelper)
    {
    }

    /**
     * Resolve a two-member union of a model and a builder class.
     */
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (! $typeNode instanceof UnionTypeNode || count($typeNode->types) !== 2) {
            return null;
        }

        $modelTypeName = null;
        $builderTypeName = null;

        foreach ($typeNode->types as $innerTypeNode) {
            // A matching union needs a model member and a builder member, so
            // any member that is not a plain identifier means we can bail out.
            if (! $innerTypeNode instanceof IdentifierTypeNode) {
                return null;
            }

            $resolvedName = $nameScope->resolveStringName($innerTypeNode->name);
            $kind = $this->resolveClassKind($resolvedName);

            if ($kind === self::KIND_MODEL && $modelTypeName === null) {
                $modelTypeName = $resolvedName;
            } elseif ($kind === self::KIND_BUILDER && $builderTypeName === null) {
                $builderTypeName = $resolvedName;
            }
        }

        if ($modelTypeName === null || $builderTypeName === null) {
            return null;
        }

        return $this->builderHelper->getBuilderType(new ObjectType($builderTypeName), new ObjectType($modelTypeName));
    }

    /**
     * Determine whether the name is a model class, a builder class, or neither.
     */
    private function resolveClassKind(string $resolvedName): ?int
    {
        if (array_key_exists($resolvedName, $this->classKind)) {
            return $this->classKind[$resolvedName];
        }

        $kind = null;

        if ($this->provider->hasClass($resolvedName)) {
            $classReflection = $this->provider->getClass($resolvedName);

            if ($classReflection->is(Model::class)) {
                $kind = self::KIND_MODEL;
            } elseif ($classReflection->is(Builder::class)) {
                $kind = self::KIND_BUILDER;
            }
        }

        return $this->classKind[$resolvedName] = $kind;
    }
}
