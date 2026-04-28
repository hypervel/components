<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Sentry\Features\QueueFeature;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\SentryTestCase;
use Sentry\Breadcrumb;
use Sentry\EventType;

use function Sentry\addBreadcrumb;
use function Sentry\captureException;

class QueueIntegrationTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.features' => [
            QueueFeature::class,
        ],
    ];

    protected function withTracingEnabled(ApplicationContract $app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
    }

    protected function withQueueJobTracingDisabled(ApplicationContract $app): void
    {
        $app['config']->set('sentry.traces_sample_rate', 1.0);
        $app['config']->set('sentry.tracing.queue_job_transactions', false);
    }

    public function testQueueJobPushesAndPopsScopeWithBreadcrumbs(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());
    }

    public function testQueueJobThatReportsPushesAndPopsScopeWithBreadcrumbs(): void
    {
        dispatch(new QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb);

        $this->assertCount(0, $this->getCurrentSentryBreadcrumbs());

        $this->assertNotNull($this->getLastSentryEvent());

        $event = $this->getLastSentryEvent();

        $this->assertCount(2, $event->getBreadcrumbs());
    }

    public function testQueueJobThatThrowsLeavesPushedScopeWithBreadcrumbs(): void
    {
        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb);
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        // We still expect to find the breadcrumbs from the job here so they are attached to reported exceptions

        $this->assertCount(2, $this->getCurrentSentryBreadcrumbs());

        $firstBreadcrumb = $this->getCurrentSentryBreadcrumbs()[0];
        $this->assertEquals('queue.job', $firstBreadcrumb->getCategory());

        $secondBreadcrumb = $this->getCurrentSentryBreadcrumbs()[1];
        $this->assertEquals('test', $secondBreadcrumb->getCategory());
    }

    public function testQueueJobsThatThrowPopsAndPushesScopeWithBreadcrumbsBeforeNewJob(): void
    {
        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('test #1'));
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        try {
            dispatch(new QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb('test #2'));
        } catch (Exception $e) {
            // No action required, expected to throw
        }

        // We only expect to find the breadcrumbs from the second job here

        $this->assertCount(2, $this->getCurrentSentryBreadcrumbs());

        $firstBreadcrumb = $this->getCurrentSentryBreadcrumbs()[0];
        $this->assertEquals('queue.job', $firstBreadcrumb->getCategory());

        $secondBreadcrumb = $this->getCurrentSentryBreadcrumbs()[1];
        $this->assertEquals('test #2', $secondBreadcrumb->getMessage());
    }

    public function testQueueJobsWithBreadcrumbSetInBetweenKeepsNonJobBreadcrumbsOnCurrentScope(): void
    {
        dispatch(new QueueEventsTestJobWithBreadcrumb);

        addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::LEVEL_DEBUG, 'test2', 'test2'));

        dispatch(new QueueEventsTestJobWithBreadcrumb);

        $this->assertCount(1, $this->getCurrentSentryBreadcrumbs());
    }

    #[DefineEnvironment('withTracingEnabled')]
    public function testQueueJobCreatesTransactionByDefault(): void
    {
        dispatch(new QueueEventsTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNotNull($transaction);

        $this->assertEquals(EventType::transaction(), $transaction->getType());
        $this->assertEquals(QueueEventsTestJob::class, $transaction->getTransaction());

        $traceContext = $transaction->getContexts()['trace'];

        $this->assertEquals('queue.process', $traceContext['op']);
    }

    #[DefineEnvironment('withQueueJobTracingDisabled')]
    public function testQueueJobDoesntCreateTransaction(): void
    {
        dispatch(new QueueEventsTestJob);

        $transaction = $this->getLastSentryEvent();

        $this->assertNull($transaction);
    }
}

class QueueEventsTestJob implements ShouldQueue
{
    public function handle(): void
    {
    }
}

function queueEventsTestAddTestBreadcrumb($message = null): void
{
    addBreadcrumb(
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::LEVEL_DEBUG,
            'test',
            $message ?? 'test'
        )
    );
}

class QueueEventsTestJobWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();
    }
}

class QueueEventsTestJobThatReportsAnExceptionWithBreadcrumb implements ShouldQueue
{
    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb();

        captureException(new Exception('This is a test exception'));
    }
}

class QueueEventsTestJobThatThrowsAnUnhandledExceptionWithBreadcrumb implements ShouldQueue
{
    public function __construct(
        private ?string $message = null,
    ) {
    }

    public function handle(): void
    {
        queueEventsTestAddTestBreadcrumb($this->message);

        throw new Exception('This is a test exception');
    }
}
