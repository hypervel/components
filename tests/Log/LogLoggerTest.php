<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Events\Dispatcher;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Log\Logger;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class LogLoggerTest extends TestCase
{
    public function testMethodsPassErrorAdditionsToMonolog()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->shouldReceive('error')->once()->with('foo', []);

        $writer->error('foo');
    }

    public function testContextIsAddedToAllSubsequentLogs()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $writer->withContext(['bar' => 'baz']);

        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->shouldReceive('error')->once()->with('foo', ['bar' => 'baz']);

        $writer->error('foo');
    }

    public function testContextIsFlushed()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $writer->withContext(['bar' => 'baz']);
        $writer->withoutContext();

        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', []);

        $writer->error('foo');
    }

    public function testContextKeysCanBeRemovedForSubsequentLogs()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $writer->withContext(['bar' => 'baz', 'forget' => 'me']);
        $writer->withoutContext(['forget']);

        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->shouldReceive('error')->once()->with('foo', ['bar' => 'baz']);

        $writer->error('foo');
    }

    public function testLoggerFiresEventsDispatcher()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class), $events = new Dispatcher);
        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->shouldReceive('error')->once()->with('foo', []);

        $context = [];

        $events->listen(MessageLogged::class, function ($event) use (&$context) {
            $context['level'] = $event->level;
            $context['message'] = $event->message;
            $context['event_context'] = $event->context;
        });

        $writer->error('foo');
        $this->assertTrue(isset($context['level']));
        $this->assertSame('error', $context['level']);
        $this->assertTrue(isset($context['message']));
        $this->assertSame('foo', $context['message']);
        $this->assertTrue(isset($context['event_context']));
        $this->assertEquals([], $context['event_context']);
    }

    public function testListenShortcutFailsWithNoDispatcher()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Events dispatcher has not been set.');

        $writer = new Logger(m::mock(Monolog::class));
        $writer->listen(function () {
        });
    }

    public function testListenShortcut()
    {
        $writer = new Logger(m::mock(Monolog::class), $events = m::mock(DispatcherContract::class));

        $callback = function () {
            return 'success';
        };
        $events->shouldReceive('listen')->with(MessageLogged::class, $callback)->once();

        $writer->listen($callback);
    }

    public function testComplexContextManipulation()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));

        $writer->withContext(['user_id' => 123, 'action' => 'login']);
        $writer->withContext(['ip' => '127.0.0.1', 'timestamp' => '1986-10-29']);
        $writer->withoutContext(['timestamp']);

        $monolog->shouldReceive('isHandling')->with('info')->andReturn(true);
        $monolog->shouldReceive('info')->once()->with('User action', [
            'user_id' => 123,
            'action' => 'login',
            'ip' => '127.0.0.1',
        ]);

        $writer->info('User action');
    }

    public function testSkipsSerializationWhenLogLevelNotHandled()
    {
        $monolog = new Monolog('test');
        $monolog->pushHandler(new TestHandler(Level::Error));

        $writer = new Logger($monolog);

        $arrayable = new class implements Arrayable {
            public bool $wasCalled = false;

            public function toArray(): array
            {
                $this->wasCalled = true;

                return ['serialized' => 'data'];
            }
        };

        $writer->debug($arrayable);

        $this->assertFalse($arrayable->wasCalled);
    }

    public function testSerializesWhenLogLevelIsHandled()
    {
        $monolog = new Monolog('test');
        $handler = new TestHandler(Level::Debug);
        $monolog->pushHandler($handler);

        $writer = new Logger($monolog);

        $arrayable = new class implements Arrayable {
            public bool $wasCalled = false;

            public function toArray(): array
            {
                $this->wasCalled = true;

                return ['serialized' => 'data'];
            }
        };

        $writer->debug($arrayable);

        $this->assertTrue($arrayable->wasCalled);
        $this->assertTrue($handler->hasDebugRecords());
    }

    // -- Hypervel-specific tests --

    public function testWithContext()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));

        $writer->withContext(['foo' => 'bar']);
        $writer->withContext(['baz' => 'qux']);

        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->shouldReceive('error')->once()->with('test message', ['foo' => 'bar', 'baz' => 'qux']);

        $writer->error('test message');
    }

    public function testLoggerSkipsEventDispatchWhenNoListenersAreRegistered()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class), $events = m::mock(DispatcherContract::class));
        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->shouldReceive('error')->once()->with('foo', []);
        $events->shouldReceive('hasListeners')->once()->with(MessageLogged::class)->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $writer->error('foo');
    }
}
