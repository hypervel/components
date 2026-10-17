<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client\Transport;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\TimeoutException;
use Hypervel\Mcp\Client\Transport\StdioTransport;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

use function Hypervel\Coroutine\parallel;

class StdioTransportTest extends TestCase
{
    public function testReceivesOutputAlreadyReadByProcessStatusChecksAndRetainsSplitFrames(): void
    {
        $transport = new InspectableStdioTransport('frames');

        try {
            $transport->connect();
            $this->assertTrue($transport->readInitialOutput());

            $this->assertSame("first\n", $transport->receive());
            $this->assertSame("second\n", $transport->receive());

            $transport->send('continue');

            $this->assertSame("partial-complete\n", $transport->receive());
        } finally {
            $transport->disconnect();
        }
    }

    public function testReceivesTheFinalFrameAfterTheProcessHasExited(): void
    {
        $transport = new InspectableStdioTransport('final');

        try {
            $transport->connect();
            $transport->waitForExit();

            $this->assertSame("final\n", $transport->receive());
        } finally {
            $transport->disconnect();
        }
    }

    public function testIncompleteOutputReportsABoundedDiagnosticTailAndDisconnects(): void
    {
        $transport = new InspectableStdioTransport('incomplete');
        $caught = null;

        try {
            $transport->connect();
            $transport->receive();
        } catch (ClientException $exception) {
            $caught = $exception;
        } finally {
            $transport->disconnect();
        }

        $this->assertInstanceOf(ClientException::class, $caught);
        $this->assertStringContainsString('closed its output before sending a complete response', $caught->getMessage());
        $this->assertStringEndsWith(':diagnostic-tail', $caught->getMessage());
        $this->assertStringNotContainsString('discarded-prefix:', $caught->getMessage());
        $this->assertLessThan(8500, strlen($caught->getMessage()));
    }

    public function testSilentProcessTimesOut(): void
    {
        $transport = new InspectableStdioTransport('silent');
        $transport->setTimeoutSeconds(0.05);

        try {
            $transport->connect();
            $this->expectException(TimeoutException::class);
            $this->expectExceptionMessage('Timed out while waiting for server response.');
            $transport->receive();
        } finally {
            $transport->disconnect();
        }
    }

    public function testDisconnectDetachesOwnedStateBeforePropagatingCancellation(): void
    {
        $cancellation = new CanceledException('Stop canceled.');
        $process = m::mock(Process::class);
        $process->shouldReceive('isRunning')->once()->andReturnTrue();
        $process->shouldReceive('stop')->once()->with(0.1, null)->ordered()->andThrow($cancellation);
        $process->shouldReceive('stop')->once()->with(0, null)->ordered()->andReturn(0);
        $input = new InputStream;
        $transport = new InspectableStdioTransport('silent');
        $transport->installProcess($process, $input);
        $caught = null;

        try {
            $transport->disconnect();
        } catch (CanceledException $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertTrue($input->isClosed());
        $this->assertSame([null, null, '', ''], $transport->ownedState());
        $transport->disconnect();
    }

    public function testConcurrentCallsSerializeWhileTheActiveExchangeCanAnswerServerRequests(): void
    {
        $client = (new Client(new InspectableStdioTransport('protocol'), new Implementation('Test client', '1.0')))
            ->withProtocolVersion(ProtocolVersion::LATEST);

        try {
            $client->connect();
            $results = parallel([
                fn (): string => $client->callTool('first')->text(),
                fn (): string => $client->callTool('second')->text(),
            ]);

            $this->assertSame(['first', 'second'], $results);
        } finally {
            $client->disconnect();
        }
    }
}

class InspectableStdioTransport extends StdioTransport
{
    /**
     * Create a transport using the local output fixture.
     */
    public function __construct(string $mode)
    {
        parent::__construct(PHP_BINARY, [__DIR__ . '/../../../Fixtures/Client/stdio-output.php', $mode, ProtocolVersion::LATEST->value]);
        $this->setTimeoutSeconds(2);
    }

    /**
     * Read subprocess output before the transport starts consuming it.
     */
    public function readInitialOutput(): bool
    {
        $this->process->setTimeout(3);

        return $this->process->waitUntil(fn (): bool => str_contains($this->process->getOutput(), 'partial'));
    }

    /**
     * Wait for the fixture to finish before reading its final frame.
     */
    public function waitForExit(): void
    {
        $this->process->setTimeout(3);
        $this->process->wait();
    }

    /**
     * Install owned state for the cleanup regression.
     */
    public function installProcess(Process $process, InputStream $input): void
    {
        $this->process = $process;
        $this->input = $input;
        $this->buffer = 'partial';
        $this->stderr = 'diagnostic';
    }

    /**
     * Get the transport's retained resources and output.
     */
    public function ownedState(): array
    {
        return [$this->process, $this->input, $this->buffer, $this->stderr];
    }
}
