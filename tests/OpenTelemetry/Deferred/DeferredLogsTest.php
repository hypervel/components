<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Deferred;

use Generator;
use Hypervel\OpenTelemetry\Deferred\Logs\DeferredLoggerProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Logs\NoopLogRecordBuilder;
use OpenTelemetry\Context\Context;

class DeferredLogsTest extends TestCase
{
    public function testPreBindLoggerDropsRecordsAndReplaysItsScopeAcrossBindings(): void
    {
        $provider = new DeferredLoggerProvider;
        $attributes = $this->scopeAttributes();
        $logger = $provider->getLogger('billing', '1.0', 'https://schema.test', $attributes);
        $record = new LogRecord('before-bind');
        $preBindBuilder = $logger->logRecordBuilder();

        $logger->emit($record);

        $this->assertFalse($logger->isEnabled());
        $this->assertInstanceOf(NoopLogRecordBuilder::class, $preBindBuilder);
        $this->assertFalse($attributes->valid());

        $workerBuilder = m::mock(LogRecordBuilderInterface::class);
        $workerLogger = m::mock(LoggerInterface::class);
        $workerLogger->shouldReceive('isEnabled')
            ->once()
            ->with(Context::getRoot(), 17, 'exception')
            ->andReturnTrue();
        $workerLogger->shouldReceive('logRecordBuilder')->once()->andReturn($workerBuilder);
        $workerLogger->shouldReceive('emit')->once()->with($record);
        $workerProvider = m::mock(LoggerProviderInterface::class);
        $workerProvider->shouldReceive('getLogger')
            ->once()
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->andReturn($workerLogger);

        $provider->bind($workerProvider);

        $this->assertTrue($logger->isEnabled(Context::getRoot(), 17, 'exception'));
        $this->assertSame($workerBuilder, $logger->logRecordBuilder());
        $logger->emit($record);
        $this->assertInstanceOf(NoopLogRecordBuilder::class, $preBindBuilder);

        $provider->unbind();

        $this->assertFalse($logger->isEnabled());
    }

    public function testLoggerRequestedAfterBindComesDirectlyFromTheWorkerProvider(): void
    {
        $provider = new DeferredLoggerProvider;
        $workerLogger = m::mock(LoggerInterface::class);
        $workerProvider = m::mock(LoggerProviderInterface::class);
        $workerProvider->shouldReceive('getLogger')
            ->once()
            ->with('worker', null, null, [])
            ->andReturn($workerLogger);

        $provider->bind($workerProvider);

        $this->assertSame($workerLogger, $provider->getLogger('worker'));
    }

    /**
     * Yield one instrumentation-scope attribute.
     *
     * @return Generator<non-empty-string, string>
     */
    private function scopeAttributes(): Generator
    {
        yield 'scope.kind' => 'test';
    }
}
