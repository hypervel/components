<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Queue;

use Closure;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\PendingBatch;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\PHPStan\Concerns\InspectsQueuedJobs;
use Hypervel\Support\Facades\Bus;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

use function sprintf;

/**
 * Every job added to a batch must use the `Batchable` trait.
 *
 * The batch wires each job back to its parent batch so the job can read progress
 * and short circuit (`$this->batch()->cancelled()`), and so the batch can
 * reconcile its job count and fire its then/catch/finally callbacks. All of that
 * lives in `Batchable`, so `PendingBatch` throws when the batch is created with
 * a job that does not use it, and `Batch::add()` fails calling its missing
 * `withBatchId()`.
 *
 * Nested arrays represent chains within a batch, and chained jobs need the trait too.
 *
 * @implements Rule<CallLike>
 */
#[Conditional(attribute: new AutowiredService, if: '%checkBatchedJobIsBatchable%')]
class BatchedJobIsBatchableRule implements Rule
{
    use InspectsQueuedJobs;

    /**
     * The batch methods of each facade, keyed by its alias.
     *
     * @var array<string, array{class-string, list<string>}>
     */
    private const array FACADE_METHODS = [
        'Bus' => [Bus::class, ['batch']],
    ];

    /**
     * The batch methods each receiver type provides.
     *
     * Unlike upstream, which checks only Bus::batch(), adding jobs to a batch needs the trait too.
     *
     * @var array<class-string, list<string>>
     */
    private const array RECEIVER_METHODS = [
        QueueingDispatcher::class => ['batch'],
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
     * Report batched jobs that do not use Batchable.
     *
     * @param CallLike $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->getQueueMethod($node, $scope, self::FACADE_METHODS, self::RECEIVER_METHODS) === null) {
            return [];
        }

        $jobsArg = $node->getArg('jobs', 0);

        if ($jobsArg === null) {
            return [];
        }

        $errors = [];

        // Unlike upstream, which checks only array literals, a variable's job type and a single job count too.
        foreach ($this->getQueuedJobTypes($jobsArg->value, $node, $scope) as [$type, $reportNode]) {
            // Every path the entry may take must hold a job without the trait.
            $jobs = $this->getMatchingJobs($type, $this->isNonBatchableJob(...), true);

            if ($jobs === []) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                'Job %s is batched but does not use Batchable.',
                $this->describeJobClasses($jobs),
            ))
                ->tip('Use the Hypervel\Bus\Batchable trait.')
                ->identifier('hypervel.batchedJobIsBatchable')
                ->line($reportNode->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * Determine whether the value is definitely a job that does not use Batchable.
     */
    private function isNonBatchableJob(Type $type): bool
    {
        $reflections = $type->getObjectClassReflections();

        if ($reflections === []) {
            return false;
        }

        foreach ($reflections as $classReflection) {
            // Unlike upstream, which checks only queued jobs, this follows PendingBatch: every job
            // except a closure or a nested batch needs the trait. An interface or abstract class
            // may be implemented by a job that uses it.
            if (
                ! $this->isDispatchableClass($classReflection)
                || $classReflection->is(Closure::class)
                || $classReflection->is(PendingBatch::class)
            ) {
                return false;
            }

            if ($this->usesTrait($classReflection, Batchable::class)) {
                return false;
            }
        }

        return true;
    }
}
