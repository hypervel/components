<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Facades;

use Hypervel\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class EventFacadeTest extends TestCase
{
    public function testFakeReturnsEventFake()
    {
        $fake = Event::fake();

        $this->assertInstanceOf(EventFake::class, $fake);
    }

    public function testDoubleFakeDoesNotTypeError()
    {
        $fake1 = Event::fake();
        $fake2 = Event::fake();

        $this->assertInstanceOf(EventFake::class, $fake2);
        $this->assertInstanceOf(Dispatcher::class, $fake2->dispatcher);
    }

    public function testDoubleFakeUnwrapsToOriginalDispatcher()
    {
        $originalDispatcher = Event::getFacadeRoot();

        Event::fake();
        $secondFake = Event::fake();

        $this->assertSame($originalDispatcher, $secondFake->dispatcher);
    }

    public function testFakeExceptDoubleFakeDoesNotTypeError()
    {
        Event::fake();
        $fake = Event::fakeExcept('some.event');

        $this->assertInstanceOf(EventFake::class, $fake);
        $this->assertInstanceOf(Dispatcher::class, $fake->dispatcher);
    }
}
