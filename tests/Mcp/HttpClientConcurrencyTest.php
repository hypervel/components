<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp;

use Closure;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\WebClient;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Http\Fixtures\StreamingHttpFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;

use function Hypervel\Coroutine\parallel;

class HttpClientConcurrencyTest extends TestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function testOneClientCanCompleteOverlappingCallsWithTheirMatchingReplies(bool $native): void
    {
        Http::swap(new StreamingHttpFactory($native));

        $this->withServer('concurrent', function (WebClient $client): void {
            $results = parallel([
                fn (): string => $client->callTool('first')->text(),
                fn (): string => $client->callTool('second')->text(),
            ]);

            $this->assertSame(['first', 'second'], $results);
        });
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testATerminalSseReplyCompletesWithoutWaitingForEof(bool $native): void
    {
        Http::swap(new StreamingHttpFactory($native));

        $this->withServer('sse', function (WebClient $client): void {
            $this->assertSame('finished', $client->callTool('first')->text());
        });
    }

    public function testAFailedExchangeDoesNotCloseItsPendingSibling(): void
    {
        $this->withServer('failure', function (WebClient $client): void {
            $results = parallel([
                function () use ($client): ClientException {
                    try {
                        $client->callTool('first');
                        $this->fail('The malformed response must fail its call.');
                    } catch (ClientException $exception) {
                        return $exception;
                    }
                },
                fn (): string => $client->callTool('second')->text(),
            ]);

            $this->assertStringContainsString('Malformed JSON-RPC response', $results[0]->getMessage());
            $this->assertSame('second', $results[1]);
        });
    }

    /**
     * Exercise the client against a bounded, independent HTTP server.
     *
     * @param Closure(WebClient): void $callback
     */
    private function withServer(string $mode, Closure $callback): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/Fixtures/Client/http-exchanges.php', $mode, ProtocolVersion::LATEST->value]);
        $process->setTimeout(8);
        $client = null;

        try {
            $process->start();
            $process->waitUntil(fn (): bool => str_contains($process->getOutput(), "\n"));
            $this->assertStringContainsString("\n", $process->getOutput(), $process->getErrorOutput());
            $client = Client::web('http://' . trim($process->getOutput()))
                ->withTimeout(3)
                ->withProtocolVersion(ProtocolVersion::LATEST);

            $callback($client);
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
