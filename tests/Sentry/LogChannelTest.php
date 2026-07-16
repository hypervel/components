<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Log\Handlers\FingersCrossedHandler;
use Hypervel\Sentry\LogChannel;
use Hypervel\Sentry\Logs\LogChannel as LogsLogChannel;
use Hypervel\Sentry\Logs\LogsHandler;
use Hypervel\Sentry\SentryHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Sentry\Event;

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
}
