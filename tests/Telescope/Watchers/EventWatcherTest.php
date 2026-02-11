<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\EventWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Hypervel\Contracts\Event\Dispatcher;
use ReflectionMethod;
use Telescope\Dummies\DummyEvent;
use Telescope\Dummies\DummyEventListener;
use Telescope\Dummies\DummyEventWithObject;
use Telescope\Dummies\DummyObject;
use Telescope\Dummies\IgnoredEvent;

/**
 * @internal
 * @coversNothing
 */
class EventWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get('config')
            ->set('telescope.watchers', [
                EventWatcher::class => [
                    'enabled' => true,
                    'ignore' => [
                        IgnoredEvent::class,
                    ],
                    'ignore_framework' => false,
                ],
            ]);

        $this->startTelescope();
    }

    public function testEventWatcherRegistersAnyEvents()
    {
        $this->app->get(Dispatcher::class)
            ->dispatch(new DummyEvent());

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEvent::class, $entry->content['name']);
    }

    public function testEventWatcherStoresPayloads()
    {
        $this->app->get(Dispatcher::class)
            ->dispatch(new DummyEvent('Telescope', 'Laravel', 'PHP'));

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
        $this->app->get(Dispatcher::class)
            ->dispatch(new DummyEventWithObject());

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::EVENT, $entry->type);
        $this->assertSame(DummyEventWithObject::class, $entry->content['name']);
        $this->assertArrayHasKey('thing', $entry->content['payload']);
        $this->assertSame(DummyObject::class, $entry->content['payload']['thing']['class']);
        $this->assertContains('Telescope', $entry->content['payload']['thing']['properties']);
        $this->assertContains('Laravel', $entry->content['payload']['thing']['properties']);
        $this->assertContains('PHP', $entry->content['payload']['thing']['properties']);
    }

    public function testEventWatcherIgnoreEvent()
    {
        $this->app->get(Dispatcher::class)
            ->dispatch(new IgnoredEvent());

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry);
    }

    /**
     * @dataProvider formatListenersProvider
     * @param mixed $listener
     * @param mixed $formatted
     */
    public function testFormatListeners($listener, $formatted)
    {
        $this->app->get(Dispatcher::class)
            ->listen(DummyEvent::class, $listener);

        $method = new ReflectionMethod(EventWatcher::class, 'formatListeners');
        $method->setAccessible(true);

        $this->assertSame($formatted, $method->invoke(new EventWatcher(), DummyEvent::class)[0]['name']);
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
                [new DummyEventListener(), 'handle'],
                DummyEventListener::class . '@handle',
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
        $this->thing = new DummyObject();
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
