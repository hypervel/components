<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\Contracts\Broadcaster;
use Hypervel\Broadcasting\Contracts\Factory as BroadcastingFactory;
use Hypervel\Broadcasting\InteractsWithBroadcasting;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BroadcastEventTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testBasicEventBroadcastParameterFormatting()
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->shouldReceive('broadcast')->once()->with(
            ['test-channel'],
            TestBroadcastEvent::class,
            ['firstName' => 'Taylor', 'lastName' => 'Otwell', 'collection' => ['foo' => 'bar']]
        );

        $manager = m::mock(BroadcastingFactory::class);

        $manager->shouldReceive('connection')->once()->with(null)->andReturn($broadcaster);

        $event = new TestBroadcastEvent();

        (new BroadcastEvent($event))->handle($manager);
    }

    public function testManualParameterSpecification()
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->shouldReceive('broadcast')->once()->with(
            ['test-channel'],
            TestBroadcastEventWithManualData::class,
            ['name' => 'Taylor', 'socket' => null]
        );

        $manager = m::mock(BroadcastingFactory::class);

        $manager->shouldReceive('connection')->once()->with(null)->andReturn($broadcaster);

        $event = new TestBroadcastEventWithManualData();

        (new BroadcastEvent($event))->handle($manager);
    }

    public function testSpecificBroadcasterGiven()
    {
        $broadcaster = m::mock(Broadcaster::class);

        $broadcaster->shouldReceive('broadcast')->once();

        $manager = m::mock(BroadcastingFactory::class);

        $manager->shouldReceive('connection')->once()->with('log')->andReturn($broadcaster);

        $event = new TestBroadcastEventWithSpecificBroadcaster();

        (new BroadcastEvent($event))->handle($manager);
    }
}

class TestBroadcastEvent
{
    public $firstName = 'Taylor';

    public $lastName = 'Otwell';

    public $collection;

    private $title = 'Developer';

    public function __construct()
    {
        $this->collection = collect(['foo' => 'bar']);
    }

    public function broadcastOn()
    {
        return ['test-channel'];
    }
}

class TestBroadcastEventWithManualData extends TestBroadcastEvent
{
    public function broadcastWith()
    {
        return ['name' => 'Taylor'];
    }
}

class TestBroadcastEventWithSpecificBroadcaster extends TestBroadcastEvent
{
    use InteractsWithBroadcasting;

    public function __construct()
    {
        $this->broadcastVia('log');
    }
}
