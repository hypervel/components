<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Exporters;

use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory as LogsConsoleExporterFactory;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\ConsoleMetricExporterFactory;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class ConsoleExporterFactory implements ExporterFactory
{
    /**
     * Create a console span exporter.
     */
    public function spanExporter(array $config): SpanExporterInterface
    {
        return (new ConsoleSpanExporterFactory)->create();
    }

    /**
     * Create a console metric exporter.
     */
    public function metricExporter(array $config): MetricExporterInterface
    {
        return (new ConsoleMetricExporterFactory)->create();
    }

    /**
     * Create a console log-record exporter.
     */
    public function logExporter(array $config): LogRecordExporterInterface
    {
        return (new LogsConsoleExporterFactory)->create();
    }
}
