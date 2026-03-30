<?php

declare(strict_types=1);

namespace Hypervel\Tests\Context;

use DateTimeImmutable;
use Hypervel\Context\CoroutineContext;
use Hypervel\Log\ContextLogProcessor;
use Hypervel\Testbench\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * @internal
 * @coversNothing
 */
class PropagatedContextLogTest extends TestCase
{
    public function testPropagatedContextIsAddedToLogRecords()
    {
        CoroutineContext::propagated()->add('trace_id', 'abc-123');
        CoroutineContext::propagated()->add('user_id', 42);

        $handler = new TestHandler();
        $logger = new Monolog('test', [$handler], [new ContextLogProcessor()]);

        $logger->info('test message');

        $record = $handler->getRecords()[0];
        $this->assertSame('abc-123', $record->extra['trace_id']);
        $this->assertSame(42, $record->extra['user_id']);
    }

    public function testHiddenContextIsNotAddedToLogRecords()
    {
        CoroutineContext::propagated()->addHidden('secret', 'sensitive-data');

        $handler = new TestHandler();
        $logger = new Monolog('test', [$handler], [new ContextLogProcessor()]);

        $logger->info('test message');

        $record = $handler->getRecords()[0];
        $this->assertArrayNotHasKey('secret', $record->extra);
    }

    public function testPropagatedContextDoesNotOverrideLogMessageContext()
    {
        CoroutineContext::propagated()->add('request_id', 'propagated-value');

        $handler = new TestHandler();
        $logger = new Monolog('test', [$handler], [new ContextLogProcessor()]);

        $logger->info('test message', ['request_id' => 'message-value']);

        $record = $handler->getRecords()[0];
        // Message context and propagated context live in separate places
        $this->assertSame('message-value', $record->context['request_id']);
        $this->assertSame('propagated-value', $record->extra['request_id']);
    }

    public function testLogProcessorSkipsWhenNoPropagatedContextExists()
    {
        $processor = new ContextLogProcessor();

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'test',
        );

        $result = $processor($record);

        // Should return the same record unchanged — no PropagatedContext allocated
        $this->assertSame($record, $result);
        $this->assertFalse(CoroutineContext::hasPropagated());
    }

    public function testLogProcessorSkipsWhenPropagatedContextIsEmpty()
    {
        // Access propagated context but don't add anything
        CoroutineContext::propagated();
        $this->assertTrue(CoroutineContext::hasPropagated());

        $processor = new ContextLogProcessor();

        $record = new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'test',
        );

        $result = $processor($record);

        // Should return the same record unchanged — propagated context exists but is empty
        $this->assertSame($record, $result);
    }

    public function testCustomLogProcessorCanBeBound()
    {
        $custom = new class implements ProcessorInterface {
            public bool $called = false;

            public function __invoke(LogRecord $record): LogRecord
            {
                $this->called = true;

                return $record->with(extra: [...$record->extra, 'custom' => true]);
            }
        };

        $handler = new TestHandler();
        $logger = new Monolog('test', [$handler], [$custom]);

        $logger->info('test message');

        $this->assertTrue($custom->called);
        $record = $handler->getRecords()[0];
        $this->assertTrue($record->extra['custom']);
    }
}
