<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Validation;

use Hypervel\Data\Attributes\Validation\Rule as DataRule;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

/**
 * Recommend arrays over pipe-separated rule strings in Data's #[Rule] attribute.
 *
 * @implements Rule<Attribute>
 */
#[Conditional(attribute: new AutowiredService, if: '%noPipeSeparatedValidationRules%')]
class NoPipeSeparatedValidationRulesAttributeRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private ValidationRuleHelper $helper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return Attribute::class;
    }

    /**
     * Report the pipe-separated rule strings the attribute takes.
     *
     * @param Attribute $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->resolveName($node->name);

        if (! $this->reflectionProvider->hasClass($class)) {
            return [];
        }

        $attribute = $this->reflectionProvider->getClass($class);

        if (! $attribute->is(DataRule::class) || ! $attribute->hasConstructor()) {
            return [];
        }

        $errors = [];

        foreach ($this->helper->getArgumentValues($node->args, $attribute->getConstructor()->getVariants(), 'rules', $scope) as $value) {
            $errors = [...$errors, ...$this->helper->check($value, ValidationRuleHelper::DATA_SET, $scope)];
        }

        return $errors;
    }
}
