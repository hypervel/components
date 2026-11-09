<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Model;
use PhpParser\Node;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Type\ObjectType;

use function array_merge;

/**
 * Report relations a concrete model's default $with and $withCount eager loads do not define.
 *
 * @implements Rule<InClassNode>
 */
#[AutowiredService]
class ModelRelationDefaultsRule implements Rule
{
    /**
     * Create a new model relation defaults rule instance.
     */
    public function __construct(
        private RelationExistenceHelper $relationExistenceHelper,
        private ModelRuleHelper $modelRuleHelper,
        private InitializerExprTypeResolver $initializerExprTypeResolver,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report each default eager load whose relation the model does not define.
     *
     * @param DependencyTracker&Scope $scope
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $class->isSubclassOf(Model::class) || $class->isAbstract()) {
            return [];
        }

        $this->modelRuleHelper->trackModelSources($class, $scope);

        $errors = [];

        foreach (['with', 'withCount'] as $name) {
            $property = $class->getNativeReflection()->getProperty($name);

            if (! $property->hasDefaultValue()) {
                continue;
            }

            $declared = $property->getDeclaringClass()->getName() === $class->getName();
            $default = $declared
                ? $this->modelRuleHelper->declaredPropertyDefault($node->getOriginalNode(), $name)
                : $property->getDefaultValueExpression();

            if ($default === null) {
                continue;
            }

            $type = $this->initializerExprTypeResolver->getType($default, InitializerExprContext::fromClass(
                $property->getDeclaringClass()->getName(),
                $property->getDeclaringClass()->getFileName() ?: null,
            ));

            // Inherited defaults belong to the concrete child, which may supply the relation.
            $location = $declared ? $default : $node;
            $errors = array_merge($errors, $this->relationExistenceHelper->check($type, new ObjectType($class->getName()), $location, $scope, $name === 'withCount'));
        }

        return $errors;
    }
}
