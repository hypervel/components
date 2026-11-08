<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Support\CollectionHelper;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Type;

use function in_array;

/**
 * Return the related model's own collection class from relation methods that return Eloquent collections.
 *
 * Upstream also turns a forwarded call's builder result into the relation. The forwarding extension
 * decides that from whether the builder method returns itself, because a builder result such as
 * applyScopes()'s is a different object, which Relation::__call() returns as it is.
 *
 * @internal
 */
#[AutowiredService]
class RelationCollectionExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Create the relation collection return type extension.
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
        return Relation::class;
    }

    /**
     * Determine whether the relation or forwarded builder method can return model collections.
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        $modelType = $methodReflection->getDeclaringClass()->getActiveTemplateTypeMap()->getType('TRelatedModel');

        if ($modelType === null && $methodReflection->getDeclaringClass()->getName() === Builder::class) {
            $modelType = $methodReflection->getDeclaringClass()->getActiveTemplateTypeMap()->getType('TModel');
        }

        return $modelType !== null
            && $modelType->getObjectClassNames() !== []
            && CollectionHelper::isFrameworkQueryMethod($methodReflection);
    }

    /**
     * Return the related model's collection class in place of the declared Eloquent collection.
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

        return $this->collectionHelper->replaceCollectionsInType($returnType);
    }
}
