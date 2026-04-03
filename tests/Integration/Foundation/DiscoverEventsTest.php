<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation;

use Hypervel\Foundation\Events\DiscoverEvents;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventOne;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Events\EventTwo;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\AbstractListener;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\Listener;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\ListenerInterface;
use Hypervel\Tests\Integration\Foundation\Fixtures\EventDiscovery\UnionListeners\UnionListener;
use SplFileInfo;

/**
 * @internal
 * @coversNothing
 */
class DiscoverEventsTest extends TestCase
{
    public function testEventsCanBeDiscovered()
    {
        if (! class_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\Listener', false)) {
            class_alias(Listener::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\Listener');
        }

        if (! class_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\AbstractListener', false)) {
            class_alias(AbstractListener::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\AbstractListener');
        }

        if (! interface_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\ListenerInterface', false)) {
            class_alias(ListenerInterface::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\Listeners\ListenerInterface');
        }

        $events = DiscoverEvents::within(__DIR__ . '/Fixtures/EventDiscovery/Listeners', getcwd());

        $this->assertEquals([
            EventOne::class => [
                Listener::class . '@handle',
                Listener::class . '@handleEventOne',
            ],
            EventTwo::class => [
                Listener::class . '@handleEventTwo',
            ],
        ], $events);
    }

    public function testUnionEventsCanBeDiscovered()
    {
        if (! class_exists('Tests\Integration\Foundation\Fixtures\EventDiscovery\UnionListeners\UnionListener', false)) {
            class_alias(UnionListener::class, 'Tests\Integration\Foundation\Fixtures\EventDiscovery\UnionListeners\UnionListener');
        }

        $events = DiscoverEvents::within(__DIR__ . '/Fixtures/EventDiscovery/UnionListeners', getcwd());

        $this->assertEquals([
            EventOne::class => [
                UnionListener::class . '@handle',
            ],
            EventTwo::class => [
                UnionListener::class . '@handle',
            ],
        ], $events);
    }

    public function testMultipleDirectoriesCanBeDiscovered()
    {
        $events = DiscoverEvents::within([
            __DIR__ . '/Fixtures/EventDiscovery/Listeners',
            __DIR__ . '/Fixtures/EventDiscovery/UnionListeners',
        ], getcwd());

        $this->assertEquals([
            EventOne::class => [
                Listener::class . '@handle',
                Listener::class . '@handleEventOne',
                UnionListener::class . '@handle',
            ],
            EventTwo::class => [
                Listener::class . '@handleEventTwo',
                UnionListener::class . '@handle',
            ],
        ], $events);
    }

    public function testNoExceptionForEmptyDirectories()
    {
        $events = DiscoverEvents::within([], getcwd());

        $this->assertEquals([], $events);
    }

    public function testEventsCanBeDiscoveredUsingCustomClassNameGuessing()
    {
        DiscoverEvents::guessClassNamesUsing(function (SplFileInfo $file, $basePath) {
            return (new Stringable($file->getRealPath()))
                ->after($basePath . DIRECTORY_SEPARATOR)
                ->before('.php')
                ->replace(DIRECTORY_SEPARATOR, '\\')
                ->ucfirst()
                ->prepend('Hypervel\\')
                ->toString();
        });

        $events = DiscoverEvents::within(__DIR__ . '/Fixtures/EventDiscovery/Listeners', getcwd());

        $this->assertEquals([
            EventOne::class => [
                Listener::class . '@handle',
                Listener::class . '@handleEventOne',
            ],
            EventTwo::class => [
                Listener::class . '@handleEventTwo',
            ],
        ], $events);
    }
}
