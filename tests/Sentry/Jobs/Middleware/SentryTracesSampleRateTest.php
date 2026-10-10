<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Jobs\Middleware;

use Closure;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Queue\Jobs\SyncJob;
use Hypervel\Sentry\Jobs\Middleware\SentryTracesSampleRate;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\TestCase;
use InvalidArgumentException;
use Sentry\EventType;
use Sentry\Tracing\Transaction;
use stdClass;

class SentryTracesSampleRateTest extends TestCase
{
    private SyncJob $job;

    /**
     * Enable tracing for every transaction.
     */
    protected function withTracingEnabled(ApplicationContract $app): void
    {
        $app->make('config')->set('sentry.traces_sample_rate', 1.0);
    }

    /**
     * Enable tracing with queue job transactions but without queue job spans.
     */
    protected function withQueueJobSpansDisabled(ApplicationContract $app): void
    {
        $config = $app->make('config');

        $config->set('sentry.traces_sample_rate', 1.0);
        $config->set('sentry.tracing.queue_jobs', false);
    }

    public function testConstructorRejectsNegativeSampleRate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SentryTracesSampleRate(-0.1);
    }

    public function testConstructorRejectsSampleRateGreaterThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SentryTracesSampleRate(1.1);
    }

    public function testConstructorAcceptsBoundarySampleRates(): void
    {
        $zero = new SentryTracesSampleRate(0.0);
        $one = new SentryTracesSampleRate(1.0);

        $this->assertInstanceOf(SentryTracesSampleRate::class, $zero);
        $this->assertInstanceOf(SentryTracesSampleRate::class, $one);
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testZeroSampleRateUnsamplesTransaction(): void
    {
        $transaction = $this->startJobTransaction();

        $this->assertTrue($transaction->getSampled());

        $middleware = new SentryTracesSampleRate(0.0);
        $middleware->handle(new stdClass, $this->nextMiddleware());
        $this->finishJob();

        $this->assertFalse($transaction->getSampled());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testFullSampleRateKeepsTransactionSampled(): void
    {
        $transaction = $this->startJobTransaction();

        $this->assertTrue($transaction->getSampled());

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new stdClass, $this->nextMiddleware());
        $this->finishJob();

        $this->assertTrue($transaction->getSampled());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testDoesNotUpsampleUnsampledTransaction(): void
    {
        $transaction = $this->startJobTransaction();
        $transaction->setSampled(false);

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new stdClass, $this->nextMiddleware());
        $this->finishJob();

        $this->assertFalse($transaction->getSampled());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testDoesNotUpsampleNullSampledTransaction(): void
    {
        $transaction = $this->startJobTransaction();
        $transaction->setSampled(null);

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new stdClass, $this->nextMiddleware());
        $this->finishJob();

        $this->assertNull($transaction->getSampled());
    }

    public function testMiddlewareCallsNextHandler(): void
    {
        $called = false;

        $middleware = new SentryTracesSampleRate(1.0);
        $middleware->handle(new stdClass, function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testMiddlewareHandlesNoTransaction(): void
    {
        $this->expectNotToPerformAssertions();

        $middleware = new SentryTracesSampleRate(0.5);
        $middleware->handle(new stdClass, $this->nextMiddleware());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testPartialSampleRateEventuallyUnsamplesTransaction(): void
    {
        // With a very low sample rate, running 100 times should produce at least some unsampled results
        $unsampledCount = 0;

        for ($i = 0; $i < 100; ++$i) {
            $transaction = $this->startJobTransaction();

            $middleware = new SentryTracesSampleRate(0.01);
            $middleware->handle(new stdClass, $this->nextMiddleware());
            $this->finishJob();

            if (! $transaction->getSampled()) {
                ++$unsampledCount;
            }
        }

        // With a 1% sample rate, we expect most of the 100 runs to be unsampled
        $this->assertGreaterThan(80, $unsampledCount);
    }

    #[DefineEnvironment('envWithoutDsnSet')]
    public function testMiddlewareHandlesHubNotBound(): void
    {
        $this->expectNotToPerformAssertions();

        $middleware = new SentryTracesSampleRate(0.5);
        $middleware->handle(new stdClass, $this->nextMiddleware());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testDispatchedJobResamplesItsOwnTransaction(): void
    {
        dispatch(new SentryTracesSampleRateTestJob(0.0));

        $this->assertSame([], $this->getCapturedSentryEventsOfType(EventType::transaction()));

        dispatch(new SentryTracesSampleRateTestJob(1.0));

        $this->assertCount(1, $this->getCapturedSentryEventsOfType(EventType::transaction()));
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testJobWithinAnotherTransactionDoesNotResampleIt(): void
    {
        $transaction = $this->startTransaction();

        dispatch(new SentryTracesSampleRateTestJob(0.0));

        $this->assertTrue($transaction->getSampled());
    }

    #[DefineEnvironment('withQueueJobSpansDisabled')]
    public function testNestedJobWithoutASpanDoesNotResampleTheParentJobTransaction(): void
    {
        dispatch(new SentryTracesSampleRateTestParentJob(new SentryTracesSampleRateTestJob(0.0)));

        $transactions = $this->getCapturedSentryEventsOfType(EventType::transaction());

        $this->assertCount(1, $transactions);
        $this->assertSame(SentryTracesSampleRateTestParentJob::class, $transactions[0][0]->getTransaction());
    }

    /**
     * Start processing a synchronous job and return the transaction started for it.
     */
    private function startJobTransaction(): Transaction
    {
        $this->job = new SyncJob(
            $this->app,
            json_encode(['uuid' => 'sampled-job', 'job' => 'SampledJob', 'data' => []], JSON_THROW_ON_ERROR),
            'sync',
            'default',
        );

        $this->dispatchHypervelEvent(new JobProcessing('sync', $this->job));

        return $this->getSentryHubFromContainer()->getTransaction();
    }

    /**
     * Finish processing the job started by startJobTransaction().
     */
    private function finishJob(): void
    {
        $this->dispatchHypervelEvent(new JobProcessed('sync', $this->job));
    }

    /**
     * Get a next middleware callback that does nothing.
     */
    private function nextMiddleware(): Closure
    {
        return static function (): void {
        };
    }
}

class SentryTracesSampleRateTestJob implements ShouldQueue
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        private float $sampleRate,
    ) {
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new SentryTracesSampleRate($this->sampleRate)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
    }
}

class SentryTracesSampleRateTestParentJob implements ShouldQueue
{
    /**
     * Create a new job instance.
     */
    public function __construct(
        private ShouldQueue $child,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        dispatch($this->child);
    }
}
