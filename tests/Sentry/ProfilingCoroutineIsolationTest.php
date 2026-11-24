<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Sentry\State\RuntimeContextBoundary;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Sentry\Profiling\Profile;
use Sentry\Tracing\TransactionContext;
use WeakReference;

use function Hypervel\Coroutine\parallel;

#[RequiresPhpExtension('excimer')]
class ProfilingCoroutineIsolationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.profiles_sample_rate' => 1.0,
    ];

    public function testTransactionProfileExcludesWorkFromAnotherRequest(): void
    {
        $requestAStarted = new Channel(1);
        $requestBFinished = new Channel(1);

        parallel([
            function () use ($requestAStarted, $requestBFinished): void {
                $this->app->make(RuntimeContextBoundary::class)->start();
                $transaction = $this->getSentryHubFromContainer()->startTransaction(new TransactionContext('request-a'));

                try {
                    $this->requestAWork();
                    $requestAStarted->push(true);

                    // A remains profiled while it yields to B, just as when waiting for I/O.
                    $this->assertTrue($requestBFinished->pop(2.0));
                    $this->requestAWork();
                } finally {
                    $transaction->finish();
                }
            },
            function () use ($requestAStarted, $requestBFinished): void {
                try {
                    $this->assertTrue($requestAStarted->pop(2.0));
                    $this->app->make(RuntimeContextBoundary::class)->start();
                    $this->requestBWork();
                } finally {
                    $requestBFinished->push(true);
                }
            },
        ]);

        $this->assertSentryTransactionCount(1);
        $event = $this->getLastSentryEvent();
        $this->assertNotNull($event);
        $this->assertSame('request-a', $event->getTransaction());

        $profile = $event->getSdkMetadata('profile');
        $this->assertInstanceOf(Profile::class, $profile);
        $payload = $profile->getFormattedData($event);
        $this->assertNotNull($payload);

        $functions = array_column($payload['profile']['frames'], 'function');

        $stacks = array_map(static fn (array $sample): array => $payload['profile']['stacks'][$sample['stack_id']], $payload['profile']['samples']);
        $this->assertNotEmpty($stacks[0]);
        $this->assertContains([], $stacks);
        $this->assertNotEmpty(end($stacks));
        $this->assertContains(self::class . '::requestAWork', $functions);
        $this->assertNotContains(
            self::class . '::requestBWork',
            $functions,
            'Request A\'s profile must not contain work performed by request B while A was suspended.',
        );
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testOnlyChildrenSharingTheRequestAreProfiled(bool $hasContext): void
    {
        if ($hasContext) {
            $this->app->make(RuntimeContextBoundary::class)->start();
        }

        $transaction = $this->getSentryHubFromContainer()->startTransaction(new TransactionContext('parent'));
        try {
            $this->requestAWork();
            Coroutine::create(fn () => $this->attachedChildWork());
            Coroutine::createOwned(fn () => $this->requestBWork(), static fn (Closure $run) => $run(), detached: true);
        } finally {
            $transaction->finish();
        }

        $event = $this->getLastSentryEvent();
        $payload = $event->getSdkMetadata('profile')->getFormattedData($event);
        $functions = array_column($payload['profile']['frames'], 'function');

        $this->assertSame($hasContext, in_array(self::class . '::attachedChildWork', $functions, true));
        $this->assertContains(self::class . '::requestAWork', $functions);
        $this->assertNotContains(self::class . '::requestBWork', $functions);
    }

    public function testAnUnfinishedTransactionReleasesItsProfilerWhenCollected(): void
    {
        $transaction = $this->getSentryHubFromContainer()->startTransaction(new TransactionContext('unfinished'));
        $profiler = WeakReference::create($transaction->getProfiler());
        unset($transaction);
        gc_collect_cycles();

        $this->assertNull($profiler->get());
    }

    /**
     * Run an identifiable stack in an attached child coroutine.
     */
    private function attachedChildWork(): void
    {
        $this->requestAWork();
    }

    /**
     * Keep request A running across several profiler ticks.
     */
    private function requestAWork(): void
    {
        $deadline = hrtime(true) + 100_000_000;

        while (hrtime(true) < $deadline);
    }

    /**
     * Keep request B running across several profiler ticks.
     */
    private function requestBWork(): void
    {
        $deadline = hrtime(true) + 100_000_000;

        while (hrtime(true) < $deadline);
    }
}
