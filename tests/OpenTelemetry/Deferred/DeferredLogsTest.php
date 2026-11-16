<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Deferred;

use Generator;
use Hypervel\OpenTelemetry\Deferred\Logs\DeferredLoggerProvider;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LoggerProviderInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use OpenTelemetry\API\Logs\NoopLogRecordBuilder;
use OpenTelemetry\Context\Context;

class DeferredLogsTest extends TestCase
{
    use VerifiesDoubles;

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

        $workerBuilder = Double::for(LogRecordBuilderInterface::class)->strict();
        $workerLogger = Double::for(LoggerInterface::class)->strict();
        $workerLogger->expects('isEnabled')
            ->with(Context::getRoot(), 17, 'exception')
            ->returns(true);
        $workerLogger->expects('logRecordBuilder')->returns($workerBuilder);
        $workerLogger->expects('emit')->with($record);
        $workerProvider = Double::for(LoggerProviderInterface::class)->strict();
        $workerProvider->expects('getLogger')
            ->with('billing', '1.0', 'https://schema.test', ['scope.kind' => 'test'])
            ->returns($workerLogger);

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
        $workerLogger = Double::for(LoggerInterface::class)->strict();
        $workerProvider = Double::for(LoggerProviderInterface::class)->strict();
        $workerProvider->expects('getLogger')
            ->with('worker', null, null, [])
            ->returns($workerLogger);

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
