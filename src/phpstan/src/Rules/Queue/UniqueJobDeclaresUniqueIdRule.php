<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * A *parameterized* job implementing `ShouldBeUnique` must declare `uniqueId`,
 * either as a method (`public function uniqueId(): string`) or a property
 * (`public $uniqueId`).
 *
 * Hypervel builds the uniqueness lock key as `laravel_unique_job:<class>:<uniqueId>`
 * and falls back to an empty `uniqueId` when neither is declared (see
 * `Hypervel\Bus\UniqueLock::getKey()`). For a job that carries no distinguishing
 * state that empty key is correct: the class is a singleton, only one may run at
 * a time. But for a job whose constructor takes arguments (per company, per
 * product, ...) the empty key collapses *every* dispatch into one unique job
 * regardless of those arguments, so legitimately distinct jobs are silently
 * dropped at dispatch with no error.
 *
 * The rule therefore fires only when the job has a constructor with at least one
 * parameter. A genuinely class wide unique job satisfies it by declaring
 * `uniqueId()` returning a constant, which makes that intent explicit.
 *
 * @implements Rule<InClassNode>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkUniqueJobUniqueId%')]
class UniqueJobDeclaresUniqueIdRule implements Rule
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
     * Report a parameterized unique job that does not declare its identity.
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

        if (! $this->hasConstructorParameters($classReflection)) {
            // Parameterless job: the class name only lock key is correct.
            return [];
        }

        // The lock key calls any uniqueId() method from outside the job, so the method wins over
        // the property, and must be public. It cannot read a hidden property.
        if ($classReflection->hasNativeMethod('uniqueId')) {
            if ($classReflection->getNativeMethod('uniqueId')->isPublic()) {
                return [];
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Unique job %s declares uniqueId() but does not make it public.',
                    $classReflection->getDisplayName(),
                ))
                    ->tip('Make uniqueId() public so the unique lock can call it.')
                    ->identifier('hypervel.uniqueJobUniqueId')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        if ($classReflection->hasNativeProperty('uniqueId')) {
            if ($classReflection->getNativeProperty('uniqueId')->isPublic()) {
                return [];
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    'Unique job %s declares $uniqueId but does not make it public.',
                    $classReflection->getDisplayName(),
                ))
                    ->tip('Make $uniqueId public so the unique lock can read it.')
                    ->identifier('hypervel.uniqueJobUniqueId')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Unique job %s has constructor parameters but does not declare uniqueId.',
                $classReflection->getDisplayName(),
            ))
                ->tip('Declare a public uniqueId() method or a public $uniqueId property to identify distinct jobs.')
                ->identifier('hypervel.uniqueJobUniqueId')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /**
     * Determine whether the job's constructor takes parameters.
     */
    private function hasConstructorParameters(ClassReflection $classReflection): bool
    {
        if (! $classReflection->hasConstructor()) {
            return false;
        }

        $variants = $classReflection->getConstructor()->getVariants();

        return $variants !== [] && $variants[0]->getParameters() !== [];
    }
}
