<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Hypervel\Bus\Batch;
use Hypervel\Bus\PendingBatch;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function sprintf;

/**
 * A job implementing `ShouldBeUnique` must not be dispatched through the bulk or
 * batch entry points: `Bus::batch([...])`, `Bus::bulk([...])`, `Queue::bulk([...])`,
 * or a batch's `add()`.
 *
 * Only an individual dispatch acquires the unique lock. Bulk dispatch and
 * batches push the jobs through the queue's `bulk()` without it, so duplicates
 * are queued and `ShouldBeUnique` silently does nothing. A later job of a chain
 * within a batch is dispatched individually when the chain advances, but a
 * duplicate is then skipped after the batch has counted it, so the batch never
 * finishes.
 *
 * Dispatch unique jobs individually instead.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%noBatchedUniqueJob%')]
class NoBatchedUniqueJobRule implements Rule
{
    use InspectsQueuedJobs;

    /**
     * The bulk and batch methods of each facade, keyed by its alias.
     *
     * @var array<string, array{class-string, list<string>}>
     */
    private const array FACADE_METHODS = [
        'Bus' => [Bus::class, ['batch', 'bulk']],
        'Queue' => [Queue::class, ['bulk']],
    ];

    /**
     * The bulk and batch methods each receiver type provides.
     *
     * Unlike upstream, which checks only the facades, the documented $this->batch()->add() and
     * bulk calls on dispatcher and queue instances bypass the lock too.
     *
     * @var array<class-string, list<string>>
     */
    private const array RECEIVER_METHODS = [
        QueueingDispatcher::class => ['batch', 'bulk'],
        QueueContract::class => ['bulk'],
        PendingBatch::class => ['add'],
        Batch::class => ['add'],
    ];

    /**
     * Get the node type the rule inspects.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report unique jobs dispatched through a bulk or batch entry point.
     *
     * @param CallLike $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $this->getQueueMethod($node, $scope, self::FACADE_METHODS, self::RECEIVER_METHODS);
        $jobsArg = $node->getArg('jobs', 0);

        if ($method === null || $jobsArg === null) {
            return [];
        }

        $errors = [];

        // Unlike upstream, a single job is checked too: the entry points wrap it.
        foreach ($this->getQueuedJobTypes($jobsArg->value, $node, $scope) as [$type, $reportNode]) {
            // A unique job on any path the entry may take bypasses the lock.
            $jobs = $this->getMatchingJobs($type, $this->isUniqueJobType(...), false);

            if ($jobs === []) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message($this->buildMessage($jobs, $method))
                ->tip('Dispatch unique jobs individually.')
                ->identifier('hypervel.noBatchedUniqueJob')
                ->line($reportNode->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Determine whether the type is a unique job.
     */
    private function isUniqueJobType(Type $type): bool
    {
        foreach ($type->getObjectClassReflections() as $classReflection) {
            if ($this->isDispatchableClass($classReflection) && $classReflection->is(ShouldBeUnique::class)) {
                return true;
            }
        }

        // A value typed as a unique interface or abstract class holds a unique job, whatever its class.
        return (new ObjectType(ShouldBeUnique::class))->isSuperTypeOf($type)->yes();
    }

    /**
     * Build the message naming the unique jobs and the entry point.
     *
     * @param list<Type> $jobs
     */
    private function buildMessage(array $jobs, string $method): string
    {
        return sprintf(
            'Unique job %s is dispatched via %s().',
            $this->describeJobClasses($jobs),
            $method,
        );
    }
}
