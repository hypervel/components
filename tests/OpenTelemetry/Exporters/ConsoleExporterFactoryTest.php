<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Exporters;

use Hypervel\OpenTelemetry\Exporters\ConsoleExporterFactory;
use Hypervel\Tests\TestCase;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporter;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;

class ConsoleExporterFactoryTest extends TestCase
{
    public function testCreatesTheSdkConsoleExporterForEverySignal(): void
    {
        $factory = new ConsoleExporterFactory;

        $this->assertInstanceOf(ConsoleSpanExporter::class, $factory->spanExporter([]));
        $this->assertInstanceOf(ConsoleMetricExporter::class, $factory->metricExporter([]));
        $this->assertInstanceOf(ConsoleExporter::class, $factory->logExporter([]));
    }
}
