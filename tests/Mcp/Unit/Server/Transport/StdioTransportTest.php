<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Transport;

use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\Transport\StdioTransport;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class StdioTransportTest extends TestCase
{
    public function testSetsReceiveHandler(): void
    {
        $transport = new StdioTransport;

        $handler = static function (string $message): void {};

        $transport->onReceive($handler);

        // Use reflection to check the handler was set
        $reflection = new ReflectionClass($transport);
        $property = $reflection->getProperty('handler');

        $this->assertSame($handler, $property->getValue($transport));
    }

    public function testSendsMessageToStdout(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../Fixtures/StdioServer.php', 'send'], timeout: 5);

        try {
            $process->mustRun();
            $this->assertSame('{"test": "message"}' . PHP_EOL, $process->getOutput());
        } finally {
            $process->stop(0);
        }
    }

    public function testExecutesStreamCallback(): void
    {
        $transport = new StdioTransport;

        $streamExecuted = false;
        $stream = function () use (&$streamExecuted): void {
            $streamExecuted = true;
        };

        $transport->stream($stream);

        $this->assertTrue($streamExecuted);
    }

    public function testHandlesRunMethodWithHandler(): void
    {
        // @TODO: Remove this skip once Swoole hooks standard streams inherited as sockets at process startup.
        if (version_compare(SWOOLE_VERSION, '6.3.0RC1', '<=')) {
            $this->markTestSkipped('Swoole leaves socket-backed standard input outside its coroutine hooks.');
        }

        // Swoole's hooked proc_open gives the child socket-backed standard input.
        $input = new InputStream;
        $input->write('Test ');
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../Fixtures/StdioServer.php'], input: $input, timeout: 5);

        try {
            $output = '';
            $process->start(static function (string $type, string $chunk) use ($input, &$output): void {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }

                if (! $input->isClosed() && str_contains($output, "ready\n")) {
                    $input->write("message\nAnother message\n");
                    $input->close();
                }
            });
            $this->assertSame(0, $process->wait());
            $this->assertSame("ready\nTest message\nAnother message\n", $process->getOutput());
        } finally {
            $input->close();
            $process->stop(0);
        }
    }

    public function testImplementsTransportInterface(): void
    {
        $transport = new StdioTransport;

        $this->assertInstanceOf(Transport::class, $transport);
    }
}
