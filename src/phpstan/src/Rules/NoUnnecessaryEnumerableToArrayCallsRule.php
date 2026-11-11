<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\Support\Enumerable;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * This rule checks for unnecessary calls `Enumerable::toArray()` that
 * could have used `all()` instead. The `toArray()` method recursively
 * converts all Arrayable items in the Enumerable to an array and if
 * none of the items are Arrayable, it is unnecessary map call.
 *
 * For example:
 *     collect([1, 2, 3])->toArray()
 * could be simplified to:
 *      collect([1, 2, 3])->all()
 *
 * @implements Rule<MethodCall>
 */
#[Conditional(attribute: new AutowiredService, if: '%noUnnecessaryEnumerableToArrayCalls%')]
class NoUnnecessaryEnumerableToArrayCallsRule implements Rule
{
    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * Report toArray() calls that return the same array as all().
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name;

        if (! $name instanceof Identifier || $name->toLowerString() !== 'toarray') {
            return [];
        }

        $calledOnType = $scope->getType($node->var);

        if (! (new ObjectType(Enumerable::class))->isSuperTypeOf($calledOnType)->yes()) {
            return [];
        }

        $valueType = $calledOnType->getTemplateType(Enumerable::class, 'TValue');

        if (! (new ObjectType(Arrayable::class))->isSuperTypeOf($valueType)->no()) {
            return [];
        }

        // Upstream checks only the items. A collection that overrides toArray(), all() or the
        // map() that toArray() runs may convert its items differently, as may another Enumerable.
        // The Eloquent collection's map() only changes the collection class it returns.
        foreach ($calledOnType->getObjectClassReflections() as $classReflection) {
            if (! CollectionHelper::usesFrameworkMethods($classReflection, ['toArray', 'all', 'map'])) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                'Called [toArray()] on an Enumerable which does not contain any Arrayables.',
            )
                ->tip('Use [all()] to get the items as an array.')
                ->identifier('hypervel.unnecessaryEnumerableToArrayCall')
                ->line($name->getStartLine())
                ->build(),
        ];
    }
}
