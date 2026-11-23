<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\PHPStan\Support\CollectionHelper;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function in_array;
use function str_starts_with;

/**
 * Return a model's own collection class from the builder methods that return Eloquent collections.
 *
 * Methods forwarded to the query builder are described by the forwarding extension, which already
 * returns the receiving builder for fluent calls.
 */
#[AutowiredService]
class EloquentBuilderExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create the Eloquent builder return type extension.
     */
    public function __construct(
        private CollectionHelper $collectionHelper,
    ) {
    }

    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return EloquentBuilder::class;
    }

    /**
     * Determine whether the method returns a model collection the extension can refine.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // The find methods type their collection results with collection-of in the stubs.
        if (str_starts_with($methodReflection->getName(), 'find') || ! CollectionHelper::isFrameworkQueryMethod($methodReflection)) {
            return false;
        }

        $modelType = $methodReflection->getDeclaringClass()->getActiveTemplateTypeMap()->getType('TModel');

        return $modelType !== null && $modelType->getObjectClassNames() !== [];
    }

    /**
     * Return the model's collection class in place of the declared Eloquent collection.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $returnType = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants())->getReturnType();

        if (! in_array(Collection::class, $returnType->getReferencedClasses(), true)) {
            return null;
        }

        // Unlike upstream, only the collections inside the return type are replaced, so a paginator
        // of models stays a paginator.
        return $this->collectionHelper->replaceCollectionsInType($returnType);
    }
}
