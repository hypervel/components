<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Exception;
use Hyperf\Database\Model\Register;
use Hypervel\Support\Facades\Event;
use Mockery;
use Psr\EventDispatcher\EventDispatcherInterface;

trait MocksApplicationServices
{
    /**
     * All of the fired events.
     */
    protected array $firedEvents = [];

    /**
     * Specify a list of events that should be fired for the given operation.
     *
     * These events will be mocked, so that handlers will not actually be executed.
     *
     * @param array|string $events
     * @return $this
     *
     * @throws Exception
     */
    public function expectsEvents($events)
    {
        $events = is_array($events) ? $events : func_get_args();

        $this->withoutEvents();

        $this->beforeApplicationDestroyed(function () use ($events) {
            $fired = $this->getFiredEvents($events);

            $this->assertEmpty(
                $eventsNotFired = array_diff($events, $fired),
                'These expected events were not fired: [' . implode(', ', $eventsNotFired) . ']'
            );
        });

        return $this;
    }

    /**
     * Specify a list of events that should not be fired for the given operation.
     *
     * These events will be mocked, so that handlers will not actually be executed.
     *
     * @param array|string $events
     * @return $this
     */
    public function doesntExpectEvents($events)
    {
        $events = is_array($events) ? $events : func_get_args();

        $this->withoutEvents();

        $this->beforeApplicationDestroyed(function () use ($events) {
            $this->assertEmpty(
                $fired = $this->getFiredEvents($events),
                'These unexpected events were fired: [' . implode(', ', $fired) . ']'
            );
        });

        return $this;
    }

    /**
     * Mock the event dispatcher so all events are silenced and collected.
     *
     * @return $this
     */
    protected function withoutEvents()
    {
        $mock = Mockery::mock(EventDispatcherInterface::class)->shouldIgnoreMissing();

        $mock->shouldReceive('dispatch')->andReturnUsing(function ($called) {
            $this->firedEvents[] = $called;
        });

        Event::clearResolvedInstances();

        $this->app->set(EventDispatcherInterface::class, $mock);
        Register::setEventDispatcher($mock);

        return $this;
    }

    /**
     * Filter the given events against the fired events.
     *
     * @return array
     */
    protected function getFiredEvents(array $events)
    {
        return $this->getDispatched($events, $this->firedEvents);
    }

    /**
     * Filter the given classes against an array of dispatched classes.
     *
     * @return array
     */
    protected function getDispatched(array $classes, array $dispatched)
    {
        return array_filter($classes, function ($class) use ($dispatched) {
            return $this->wasDispatched($class, $dispatched);
        });
    }

    /**
     * Check if the given class exists in an array of dispatched classes.
     *
     * @param string $needle
     * @return bool
     */
    protected function wasDispatched($needle, array $haystack)
    {
        foreach ($haystack as $dispatched) {
            if ((is_string($dispatched) && ($dispatched === $needle || is_subclass_of($dispatched, $needle)))
                || $dispatched instanceof $needle) {
                return true;
            }
        }

        return false;
    }
}
