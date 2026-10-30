<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\ExpectationFailedException;

class SupportTestingEventFakeTest extends TestCase
{
    protected EventFake $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new EventFake(m::mock(Dispatcher::class));
    }

    public function testAssertDispatched(): void
    {
        try {
            $this->fake->assertDispatched(EventStub::class);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\EventStub] event was not dispatched.', $exception->getMessage());
        }

        $this->fake->dispatch(EventStub::class);

        $this->fake->assertDispatched(EventStub::class);
    }

    public function testAssertDispatchedWithClosure(): void
    {
        $this->fake->dispatch(new EventStub);

        $this->fake->assertDispatched(function (EventStub $event) {
            return true;
        });
    }

    public function testAssertListening(): void
    {
        $dispatcher = new EventDispatcher;
        $dispatcher->listen(EventStub::class, ListenerStub::class);

        $fake = new EventFake($dispatcher);

        $fake->assertListening(EventStub::class, ListenerStub::class);
    }

    public function testAssertDispatchedWithCallbackInt(): void
    {
        $this->fake->dispatch(EventStub::class);
        $this->fake->dispatch(EventStub::class);

        try {
            $this->fake->assertDispatched(EventStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\EventStub] event was dispatched 2 times instead of 1 time.', $exception->getMessage());
        }

        $this->fake->assertDispatched(EventStub::class, 2);
    }

    public function testAssertDispatchedOnce(): void
    {
        $this->fake->dispatch(EventStub::class);
        $this->fake->dispatch(EventStub::class);

        try {
            $this->fake->assertDispatchedOnce(EventStub::class);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\EventStub] event was dispatched 2 times instead of 1 time.', $exception->getMessage());
        }

        $this->fake->assertDispatchedTimes(EventStub::class, 2);
    }

    public function testAssertDispatchedTimes(): void
    {
        $this->fake->dispatch(EventStub::class);
        $this->fake->dispatch(EventStub::class);

        try {
            $this->fake->assertDispatchedTimes(EventStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\EventStub] event was dispatched 2 times instead of 1 time.', $exception->getMessage());
        }

        $this->fake->assertDispatchedTimes(EventStub::class, 2);
    }

    public function testAssertDispatchedWithArrayOfProperties(): void
    {
        $this->fake->dispatch(new EventWithPropertiesStub('pending'));

        $this->fake->assertDispatched(EventWithPropertiesStub::class, ['status' => 'pending']);
        $this->fake->assertNotDispatched(EventWithPropertiesStub::class, ['status' => 'complete']);
    }

    public function testPropertyMapsDoNotMatchStringEvents(): void
    {
        $this->fake->dispatch(EventWithPropertiesStub::class);

        $this->fake->assertNotDispatched(EventWithPropertiesStub::class, ['status' => null]);
    }

    public function testAssertNotDispatched(): void
    {
        $this->fake->assertNotDispatched(EventStub::class);

        $this->fake->dispatch(EventStub::class);

        try {
            $this->fake->assertNotDispatched(EventStub::class);
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\EventStub] event was dispatched.', $exception->getMessage());
        }
    }

    public function testAssertNotDispatchedWithClosure(): void
    {
        $this->fake->dispatch(new EventStub);

        try {
            $this->fake->assertNotDispatched(function (EventStub $event) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\EventStub] event was dispatched.', $exception->getMessage());
        }
    }

    public function testAssertDispatchedWithIgnore(): void
    {
        $dispatcher = new EventDispatcher;
        $passedThrough = [];
        $dispatcher->listen('*', function (string $event, array $payload) use (&$passedThrough): void {
            $passedThrough[] = $event;
        });

        $fake = new EventFake($dispatcher, [
            'Foo',
            function ($event, $payload) {
                return $event === 'Bar' && $payload['id'] === 1;
            },
        ]);

        $fake->dispatch('Foo');
        $fake->dispatch('Bar', ['id' => 1]);
        $fake->dispatch('Baz');

        $fake->assertDispatched('Foo');
        $fake->assertDispatched('Bar');
        $fake->assertNotDispatched('Baz');
        $this->assertSame(['Baz'], $passedThrough);
    }

    public function testAssertNothingDispatched(): void
    {
        $this->fake->assertNothingDispatched();

        $this->fake->dispatch(EventStub::class);
        $this->fake->dispatch(EventStub::class);

        try {
            $this->fake->assertNothingDispatched();
            $this->fail();
        } catch (ExpectationFailedException $exception) {
            $this->assertStringContainsString("2 unexpected events were dispatched:\n\n- Hypervel\\Tests\\Support\\EventStub dispatched 2 times", $exception->getMessage());
        }
    }
}

class EventStub
{
}

class ListenerStub
{
}

class EventWithPropertiesStub
{
    /**
     * Create an event with the given status.
     */
    public function __construct(public string $status)
    {
    }
}
