<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Sentry\Hub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\Attachment\Attachment;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\Profiling\ProfilerInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;

class HubTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application;
        $application->boot();
        Container::setInstance($application);
    }

    public function testConfiguredBaselineScopeIsClonedAndPreservedWhenClientIsBound(): void
    {
        $application = new Application;
        Container::setInstance($application);
        $baseline = new Scope;
        $baseline->setTag('baseline', 'yes');
        $hub = new Hub(scope: $baseline);
        $root = null;

        $hub->configureScope(static function (Scope $scope) use (&$root): void {
            $root = $scope;
        });

        $this->assertSame($baseline, $root);

        $client = Double::for(ClientInterface::class)->strict();
        $hub->bindClient($client);
        $application->boot();

        $this->assertSame($client, $hub->getClient());
        $this->assertSame(['baseline' => 'yes'], $this->scopeTags($hub));
    }

    public function testStandaloneConfigurationMutatesTheBaseline(): void
    {
        Container::setInstance(new Container);
        $hub = new Hub;

        $hub->configureScope(static function (Scope $scope): void {
            $scope->setTag('standalone', 'yes');
        });

        $this->assertSame(['standalone' => 'yes'], $this->scopeTags($hub));
    }

    public function testEveryCoroutineRootClonesTheBaselineScope(): void
    {
        $baseline = new Scope;
        $baseline->setTag('baseline', 'yes');
        $hub = new Hub(scope: $baseline);
        $firstReady = new Channel(1);
        $releaseFirst = new Channel(1);
        $results = new Channel(2);

        Coroutine::create(function () use ($firstReady, $hub, $releaseFirst, $results): void {
            $hub->configureScope(static function (Scope $scope): void {
                $scope->setTag('child', 'first');
            });
            $firstReady->push(true);
            $releaseFirst->pop();
            $results->push($this->scopeTags($hub));
        });
        try {
            $this->assertTrue($firstReady->pop(1.0));

            Coroutine::create(function () use ($hub, $results): void {
                $results->push($this->scopeTags($hub));
            });

            $secondTags = $results->pop(1.0);
        } finally {
            $releaseFirst->push(true);
        }

        $firstTags = $results->pop(1.0);

        $this->assertSame(['baseline' => 'yes'], $secondTags);
        $this->assertSame(['baseline' => 'yes', 'child' => 'first'], $firstTags);
        $this->assertSame(['baseline' => 'yes'], $this->scopeTags($hub));
    }

    public function testFinalRootLayerCannotBePoppedBeforeOrAfterNestedScopes(): void
    {
        $hub = new Hub;

        $this->assertFalse($hub->popScope());

        $hub->pushScope();

        $this->assertTrue($hub->popScope());
        $this->assertFalse($hub->popScope());
    }

    public function testFailingBeforeBreadcrumbCallbackDropsTheBreadcrumb(): void
    {
        $logger = Double::for(LoggerInterface::class)->strict();
        $logger->expects('error')
            ->with('The "before_breadcrumb" callback failed with exception: "Callback failed.".');
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')->returns(new Options([
            'logger' => $logger,
            'before_breadcrumb' => static fn (): ?Breadcrumb => throw new RuntimeException('Callback failed.'),
        ]));
        $hub = new Hub($client);

        $this->assertFalse($hub->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'test')));
        $this->assertSame([], $this->scopedEvent($hub)->getBreadcrumbs());
    }

    public function testAttachmentsAreAddedToTheCurrentScopeOnlyWithAClient(): void
    {
        $attachment = Attachment::fromBytes('test.txt', 'content');

        $this->assertFalse((new Hub)->addAttachment($attachment));

        $hub = new Hub(Double::for(ClientInterface::class)->strict());

        $this->assertTrue($hub->addAttachment($attachment));
        $this->assertSame([$attachment], $this->scopedEvent($hub)->getAttachments());
    }

    public function testProfilesSamplerControlsProfileSampling(): void
    {
        $called = false;
        $options = new Options([
            'traces_sample_rate' => 1.0,
            'profiles_sampler' => static function () use (&$called): float {
                $called = true;

                return 0.0;
            },
        ]);
        $client = Double::for(ClientInterface::class)->strict();
        $client->expects('getOptions')->returns($options);
        $transaction = (new Hub($client))->startTransaction(new TransactionContext('test'));

        $this->assertTrue($called);
        $this->assertTrue($transaction->getSampled());
        $this->assertNull($transaction->getProfiler());
    }

    public function testFailingSamplersFallBackToTheConfiguredRates(): void
    {
        $logger = Double::for(LoggerInterface::class)->strict();
        $logger->allows('info');
        $logger->allows('warning')->with('The profiler was started but is not available because the "excimer" extension is not loaded.');
        $logger->expects('error')
            ->with('The "traces_sampler" callback failed with exception: "Traces sampler failed.".');
        $logger->expects('error')
            ->with('The "profiles_sampler" callback failed with exception: "Profiles sampler failed.".');
        $client = Double::for(ClientInterface::class)->strict();
        $client->allows('getOptions')->returns(new Options([
            'logger' => $logger,
            'traces_sample_rate' => 1.0,
            'traces_sampler' => static fn (): float => throw new RuntimeException('Traces sampler failed.'),
            'profiles_sample_rate' => 1.0,
            'profiles_sampler' => static fn (): float => throw new RuntimeException('Profiles sampler failed.'),
        ]));

        $transaction = (new Hub($client))->startTransaction(new TransactionContext('test'));

        $profiler = $transaction->getProfiler();
        try {
            $this->assertTrue($transaction->getSampled());
            $this->assertSame(1.0, $transaction->getMetadata()->getSamplingRate());
            $this->assertNotNull($profiler);
        } finally {
            $profiler?->stop();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testProfilerStartupFailureLeavesTheTransactionSampled(bool $failInFactory): void
    {
        $failure = new RuntimeException('Profiler unavailable.');
        $logger = Double::for(LoggerInterface::class)->strict();
        $logger->allows('info');
        $logger->expects('error')->with('Failed to start the profiler. Reason: "Profiler unavailable.".', ['exception' => $failure]);
        $profiler = Double::for(ProfilerInterface::class)->strict();
        if (! $failInFactory) {
            $profiler->expects('start')->throws($failure);
        }
        $client = Double::for(ClientInterface::class)->strict();
        $client->allows('getOptions')->returns(new Options([
            'logger' => $logger,
            'traces_sample_rate' => 1.0,
            'profiles_sample_rate' => 1.0,
            'profiler_factory' => static fn (): ProfilerInterface => $failInFactory ? throw $failure : $profiler,
        ]));

        $transaction = (new Hub($client))->startTransaction(new TransactionContext('profile'));
        $this->assertTrue($transaction->getSampled());
        $this->assertNull($transaction->getProfiler());
    }

    #[DataProvider('cancellableCallbacks')]
    public function testCallbackCancellationPropagatesUnchanged(string $option, Closure $action, bool $wrapped): void
    {
        $cancellation = new CanceledException('Canceled.');

        if ($wrapped) {
            $cancellation = new RuntimeException('Callback cleanup failed.', previous: $cancellation);
        }

        $client = Double::for(ClientInterface::class)->strict();
        $client->allows('getOptions')->returns(new Options([
            'traces_sample_rate' => 1.0,
            'profiles_sample_rate' => 1.0,
            $option => static fn (): never => throw $cancellation,
        ]));
        $caught = null;

        try {
            $action(new Hub($client));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }

    /**
     * Provide the application callbacks the Hub invokes.
     */
    public static function cancellableCallbacks(): array
    {
        $startTransaction = static fn (Hub $hub): Transaction => $hub->startTransaction(new TransactionContext('test'));

        $callbacks = [
            'before_breadcrumb' => ['before_breadcrumb', static fn (Hub $hub): bool => $hub->addBreadcrumb(
                new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'test')
            ), false],
            'traces_sampler' => ['traces_sampler', $startTransaction, false],
            'profiles_sampler' => ['profiles_sampler', $startTransaction, false],
            'profiler_factory' => ['profiler_factory', $startTransaction, false],
        ];

        foreach ($callbacks as $name => [$option, $action]) {
            $callbacks[$name . ' with cleanup failure'] = [$option, $action, true];
        }

        return $callbacks;
    }

    /**
     * Get the tags applied by the current Hub scope.
     *
     * @return array<string, string>
     */
    private function scopeTags(Hub $hub): array
    {
        return $this->scopedEvent($hub)->getTags();
    }

    /**
     * Apply the current Hub scope to a new event.
     */
    private function scopedEvent(Hub $hub): Event
    {
        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event);
        });

        return $event;
    }
}
