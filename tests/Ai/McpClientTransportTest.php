<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Closure;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Attributes\ConcurrentTools;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Tools\McpTool;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\WebClient;
use Mockery as m;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Process\Process;

class McpClientTransportTest extends TestCase
{
    public function testConcurrentAiToolsUseIndependentHttpExchanges(): void
    {
        $this->withServer('concurrent', function (WebClient $client): void {
            $response = $this->generate($client);

            $this->assertSame(['first', 'second'], $response->toolResults->pluck('result')->all());
        });
    }

    public function testConcurrentAiToolsKeepStdioExchangesSerialized(): void
    {
        $client = Client::local(PHP_BINARY, [
            __DIR__ . '/../Mcp/Fixtures/Client/stdio-output.php', 'protocol', ProtocolVersion::LATEST->value,
        ])->withTimeout(3)->withProtocolVersion(ProtocolVersion::LATEST);

        try {
            $response = $this->generate($client);

            $this->assertSame(['first', 'second'], $response->toolResults->pluck('result')->all());
        } finally {
            $client->disconnect();
        }
    }

    public function testCancelingTheAiOperationClosesItsPendingMcpResponses(): void
    {
        $this->withServer('cancellation', function (WebClient $client, Process $process): void {
            $caught = null;
            $consumer = EngineCoroutine::create(function () use ($client, &$caught): void {
                try {
                    $this->generate($client, completes: false);
                } catch (CanceledException $exception) {
                    $caught = $exception;
                }
            });

            try {
                $this->assertTrue($process->waitUntil(fn (): bool => str_contains($process->getOutput(), 'waiting')));
                $this->assertTrue(EngineCoroutine::cancelById($consumer->getId(), throwException: true));
                Coroutine::join([$consumer->getId()], 3);

                $this->assertInstanceOf(CanceledException::class, $caught);
                $this->assertFalse(Coroutine::exists($consumer->getId()));
            } finally {
                if (Coroutine::exists($consumer->getId())) {
                    EngineCoroutine::cancelById($consumer->getId(), throwException: true);
                    Coroutine::join([$consumer->getId()], 3);
                }
            }
        });
    }

    /**
     * Exercise the real AI tool loop with deterministic model output and live MCP transports.
     */
    private function generate(Client $client, bool $completes = true): TextResponse
    {
        $gateway = m::mock(StepTextGateway::class);
        $gateway->shouldReceive('generateTextStep')->times($completes ? 2 : 1)->andReturn(
            new StepResponse('', [
                new ToolCall('first-call', 'mcp_tools_first', []),
                new ToolCall('second-call', 'mcp_tools_second', []),
            ], FinishReason::ToolCalls, new TextUsage, new Meta),
            new StepResponse('Finished', [], FinishReason::Stop, new TextUsage, new Meta),
        );
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('local-test');
        $tools = array_map(static fn (string $name): McpTool => new McpTool(Tool::from($client, [
            'name' => $name,
            'inputSchema' => ['type' => 'object'],
        ])), ['first', 'second']);

        return (new TextGenerationLoop($gateway))->generate(
            $provider,
            'model',
            '',
            tools: $tools,
            options: new TextGenerationOptions(agent: new McpTransportAgent('', [], [])),
        );
    }

    /**
     * Exercise the client against a bounded, independent HTTP server.
     *
     * @param Closure(WebClient, Process): void $callback
     */
    private function withServer(string $mode, Closure $callback): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../Mcp/Fixtures/Client/http-exchanges.php', $mode, ProtocolVersion::LATEST->value]);
        $process->setTimeout(8);
        $client = null;

        try {
            $process->start();
            $process->waitUntil(fn (): bool => str_contains($process->getOutput(), "\n"));
            $this->assertStringContainsString("\n", $process->getOutput(), $process->getErrorOutput());
            $client = Client::web('http://' . trim($process->getOutput()))
                ->withTimeout(3)
                ->withProtocolVersion(ProtocolVersion::LATEST);

            $callback($client, $process);
            $client->disconnect();

            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        } finally {
            try {
                $client?->disconnect();
            } finally {
                $process->stop(0);
            }
        }
    }
}

#[ConcurrentTools(max: 2)]
class McpTransportAgent extends AnonymousAgent
{
}
