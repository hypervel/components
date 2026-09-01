<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\OpenTelemetry\Server;

use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\OpenTelemetry\Exporters\OtlpExporterFactory;
use Hypervel\Tests\TestCase;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use Swoole\Coroutine\Channel;
use Throwable;

use function Hypervel\Coroutine\go;

class OtlpExporterIntegrationTest extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19501;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInteractsWithServer();
    }

    public function testSlowOtlpExportYieldsToOtherCoroutines(): void
    {
        $exporter = new RecordingSpanExporter(
            (new OtlpExporterFactory)->spanExporter($this->exporterConfiguration()),
        );
        $provider = (new TracerProviderBuilder)
            ->addSpanProcessor(new BatchSpanProcessor(
                $exporter,
                Clock::getDefault(),
                maxQueueSize: 8,
                scheduledDelayMillis: 60_000,
                maxExportBatchSize: 8,
                autoFlush: false,
            ))
            ->build();
        $provider->getTracer('hypervel.integration')
            ->spanBuilder('slow-otlp-export')
            ->startSpan()
            ->end();

        $exportStarted = new Channel(1);
        $exportFinished = new Channel(1);
        $heartbeat = new Channel(1);

        go(static function () use ($provider, $exportStarted, $exportFinished): void {
            $exportStarted->push(true);

            try {
                $exportFinished->push($provider->forceFlush() ? 'success' : 'failure');
            } catch (Throwable $exception) {
                $exportFinished->push($exception);
            }
        });

        $started = $exportStarted->pop(1.0);
        go(static fn (): bool => $heartbeat->push(true));
        $heartbeatObserved = $heartbeat->pop(1.0);
        $prematureResult = $exportFinished->pop(0.001);
        $result = $prematureResult === false
            ? $exportFinished->pop(4.0)
            : $prematureResult;
        $shutdown = $provider->shutdown();

        $this->assertTrue($started);
        $this->assertTrue($heartbeatObserved);
        $this->assertFalse($prematureResult, 'The export completed before another coroutine could run.');
        $this->assertSame('success', $result);
        $this->assertSame([true], $exporter->results);
        $this->assertTrue($shutdown);
    }

    /**
     * Return OTLP settings for the delayed engine test endpoint.
     */
    private function exporterConfiguration(): array
    {
        $endpoint = sprintf(
            'http://%s:%d/timeout?time=2&body=%%7B%%7D',
            $this->getServerHost(),
            $this->getServerPort(),
        );

        return [
            'endpoint' => $endpoint,
            'protocol' => Protocols::HTTP_JSON,
            'headers' => [],
            'compression' => 'none',
            'timeout' => 5000,
            'certificate' => null,
            'client_certificate' => null,
            'client_key' => null,
            'traces_endpoint' => $endpoint,
            'traces_protocol' => null,
            'traces_headers' => null,
            'traces_compression' => null,
            'traces_timeout' => null,
            'traces_certificate' => null,
            'traces_client_certificate' => null,
            'traces_client_key' => null,
            'max_retries' => 0,
        ];
    }
}

class RecordingSpanExporter implements SpanExporterInterface
{
    /** @var list<bool> */
    public array $results = [];

    /**
     * Create a recording span exporter.
     */
    public function __construct(private SpanExporterInterface $exporter)
    {
    }

    /**
     * Export a batch of spans.
     *
     * @param iterable<SpanDataInterface> $batch
     */
    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        return $this->exporter->export($batch, $cancellation)->map(function (bool $result): bool {
            $this->results[] = $result;

            return $result;
        });
    }

    /**
     * Shut down the exporter.
     */
    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->shutdown($cancellation);
    }

    /**
     * Force the exporter to flush.
     */
    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->forceFlush($cancellation);
    }
}
