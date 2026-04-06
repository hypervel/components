<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Events;

use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Console\EventListCommand;
use Hypervel\Support\Facades\Artisan;

/**
 * @internal
 * @coversNothing
 */
class EventListCommandTest extends \Hypervel\Testbench\TestCase
{
    public $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = new Dispatcher;
        EventListCommand::resolveEventsUsing(fn () => $this->dispatcher);
    }

    public function testDisplayEmptyList()
    {
        $this->artisan(EventListCommand::class)
            ->assertSuccessful()
            ->expectsOutputToContain("Your application doesn't have any events matching the given criteria.");
    }

    public function testDisplayEvents()
    {
        $this->dispatcher->subscribe(ExampleSubscriber::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleQueueListener::class);
        $this->dispatcher->listen(ExampleBroadcastEvent::class, ExampleBroadcastListener::class);
        $this->dispatcher->listen(ExampleEvent::class, fn () => '');
        $closureLineNumber = __LINE__ - 1;
        $unixFilePath = str_replace('\\', '/', __FILE__);

        $this->artisan(EventListCommand::class)
            ->assertSuccessful()
            ->expectsOutputToContain('ExampleSubscriberEventName')
            ->expectsOutputToContain('⇂ Hypervel\Tests\Integration\Console\Events\ExampleSubscriber@a')
            ->expectsOutputToContain('Hypervel\Tests\Integration\Console\Events\ExampleBroadcastEvent (ShouldBroadcast)')
            ->expectsOutputToContain('⇂ Hypervel\Tests\Integration\Console\Events\ExampleBroadcastListener')
            ->expectsOutputToContain('Hypervel\Tests\Integration\Console\Events\ExampleEvent')
            ->expectsOutputToContain('⇂ Closure at: ' . $unixFilePath . ':' . $closureLineNumber);
    }

    public function testDisplayFilteredEvent()
    {
        $this->dispatcher->subscribe(ExampleSubscriber::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);

        $this->artisan(EventListCommand::class, ['--event' => 'ExampleEvent'])
            ->assertSuccessful()
            ->doesntExpectOutput('  ExampleSubscriberEventName')
            ->expectsOutputToContain('ExampleEvent');
    }

    public function testDisplayFilteredByListener()
    {
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleQueueListener::class);
        $this->dispatcher->listen(ExampleBroadcastEvent::class, ExampleBroadcastListener::class);

        $this->artisan(EventListCommand::class, ['--listener' => 'ExampleQueueListener'])
            ->assertSuccessful()
            ->expectsOutputToContain('ExampleEvent')
            ->expectsOutputToContain('ExampleQueueListener');
    }

    public function testDisplayFilteredByListenerExcludesNonMatching()
    {
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);
        $this->dispatcher->listen(ExampleBroadcastEvent::class, ExampleBroadcastListener::class);

        $this->artisan(EventListCommand::class, ['--listener' => 'BroadcastListener'])
            ->assertSuccessful()
            ->expectsOutputToContain('ExampleBroadcastEvent')
            ->expectsOutputToContain('ExampleBroadcastListener');
    }

    public function testDisplayFilteredByListenerAsJson()
    {
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleQueueListener::class);
        $this->dispatcher->listen(ExampleBroadcastEvent::class, ExampleBroadcastListener::class);

        $this->withoutMockingConsoleOutput()->artisan(EventListCommand::class, [
            '--listener' => 'ExampleListener',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertJson($output);
        $this->assertStringContainsString('ExampleListener', $output);
        $this->assertStringNotContainsString('ExampleQueueListener', $output);
        $this->assertStringNotContainsString('ExampleBroadcastListener', $output);
    }

    public function testDisplayEmptyListAsJson()
    {
        $this->withoutMockingConsoleOutput()->artisan(EventListCommand::class, ['--json' => true]);
        $output = Artisan::output();

        $this->assertJson($output);
        $this->assertJsonStringEqualsJsonString('[]', $output);
    }

    public function testDisplayEventsAsJson()
    {
        $this->dispatcher->subscribe(ExampleSubscriber::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleQueueListener::class);
        $this->dispatcher->listen(ExampleBroadcastEvent::class, ExampleBroadcastListener::class);
        $this->dispatcher->listen(ExampleEvent::class, fn () => '');
        $closureLineNumber = __LINE__ - 1;
        $unixFilePath = str_replace('\\', '/', __FILE__);

        $this->withoutMockingConsoleOutput()->artisan(EventListCommand::class, ['--json' => true]);
        $output = Artisan::output();

        $this->assertJson($output);
        $this->assertStringContainsString('ExampleSubscriberEventName', $output);
        $this->assertStringContainsString(json_encode('Hypervel\Tests\Integration\Console\Events\ExampleSubscriber@a'), $output);
        $this->assertStringContainsString(json_encode('Hypervel\Tests\Integration\Console\Events\ExampleBroadcastEvent (ShouldBroadcast)'), $output);
        $this->assertStringContainsString(json_encode('Hypervel\Tests\Integration\Console\Events\ExampleBroadcastListener'), $output);
        $this->assertStringContainsString(json_encode('Hypervel\Tests\Integration\Console\Events\ExampleEvent'), $output);
        $this->assertStringContainsString(json_encode('Closure at: ' . $unixFilePath . ':' . $closureLineNumber), $output);
    }

    public function testDisplayFilteredEventAsJson()
    {
        $this->dispatcher->subscribe(ExampleSubscriber::class);
        $this->dispatcher->listen(ExampleEvent::class, ExampleListener::class);

        $this->withoutMockingConsoleOutput()->artisan(EventListCommand::class, [
            '--event' => 'ExampleEvent',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertJson($output);
        $this->assertStringContainsString('ExampleEvent', $output);
        $this->assertStringContainsString('ExampleListener', $output);
        $this->assertStringNotContainsString('ExampleSubscriberEventName', $output);
    }

    protected function tearDown(): void
    {
        EventListCommand::resolveEventsUsing(null);

        parent::tearDown();
    }
}

class ExampleSubscriber
{
    public function subscribe()
    {
        return [
            'ExampleSubscriberEventName' => [
                self::class . '@a',
                self::class . '@b',
            ],
        ];
    }

    public function a()
    {
    }

    public function b()
    {
    }
}

class ExampleEvent
{
}

class ExampleBroadcastEvent implements ShouldBroadcast
{
    public function broadcastOn(): \Hypervel\Broadcasting\Channel|array
    {
        return [];
    }
}

class ExampleListener
{
    public function handle()
    {
    }
}

class ExampleQueueListener implements ShouldQueue
{
    public function handle()
    {
    }
}

class ExampleBroadcastListener
{
    public function handle()
    {
    }
}
