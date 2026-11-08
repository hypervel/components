<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Types\BuilderOf\BuilderOfType;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

/**
 * Return the model's own builder from toQuery(), which runs the first model's newModelQuery().
 *
 * Upstream's stub declares builder-of<TModel>. The collection cannot be stubbed, and an analyzer-only
 * return type in source would read as mixed without this package, so the source keeps Builder<TModel>.
 */
#[AutowiredService]
class EloquentCollectionToQueryDynamicReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create the toQuery() return type extension.
     */
    public function __construct(
        private BuilderHelper $builderHelper,
    ) {
    }

    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Collection::class;
    }

    /**
     * Determine whether the method is the Eloquent collection's own toQuery().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // A collection that overrides the method has its own contract.
        return $methodReflection->getName() === 'toQuery'
            && $methodReflection->getDeclaringClass()->getName() === Collection::class;
    }

    /**
     * Get the builder of the collection's model.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): Type {
        $modelType = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())
            ->getReturnType()
            ->getTemplateType(Builder::class, 'TModel');

        return (new BuilderOfType($modelType, $this->builderHelper))->resolve();
    }
}
