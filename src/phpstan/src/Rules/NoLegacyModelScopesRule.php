<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Model;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * Recommend protected #[Scope] methods over legacy scopeX() methods, which remain supported.
 *
 * No fix is offered, because renaming the method breaks direct calls in other files.
 *
 * @implements Rule<InClassMethodNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%noLegacyModelScopes%')]
class NoLegacyModelScopesRule implements Rule
{
    /**
     * Create a new no legacy model scopes rule instance.
     */
    public function __construct(
        private ModelRuleHelper $modelRuleHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * Report legacy scope methods that the model can declare as attributed scopes.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $method = $node->getMethodReflection();

        if (! $classReflection->is(Model::class)
            || $this->modelRuleHelper->declaresInheritedMethod($classReflection, $method->getName())) {
            return [];
        }

        $scopeName = $this->modelRuleHelper->attributedScopeName($classReflection, $method);

        if ($scopeName === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "Local query scope method '%s' should be declared as protected #[Scope] method '%s'.",
                $method->getName(),
                $scopeName,
            ))
                ->identifier('hypervel.legacyModelScope')
                ->build(),
        ];
    }
}
