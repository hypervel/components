<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hypervel\Contracts\Container\Container;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\ListenerProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class EventsSubscriberTest extends TestCase
{
    /**
     * @var Container|MockInterface
     */
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
    }

    public function testEventSubscribers()
    {
        $d = $this->getEventDispatcher();
        $subs = m::mock(ExampleSubscriber::class);
        $subs->shouldReceive('subscribe')->once()->with($d);
        $this->container->shouldReceive('get')->once()->with(ExampleSubscriber::class)->andReturn($subs);

        $d->subscribe(ExampleSubscriber::class);
    }

    public function testEventSubscribeCanAcceptObject()
    {
        $d = $this->getEventDispatcher();
        $subs = m::mock(ExampleSubscriber::class);
        $subs->shouldReceive('subscribe')->once()->with($d);

        $d->subscribe($subs);
    }

    public function testEventSubscribeCanReturnMappings()
    {
        $d = $this->getEventDispatcher();
        $this->container->shouldReceive('get')->times(4)->with(DeclarativeSubscriber::class)->andReturn(new DeclarativeSubscriber());

        $d->subscribe(DeclarativeSubscriber::class);

        $d->dispatch('myEvent1');
        $this->assertSame('L1_L2_', DeclarativeSubscriber::$string);

        $d->dispatch('myEvent2');
        $this->assertSame('L1_L2_L3', DeclarativeSubscriber::$string);
    }

    private function getEventDispatcher(): EventDispatcher
    {
        return new EventDispatcher(new ListenerProvider(), null, $this->container);
    }
}

class ExampleSubscriber
{
    public function subscribe($e)
    {
        // There would be no error if a non-array is returned.
        return '(O_o)';
    }
}

class DeclarativeSubscriber
{
    public static $string = '';

    public function subscribe()
    {
        return [
            'myEvent1' => [
                self::class . '@listener1',
                self::class . '@listener2',
            ],
            'myEvent2' => [
                self::class . '@listener3',
            ],
        ];
    }

    public function listener1()
    {
        self::$string .= 'L1_';
    }

    public function listener2()
    {
        self::$string .= 'L2_';
    }

    public function listener3()
    {
        self::$string .= 'L3';
    }
}
