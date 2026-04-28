<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Support\Facades\Event;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\EventWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Telescope\Dummies\DummyEvent;
use Telescope\Dummies\DummyEventListener;
use Telescope\Dummies\DummyEventSubscriber;
use Telescope\Dummies\DummyEventWithObject;
use Telescope\Dummies\DummyInvokableEventListener;
use Telescope\Dummies\DummyObject;
use Telescope\Dummies\IgnoredEvent;

#[WithConfig('telescope.watchers', [
    EventWatcher::class => [
        'enabled' => true,
        'ignore' => [
            IgnoredEvent::class,
        ],
    ],
])]
class EventWatcherTest extends FeatureTestCase
{
    public function testEventWatcherRegistersAnyEvents()
    {
        Event::listen(DummyEvent::class, function ($payload) {
        });

        event(new DummyEvent);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEvent::class, $entry->content['name']);
    }

    public function testEventWatcherStoresPayloads()
    {
        Event::listen(DummyEvent::class, function ($payload) {
        });

        event(new DummyEvent('Telescope', 'Laravel', 'PHP'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEvent::class, $entry->content['name']);
        $this->assertArrayHasKey('data', $entry->content['payload']);
        $this->assertContains('Telescope', $entry->content['payload']['data']);
        $this->assertContains('Laravel', $entry->content['payload']['data']);
        $this->assertContains('PHP', $entry->content['payload']['data']);
    }

    public function testEventWatcherWithObjectPropertyCallsFormatForTelescopeMethodIfItExists()
    {
        Event::listen(DummyEventWithObject::class, function ($payload) {
        });

        event(new DummyEventWithObject);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEventWithObject::class, $entry->content['name']);
        $this->assertArrayHasKey('thing', $entry->content['payload']);
        $this->assertSame(DummyObject::class, $entry->content['payload']['thing']['class']);
        $this->assertContains('Telescope', $entry->content['payload']['thing']['properties']);
        $this->assertContains('Laravel', $entry->content['payload']['thing']['properties']);
        $this->assertContains('PHP', $entry->content['payload']['thing']['properties']);
    }

    public function testEventWatcherRegistersEventsAndStoresPayloadsWithSubscriberMethods()
    {
        Event::listen(DummyEvent::class, DummyEventSubscriber::class . '@handleDummyEvent');

        event(new DummyEvent('Telescope', 'Laravel', 'PHP'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEvent::class, $entry->content['name']);
        $this->assertArrayHasKey('data', $entry->content['payload']);
        $this->assertContains('Telescope', $entry->content['payload']['data']);
        $this->assertContains('Laravel', $entry->content['payload']['data']);
        $this->assertContains('PHP', $entry->content['payload']['data']);
    }

    public function testEventWatcherRegistersEventsAndStoresPayloadsWithSubscriberClasses()
    {
        Event::listen(DummyEvent::class, [DummyEventSubscriber::class, 'handleDummyEvent']);

        event(new DummyEvent('Telescope', 'Laravel', 'PHP'));

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEvent::class, $entry->content['name']);
        $this->assertArrayHasKey('data', $entry->content['payload']);
        $this->assertContains('Telescope', $entry->content['payload']['data']);
        $this->assertContains('Laravel', $entry->content['payload']['data']);
        $this->assertContains('PHP', $entry->content['payload']['data']);
    }

    public function testEventWatcherIgnoreEvent()
    {
        event(new IgnoredEvent);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    #[DataProvider('formatListenersProvider')]
    public function testFormatListeners($listener, $formatted)
    {
        Event::listen(DummyEvent::class, $listener);

        $method = new ReflectionMethod(EventWatcher::class, 'formatListeners');

        $this->assertSame($formatted, $method->invoke(new EventWatcher, DummyEvent::class)[0]['name']);
    }

    public static function formatListenersProvider()
    {
        return [
            'class string' => [
                DummyEventListener::class,
                DummyEventListener::class . '@handle',
            ],
            'class string with method' => [
                DummyEventListener::class . '@handle',
                DummyEventListener::class . '@handle',
            ],
            'array class string and method' => [
                [DummyEventListener::class, 'handle'],
                DummyEventListener::class . '@handle',
            ],
            'array object and method' => [
                [new DummyEventListener, 'handle'],
                DummyEventListener::class . '@handle',
            ],
            'callable object' => [
                new DummyInvokableEventListener,
                DummyInvokableEventListener::class . '@__invoke',
            ],
            'anonymous callable object' => [
                $class = new class {
                    public function __invoke()
                    {
                    }
                },
                get_class($class) . '@__invoke',
            ],
            'closure' => [
                function () {
                },
                sprintf('Closure at %s[%s:%s]', __FILE__, __LINE__ - 2, __LINE__ - 1),
            ],
        ];
    }
}

namespace Telescope\Dummies;

class DummyEvent
{
    public $data;

    public function __construct(...$payload)
    {
        $this->data = $payload;
    }

    public function handle()
    {
    }
}

class DummyEventWithObject
{
    public $thing;

    public function __construct()
    {
        $this->thing = new DummyObject;
    }
}

class DummyObject
{
    public function formatForTelescope(): array
    {
        return [
            'Telescope',
            'Laravel',
            'PHP',
        ];
    }
}

class DummyEventSubscriber
{
    public function handleDummyEvent($event)
    {
    }
}

class IgnoredEvent
{
    public function handle()
    {
    }
}

class DummyEventListener
{
    public function handle($event)
    {
    }
}

class DummyInvokableEventListener
{
    public function __invoke($event)
    {
    }
}
