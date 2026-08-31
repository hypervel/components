<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use DateTimeImmutable;
use Hypervel\Log\Handlers\FingersCrossedHandler;
use Hypervel\Sentry\LogChannel;
use Hypervel\Sentry\Logs\LogChannel as LogsLogChannel;
use Hypervel\Sentry\Logs\LogsHandler;
use Hypervel\Sentry\SentryHandler;
use Mockery as m;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Sentry\Event;
use Sentry\State\HubInterface;

class LogChannelTest extends SentryTestCase
{
    public function testCreatingHandlerWithoutActionLevelConfig(): void
    {
        $logChannel = new LogChannel($this->app);

        $logger = $logChannel();

        $this->assertContainsOnlyInstancesOf(SentryHandler::class, $logger->getHandlers());
    }

    public function testCreatingHandlerWithActionLevelConfig(): void
    {
        $logChannel = new LogChannel($this->app);

        $logger = $logChannel([
            'action_level' => 'critical',
            'stop_buffering' => false,
        ]);

        $this->assertContainsOnlyInstancesOf(FingersCrossedHandler::class, $logger->getHandlers());

        $currentHandler = current($logger->getHandlers());

        $this->assertSame(FingersCrossedHandler::class, get_class($currentHandler));
        $this->assertFalse((new ReflectionProperty($currentHandler, 'stopBuffering'))->getValue($currentHandler));

        if (method_exists($currentHandler, 'getHandler')) {
            $this->assertInstanceOf(SentryHandler::class, $currentHandler->getHandler());
        }

        $loggerWithoutActionLevel = $logChannel(['action_level' => null]);

        $this->assertContainsOnlyInstancesOf(SentryHandler::class, $loggerWithoutActionLevel->getHandlers());
    }

    public function testCreatingLogsHandlerWithActionLevelConfig(): void
    {
        $logChannel = new LogsLogChannel($this->app);

        $logger = $logChannel([
            'action_level' => 'critical',
            'stop_buffering' => false,
        ]);

        $currentHandler = current($logger->getHandlers());

        $this->assertSame(FingersCrossedHandler::class, get_class($currentHandler));
        $this->assertFalse((new ReflectionProperty($currentHandler, 'stopBuffering'))->getValue($currentHandler));
        $this->assertInstanceOf(LogsHandler::class, $currentHandler->getHandler());
    }

    public function testLogsHandlerCreatesItsDefaultBatchFormatter(): void
    {
        $handler = new LogsHandler(Level::Debug);

        $this->assertInstanceOf(LineFormatter::class, $handler->getBatchFormatter());
    }

    #[DataProvider('nativeBatchHandlerDataProvider')]
    public function testNativeBatchHandlingFiltersAndEnrichesImmutableRecords(RecordingLogsHandler|RecordingSentryHandler $handler): void
    {
        $debug = $this->record(Level::Debug, 'debug');
        $warning = $this->record(Level::Warning, 'warning');
        $error = $this->record(Level::Error, 'error', ['original' => true]);
        $handler->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(extra: ['processed' => true]));

        $handler->handleBatch([$debug, $warning, $error]);

        $this->assertCount(1, $handler->writtenRecords);
        $this->assertSame(Level::Error, $handler->writtenRecords[0]->level);
        $this->assertSame(['processed' => true], $handler->writtenRecords[0]->extra);
        $this->assertArrayHasKey('logs', $handler->writtenRecords[0]->context);
        $this->assertSame(['original' => true], $error->context);
    }

    public static function nativeBatchHandlerDataProvider(): iterable
    {
        yield 'events handler' => [new RecordingSentryHandler(m::mock(HubInterface::class), Level::Warning)];
        yield 'logs handler' => [new RecordingLogsHandler(Level::Warning)];
    }

    public function testExceptionIsRemovedFromEmittedLogContext(): void
    {
        $logger = (new LogChannel($this->app))();

        $logger->error('test message', [
            'exception' => new RuntimeException('failed'),
            'retained' => 'value',
        ]);

        $lastEvent = $this->getLastSentryEvent();

        $this->assertNotNull($lastEvent);
        $this->assertSame(['retained' => 'value'], $lastEvent->getExtra()['log_context']);
    }

    #[DataProvider('handlerDataProvider')]
    public function testHandlerWritingExpectedEventsAndContext(array $context, callable $asserter): void
    {
        $logChannel = new LogChannel($this->app);

        $logger = $logChannel();

        $logger->error('test message', $context);

        $lastEvent = $this->getLastSentryEvent();

        $this->assertNotNull($lastEvent);
        $this->assertEquals('test message', $lastEvent->getMessage());
        $this->assertEquals('error', $lastEvent->getLevel());

        $asserter($lastEvent);
    }

    public static function handlerDataProvider(): iterable
    {
        $context = ['foo' => 'bar'];

        yield [
            $context,
            function (Event $event) use ($context) {
                self::assertEquals($context, $event->getExtra()['log_context']);
            },
        ];

        $context = ['fingerprint' => ['foo', 'bar']];

        yield [
            $context,
            function (Event $event) use ($context) {
                self::assertEquals($context['fingerprint'], $event->getFingerprint());
                self::assertEmpty($event->getExtra());
            },
        ];

        $context = ['user' => 'invalid value'];

        yield [
            $context,
            function (Event $event) use ($context) {
                self::assertNull($event->getUser());
                self::assertEquals($context, $event->getExtra()['log_context']);
            },
        ];

        $context = ['user' => ['id' => 123]];

        yield [
            $context,
            function (Event $event) {
                self::assertNotNull($event->getUser());
                self::assertEquals(123, $event->getUser()->getId());
                self::assertEmpty($event->getExtra());
            },
        ];

        $context = ['tags' => [
            'foo' => 'bar',
            'bar' => 123,
        ]];

        yield [
            $context,
            function (Event $event) {
                self::assertSame([
                    'foo' => 'bar',
                    'bar' => '123',
                ], $event->getTags());
                self::assertEmpty($event->getExtra());
            },
        ];
    }

    /**
     * Create a Monolog record.
     */
    protected function record(Level $level, string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
        );
    }
}

class RecordingSentryHandler extends SentryHandler
{
    /** @var array<int, LogRecord> */
    public array $writtenRecords = [];

    protected function doWrite(LogRecord $record): void
    {
        $this->writtenRecords[] = $record;
    }
}

class RecordingLogsHandler extends LogsHandler
{
    /** @var array<int, LogRecord> */
    public array $writtenRecords = [];

    protected function doWrite(LogRecord $record): void
    {
        $this->writtenRecords[] = $record;
    }
}
