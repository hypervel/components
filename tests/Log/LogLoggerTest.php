<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Hyperf\Context\Context;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Log\Logger;
use Mockery as m;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class LogLoggerTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroy('__logger.context');
    }

    public function testMethodsPassErrorAdditionsToMonolog()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $monolog->shouldReceive('error')->once()->with('foo', []);

        $writer->error('foo');
    }

    public function testContextIsAddedToAllSubsequentLogs()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $writer->withContext(['bar' => 'baz']);

        $monolog->shouldReceive('error')->once()->with('foo', ['bar' => 'baz']);

        $writer->error('foo');
    }

    public function testContextIsFlushed()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));
        $writer->withContext(['bar' => 'baz']);
        $writer->withoutContext();

        $monolog->expects('error')->with('foo', []);

        $writer->error('foo');
    }

    public function testLoggerFiresEventsDispatcher()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class), $events = new DispatcherStub());
        $monolog->shouldReceive('error')->once()->with('foo', []);

        $context = [];

        $events->listen(MessageLogged::class, function ($event) use (&$context) {
            $context['__log.level'] = $event->level;
            $context['__log.message'] = $event->message;
            $context['__log.context'] = $event->context;
        });

        $writer->error('foo');
        $this->assertTrue(isset($context['__log.level']));
        $this->assertSame('error', $context['__log.level']);
        $this->assertTrue(isset($context['__log.message']));
        $this->assertSame('foo', $context['__log.message']);
        $this->assertTrue(isset($context['__log.context']));
        $this->assertEquals([], $context['__log.context']);
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
        $writer = new Logger(m::mock(Monolog::class), $events = m::mock(DispatcherStub::class));

        $callback = function () {
            return 'success';
        };
        $events->shouldReceive('listen')->with(MessageLogged::class, $callback)->once();

        $writer->listen($callback);
    }

    public function testWithContext()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));

        $writer->withContext(['foo' => 'bar']);
        $writer->withContext(['baz' => 'qux']);

        $monolog->shouldReceive('error')->once()->with('test message', ['foo' => 'bar', 'baz' => 'qux']);

        $writer->error('test message');
    }

    public function testWithoutContext()
    {
        $writer = new Logger($monolog = m::mock(Monolog::class));

        $writer->withContext(['foo' => 'bar']);
        $writer->withoutContext();

        $monolog->shouldReceive('error')->once()->with('test message', []);

        $writer->error('test message');
    }
}
