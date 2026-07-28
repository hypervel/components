<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\ListFailedCommand;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueListFailedCommandTest extends TestCase
{
    public function testQueuedListenerShowsUnderlyingListenerClassNotWrapper(): void
    {
        // CallQueuedListener is the wrapper class Hypervel uses to dispatch
        // queued event listeners. The legacy regex matched the wrapper out
        // of data.command — payload.displayName carries the actual listener.
        $output = $this->runListFailedCommandWith([
            $this->fakeFailedJob([
                'displayName' => 'App\Listeners\HandleStripeWebhookHandled',
                'data' => [
                    'commandName' => 'Hypervel\Events\CallQueuedListener',
                    'command' => 'O:35:"Hypervel\Events\CallQueuedListener":3:{s:5:"class";s:43:"App\Listeners\HandleStripeWebhookHandled";s:6:"method";s:6:"handle";s:4:"data";a:0:{}}',
                ],
            ]),
        ]);

        $this->assertStringContainsString('App\Listeners\HandleStripeWebhookHandled', $output);
        $this->assertStringNotContainsString('CallQueuedListener', $output);
    }

    public function testRegularQueuedJobStillShowsJobClass(): void
    {
        // Regression: the common ShouldQueue path must keep showing the job class.
        $output = $this->runListFailedCommandWith([
            $this->fakeFailedJob([
                'displayName' => 'App\Jobs\ProcessPodcast',
                'data' => [
                    'commandName' => 'App\Jobs\ProcessPodcast',
                    'command' => 'O:25:"App\Jobs\ProcessPodcast":0:{}',
                ],
            ]),
        ]);

        $this->assertStringContainsString('App\Jobs\ProcessPodcast', $output);
    }

    public function testPayloadWithoutDisplayNameFallsBackToSerializedCommand(): void
    {
        // Payloads without displayName fall back to the serialized command class.
        $output = $this->runListFailedCommandWith([
            $this->fakeFailedJob([
                'data' => [
                    'commandName' => 'App\Jobs\LegacyJob',
                    'command' => 'O:18:"App\Jobs\LegacyJob":0:{}',
                ],
            ]),
        ]);

        $this->assertStringContainsString('App\Jobs\LegacyJob', $output);
    }

    public function testEncryptedJobShowsUnderlyingClass(): void
    {
        // Encrypted queue payloads store ciphertext in data.command, so the
        // legacy regex falls back to CallQueuedHandler@call (the value at
        // payload.job). displayName carries the real underlying class.
        $output = $this->runListFailedCommandWith([
            $this->fakeFailedJob([
                'displayName' => 'App\Jobs\ProcessOrder',
                'job' => 'Hypervel\Queue\CallQueuedHandler@call',
                'data' => [
                    'commandName' => 'Hypervel\Queue\CallEncryptedQueuedHandler',
                    'command' => 'eyJpdiI6IlhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFg9IiwidmFsdWUiOiJjaXBoZXJ0ZXh0LWJsb2Itd2l0aC1uby1jbGFzcy1uYW1lcyIsIm1hYyI6ImZha2UifQ==',
                ],
            ]),
        ]);

        $this->assertStringContainsString('App\Jobs\ProcessOrder', $output);
        $this->assertStringNotContainsString('CallQueuedHandler', $output);
    }

    public function testMalformedPayloadDoesNotThrow(): void
    {
        // Malformed JSON in the payload column must not bubble up an exception;
        // the row should render with an empty Class cell.
        $output = $this->runListFailedCommandWithRawPayload('not-json-at-all');

        $this->assertStringContainsString('1', $output);
    }

    /**
     * Build a fake failed_jobs row with an encoded JSON payload.
     */
    private function fakeFailedJob(array $payload): array
    {
        return [
            'id' => 1,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode($payload + ['uuid' => 'fake-uuid'], JSON_THROW_ON_ERROR),
            'exception' => 'Exception: boom',
            'failed_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * Build a row with a raw (possibly malformed) payload string.
     */
    private function rawFailedJob(string $rawPayload): array
    {
        return [
            'id' => 1,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $rawPayload,
            'exception' => 'Exception: boom',
            'failed_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * Run queue:failed with the given failed_jobs rows and return captured output.
     */
    private function runListFailedCommandWith(array $rows): string
    {
        return $this->executeCommand($rows);
    }

    /**
     * Run queue:failed with a single raw-payload row and return captured output.
     */
    private function runListFailedCommandWithRawPayload(string $rawPayload): string
    {
        return $this->executeCommand([$this->rawFailedJob($rawPayload)]);
    }

    /**
     * Wire up a stub failer, run the command, and return the buffered output.
     */
    private function executeCommand(array $rows): string
    {
        $container = new Application;

        // The command resolves the failer via the queue.failer container binding.
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('all')->andReturn($rows);
        $container->instance('queue.failer', $failer);

        $command = new ListFailedCommand;
        $command->setHypervel($container);

        $output = new BufferedOutput;
        $command->run(new ArrayInput([]), $output);

        return $output->fetch();
    }
}
