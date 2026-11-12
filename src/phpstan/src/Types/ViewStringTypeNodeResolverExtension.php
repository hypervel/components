<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types;

use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Analyser\NameScope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\PhpDoc\TypeNodeResolver;
use PHPStan\PhpDoc\TypeNodeResolverAwareExtension;
use PHPStan\PhpDoc\TypeNodeResolverExtension;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

/**
 * Ensures a 'view-string' type in PHPDoc is recognized to be of type ViewStringType.
 *
 * Whether a view-string accepts a name depends on which views exist, so what the class whose
 * PHPDoc uses one declares depends on them. PHPStan resolves a type alias in the scope that
 * defines it, not the class using it, so an alias is resolved here to see whether it holds one.
 */
#[AutowiredService]
class ViewStringTypeNodeResolverExtension implements TypeNodeResolverExtension, TypeNodeResolverAwareExtension
{
    private TypeNodeResolver $typeNodeResolver;

    /**
     * The names of the aliases being resolved, which this extension leaves to core.
     *
     * @var array<string, true>
     */
    private array $resolvingAliases = [];

    /**
     * Create a new view-string type node resolver.
     *
     * @param array<string, string> $typeAliases the global type aliases
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
        #[AutowiredParameter]
        private array $typeAliases,
    ) {
    }

    /**
     * Resolve a view-string PHPDoc type, or a type alias that holds one.
     */
    public function resolve(TypeNode $typeNode, NameScope $nameScope): ?Type
    {
        if (! $typeNode instanceof IdentifierTypeNode) {
            return null;
        }

        if ($typeNode->name === 'view-string') {
            $this->trackViews($nameScope);

            return new ViewStringType;
        }

        if ($nameScope->shouldBypassTypeAliases()
            || isset($this->resolvingAliases[$typeNode->name])
            || (! $nameScope->hasTypeAlias($typeNode->name) && ! isset($this->typeAliases[$typeNode->name]))) {
            return null;
        }

        $this->resolvingAliases[$typeNode->name] = true;

        try {
            $type = $this->typeNodeResolver->resolve($typeNode, $nameScope);
        } finally {
            unset($this->resolvingAliases[$typeNode->name]);
        }

        if (ViewStringType::occursIn($type)) {
            $this->trackViews($nameScope);
        }

        return $type;
    }

    /**
     * Set the resolver used to resolve type aliases.
     */
    public function setTypeNodeResolver(TypeNodeResolver $typeNodeResolver): void
    {
        $this->typeNodeResolver = $typeNodeResolver;
    }

    /**
     * Declare that what the class whose PHPDoc is resolved declares depends on which views exist.
     */
    private function trackViews(NameScope $nameScope): void
    {
        $className = $nameScope->getClassName();

        if ($className !== null && $this->reflectionProvider->hasClass($className)) {
            $this->runtimeInputs->trackViews($this->reflectionProvider->getClass($className));
        }
    }
}
