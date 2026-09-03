<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Contracts;

use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

interface ExporterFactory
{
    /**
     * Create a span exporter.
     */
    public function spanExporter(array $config): SpanExporterInterface;

    /**
     * Create a metric exporter.
     */
    public function metricExporter(array $config): MetricExporterInterface;

    /**
     * Create a log-record exporter.
     */
    public function logExporter(array $config): LogRecordExporterInterface;
}
