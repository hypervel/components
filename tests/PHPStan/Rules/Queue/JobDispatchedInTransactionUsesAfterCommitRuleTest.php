<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Queue;

use Hypervel\Container\Container;
use Hypervel\PHPStan\Rules\Queue\JobDispatchedInTransactionUsesAfterCommitRule;
use Hypervel\PHPStan\Support\ConfigParser;
use Hypervel\PHPStan\Support\ConstructorParser;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function str_replace;

/** @extends RuleTestCase<JobDispatchedInTransactionUsesAfterCommitRule> */
class JobDispatchedInTransactionUsesAfterCommitRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // A new rule reads the queue routes a test binds; the container's rule keeps the first routes it reads.
        $container = self::getContainer();

        return new JobDispatchedInTransactionUsesAfterCommitRule(
            $container->getByType(ReflectionProvider::class),
            $container->getByType(ConfigParser::class),
            $container->getByType(InitializerExprTypeResolver::class),
            $container->getByType(ConstructorParser::class),
            $container->getByType(RuntimeInputs::class),
        );
    }

    public function testRule(): void
    {
        $message = "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\NotifyOwner is dispatched inside a transaction without deferring until commit.\n    💡 Call afterCommit() on the dispatch, implement ShouldQueueAfterCommit, or enable after_commit on the queue connection.";

        $this->analyse([__DIR__ . '/data/transaction-dispatch.php'], [
            [$message, 44],
            [$message, 48],
            [$message, 52],
            [$message, 55],
            [$message, 78],
            [str_replace('NotifyOwner', 'DatabaseQueuedJob', $message), 128],
            [str_replace('NotifyOwner', 'DatabaseQueuedJob', $message), 132],
            [str_replace('NotifyOwner', 'QueueableNotifyOwner', $message), 173],
            [str_replace('NotifyOwner', 'QueueableNotifyOwner', $message), 179],
            [str_replace('Hypervel\Tests\PHPStan\Rules\Queue\Data\NotifyOwner', 'Hypervel\Queue\CallQueuedClosure', $message), 191],
            [$message, 199],
            [str_replace('NotifyOwner', 'DatabaseQueuedJob', $message), 205],
            [str_replace('NotifyOwner', 'SyncAttributeJob', $message), 225],
            [str_replace('NotifyOwner', 'RedisPropertyJob', $message), 226],
            [str_replace('NotifyOwner', 'ConstructorQueueJob', $message), 314],
            [$message, 353],
            [$message, 354],
            [$message, 355],
        ]);
    }

    public function testAfterCommitContract(): void
    {
        // Hypervel: a deliberate beforeCommit() or $afterCommit = false is respected, unlike upstream,
        // which reports both. Upstream's branch for Laravel versions before 12.22 is omitted.
        $this->analyse([__DIR__ . '/data/transaction-aftercommit-contract.php'], []);
    }

    public function testQueueForwardsMayChooseTheConnection(): void
    {
        $container = Container::getInstance();
        $routes = $container->make('queue.routes');
        $forwardingRoutes = clone $routes;
        $forwardingRoutes->forward('default', connection: 'sync');
        $container->instance('queue.routes', $forwardingRoutes);

        try {
            $this->analyse([__DIR__ . '/data/transaction-forwards.php'], [
                [
                    "Job Hypervel\\Tests\\PHPStan\\Rules\\Queue\\Data\\QueueableNotifyOwner is dispatched inside a transaction without deferring until commit.\n    💡 Call afterCommit() on the dispatch, implement ShouldQueueAfterCommit, or enable after_commit on the queue connection.",
                    12,
                ],
            ]);
        } finally {
            $container->instance('queue.routes', $routes);
        }
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../../phpstan-tests.neon'];
    }
}
