<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use Hypervel\PHPStan\Reflection\ReflectionHelper;
use Hypervel\Queue\Attributes\UniqueFor;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * Every job implementing `ShouldBeUnique` must declare how long it stays unique,
 * with a `#[UniqueFor]` attribute, a public `uniqueFor()` method or a public
 * `$uniqueFor` property.
 *
 * Without one, the lock is acquired with a zero duration, whose meaning depends
 * on the cache store. Normal completion and final failure release the lock, but
 * a worker that dies mid job can leave it held. Declaring `uniqueFor` states
 * that policy explicitly.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkUniqueJobUniqueFor%')]
class UniqueJobDeclaresUniqueForRule implements Rule
{
    use InspectsQueuedJobs;

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report a unique job that does not declare how long it stays unique.
     *
     * @param InClassNode $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (! $this->isDispatchableClass($classReflection)) {
            return [];
        }

        if (! $classReflection->is(ShouldBeUnique::class)) {
            return [];
        }

        // The unique lock calls any uniqueFor() method from outside the job, so the method wins
        // over the attribute and property, and must be public. It cannot read a hidden property.
        if ($classReflection->hasNativeMethod('uniqueFor')) {
            if ($classReflection->getNativeMethod('uniqueFor')->isPublic()) {
                return [];
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Job %s declares uniqueFor() but does not make it public.',
                    $classReflection->getDisplayName(),
                ))
                    ->tip('Make uniqueFor() public so the unique lock can call it.')
                    ->identifier('hypervel.uniqueJobUniqueFor')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        if (ReflectionHelper::findClassAttribute($classReflection, UniqueFor::class) !== null) {
            return [];
        }

        if ($classReflection->hasNativeProperty('uniqueFor')) {
            if ($classReflection->getNativeProperty('uniqueFor')->isPublic()) {
                return [];
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Job %s declares $uniqueFor but does not make it public.',
                    $classReflection->getDisplayName(),
                ))
                    ->tip('Make $uniqueFor public so the unique lock can read it, or use a #[UniqueFor] attribute.')
                    ->identifier('hypervel.uniqueJobUniqueFor')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Job %s implements ShouldBeUnique but does not declare uniqueFor.',
                $classReflection->getDisplayName(),
            ))
                ->tip('Add a #[UniqueFor] attribute, or a public uniqueFor() method for a computed duration.')
                ->identifier('hypervel.uniqueJobUniqueFor')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
