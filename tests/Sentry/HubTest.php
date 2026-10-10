<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Sentry\Hub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Sentry\Attachment\Attachment;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\State\Scope;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;

class HubTest extends TestCase
{
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

        $client = m::mock(ClientInterface::class);
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
        $this->assertTrue($firstReady->pop(1.0));

        Coroutine::create(function () use ($hub, $results): void {
            $results->push($this->scopeTags($hub));
        });

        $secondTags = $results->pop(1.0);
        $releaseFirst->push(true);
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
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('The "before_breadcrumb" callback failed with exception: "Callback failed.".');
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')->once()->andReturn(new Options([
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

        $hub = new Hub(m::mock(ClientInterface::class));

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
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')->once()->andReturn($options);
        $transaction = (new Hub($client))->startTransaction(new TransactionContext('test'));

        $this->assertTrue($called);
        $this->assertTrue($transaction->getSampled());
        $this->assertNull($transaction->getProfiler());
    }

    public function testFailingSamplersFallBackToTheConfiguredRates(): void
    {
        $logger = m::mock(LoggerInterface::class)->shouldIgnoreMissing();
        $logger->shouldReceive('error')
            ->once()
            ->with('The "traces_sampler" callback failed with exception: "Traces sampler failed.".');
        $logger->shouldReceive('error')
            ->once()
            ->with('The "profiles_sampler" callback failed with exception: "Profiles sampler failed.".');
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')->andReturn(new Options([
            'logger' => $logger,
            'traces_sample_rate' => 1.0,
            'traces_sampler' => static fn (): float => throw new RuntimeException('Traces sampler failed.'),
            'profiles_sample_rate' => 1.0,
            'profiles_sampler' => static fn (): float => throw new RuntimeException('Profiles sampler failed.'),
        ]));

        $transaction = (new Hub($client))->startTransaction(new TransactionContext('test'));

        $this->assertTrue($transaction->getSampled());
        $this->assertSame(1.0, $transaction->getMetadata()->getSamplingRate());
        $this->assertNotNull($transaction->getProfiler());
    }

    #[DataProvider('cancellableCallbacks')]
    public function testCallbackCancellationPropagatesUnchanged(string $option, Closure $action): void
    {
        $cancellation = new CanceledException('Canceled.');
        $client = m::mock(ClientInterface::class);
        $client->shouldReceive('getOptions')->andReturn(new Options([
            'traces_sample_rate' => 1.0,
            $option => static fn (): never => throw $cancellation,
        ]));

        try {
            $action(new Hub($client));
            $this->fail('The callback cancellation was not propagated.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    /**
     * Provide the application callbacks the Hub invokes.
     */
    public static function cancellableCallbacks(): array
    {
        $startTransaction = static fn (Hub $hub): Transaction => $hub->startTransaction(new TransactionContext('test'));

        return [
            'before_breadcrumb' => ['before_breadcrumb', static fn (Hub $hub): bool => $hub->addBreadcrumb(
                new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'test')
            )],
            'traces_sampler' => ['traces_sampler', $startTransaction],
            'profiles_sampler' => ['profiles_sampler', $startTransaction],
        ];
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
