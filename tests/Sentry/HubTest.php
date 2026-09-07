<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Application;
use Hypervel\Sentry\Hub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\State\Scope;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine\Channel;

class HubTest extends TestCase
{
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

    /**
     * Get the tags applied by the current Hub scope.
     *
     * @return array<string, string>
     */
    private function scopeTags(Hub $hub): array
    {
        $event = Event::createEvent();
        $hub->configureScope(static function (Scope $scope) use (&$event): void {
            $event = $scope->applyToEvent($event);
        });

        return $event->getTags();
    }
}
