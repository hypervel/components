<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_find;
use function class_basename;
use function count;
use function sprintf;

/**
 * Reports boot-time and test-only markers whose conditions would silently disable or misstate the
 * restriction RestrictedCallRule enforces.
 *
 * @implements Rule<InClassMethodNode>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkRestrictedCalls%')]
class RestrictedAttributeRule implements Rule
{
    /**
     * Create a new restricted attribute rule instance.
     */
    public function __construct(
        private WorkerStateHelper $helper,
    ) {
    }

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * Report the method's malformed lifetime marker.
     *
     * @param InClassMethodNode $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->getOriginalNode()->attrGroups === []) {
            return [];
        }

        $method = $node->getMethodReflection();
        $attribute = $this->helper->getLifetimeAttribute($method);

        if ($attribute === null) {
            return [];
        }

        $marker = sprintf('#[%s] on %s::%s()', class_basename($attribute->getName()), $method->getDeclaringClass()->getName(), $method->getName());
        $arguments = $attribute->getArgumentTypes();
        $errors = [];
        $names = [];

        foreach (['whenNotDefault', 'whenProvided'] as $argument) {
            $values = ($arguments[$argument] ?? null)?->getConstantStrings() ?? [];

            if (count($values) === 1) {
                $names[$argument] = $values[0]->getValue();
            }
        }

        if (count($names) === 2) {
            $errors[] = RuleErrorBuilder::message(sprintf('%s sets both whenNotDefault and whenProvided, but a marker takes one parameter condition.', $marker))
                ->tip('Keep the condition that decides whether a call is restricted.')
                ->identifier('hypervel.restrictedAttribute')
                ->build();
        }

        foreach ($names as $argument => $name) {
            $parameter = array_find(
                $method->getOnlyVariant()->getParameters(),
                static fn (ParameterReflection $parameter): bool => $parameter->getName() === $name,
            );

            if ($parameter === null) {
                $errors[] = RuleErrorBuilder::message(sprintf('%s names $%s in %s, but the method has no such parameter.', $marker, $name, $argument))
                    ->tip('Name the parameter whose argument makes a call restricted.')
                    ->identifier('hypervel.restrictedAttribute')
                    ->build();
            } elseif ($parameter->getDefaultValue() === null) {
                $errors[] = RuleErrorBuilder::message(sprintf('%s names $%s in %s, but $%s has no default, so every call is restricted.', $marker, $name, $argument, $name))
                    ->tip('Name an optional parameter, or remove the condition.')
                    ->identifier('hypervel.restrictedAttribute')
                    ->build();
            }
        }

        if ($method->isStatic() && (($arguments['whenShared'] ?? null)?->isTrue()->yes() ?? false)) {
            $errors[] = RuleErrorBuilder::message(sprintf('%s sets whenShared, but a static method has no instance for the container to share.', $marker))
                ->tip('Remove whenShared, so the restriction applies to every call.')
                ->identifier('hypervel.restrictedAttribute')
                ->build();
        }

        return $errors;
    }
}
