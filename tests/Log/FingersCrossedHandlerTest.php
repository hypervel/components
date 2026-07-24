<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use DateTimeImmutable;
use Hypervel\Engine\Channel;
use Hypervel\Log\Handlers\FingersCrossedHandler;
use Hypervel\Tests\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;

use function Hypervel\Coroutine\parallel;

class FingersCrossedHandlerTest extends TestCase
{
    public function testBuffersAndActivationAreIsolatedBetweenCoroutines(): void
    {
        $underlying = new TestHandler;
        $handler = new FingersCrossedHandler($underlying, Level::Critical);
        $buffered = new Channel(1);
        $release = new Channel(1);

        parallel([
            function () use ($handler, $buffered, $release): void {
                $handler->handle($this->record(Level::Debug, 'first debug'));
                $buffered->push(true);
                $release->pop();
            },
            function () use ($handler, $buffered, $release): void {
                $buffered->pop();
                $handler->handle($this->record(Level::Critical, 'second critical'));
                $release->push(true);
            },
        ]);

        $this->assertSame(
            ['second critical'],
            array_map(
                static fn (LogRecord $record): string => $record->message,
                $underlying->getRecords()
            )
        );
    }

    public function testCopiedCoroutineStartsWithAFreshBuffer(): void
    {
        $underlying = new TestHandler;
        $handler = new FingersCrossedHandler($underlying, Level::Critical);
        $handler->handle($this->record(Level::Debug, 'parent debug'));

        parallel([
            fn () => $handler->handle($this->record(Level::Critical, 'child critical')),
        ], copyContext: true);

        $this->assertSame(
            ['child critical'],
            array_map(
                static fn (LogRecord $record): string => $record->message,
                $underlying->getRecords()
            )
        );
    }

    private function record(Level $level, string $message): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: $level,
            message: $message,
            context: [],
            extra: [],
        );
    }
}
