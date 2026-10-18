<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Socket;
use Throwable;

class BedrockHttpTest extends TestCase
{
    use BedrockHelpers;
    use OpenAiHelpers;

    #[DataProvider('generationModes')]
    public function testAgentCallsFailOverAfterAnAwsQuotaError(bool $stream): void
    {
        config([
            'ai.providers.bedrock' => [
                'driver' => 'bedrock',
                'region' => 'us-east-1',
                'access_key_id' => 'test-key',
                'secret_access_key' => 'test-secret',
            ],
            'ai.providers.openai.key' => 'test-key',
        ]);
        $fallback = $stream
            ? $this->ssePayload([$this->responseCreated(), $this->outputTextDelta('Backup'), $this->outputTextDone('Backup'), $this->responseCompleted(2, 1)])
            : $this->responseCompleted(2, 1, output: [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Backup']],
            ]])['response'];
        Http::preventStrayRequests();
        Http::fake([
            'https://bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'The account quota has been exceeded.'],
                400,
                ['x-amzn-errortype' => 'ServiceQuotaExceededException'],
            ),
            'https://api.openai.com/*' => Http::response($fallback),
        ]);

        $agent = new OpenAiAgent;
        $providers = ['bedrock' => 'test-model', 'openai' => 'gpt-5'];

        if ($stream) {
            $response = $agent->stream('Hello', provider: $providers);
            iterator_to_array($response);
        } else {
            $response = $agent->prompt('Hello', provider: $providers);
        }

        $this->assertSame('Backup', $response->text);
        Http::assertSentCount(2);
    }

    /**
     * Provide both public generation paths.
     */
    public static function generationModes(): array
    {
        return [
            'buffered' => [false],
            'streamed' => [true],
        ];
    }

    #[DataProvider('interruptionModes')]
    public function testInterruptedAgentStreamsCloseTheAwsResponseAndAllowAnotherStream(bool $cancel): void
    {
        $closed = new Channel(1);
        $received = new Channel(1);
        $finished = new Channel(1);
        $first = $this->eventFrame('messageStart', '{"role":"assistant"}')
            . $this->eventFrame('contentBlockDelta', '{"contentBlockIndex":0,"delta":{"text":"Hello"}}');
        $next = $this->eventFrame('messageStart', '{"role":"assistant"}')
            . $this->eventFrame('contentBlockDelta', '{"contentBlockIndex":0,"delta":{"text":"Again"}}')
            . $this->eventFrame('contentBlockStop', '{"contentBlockIndex":0}')
            . $this->eventFrame('messageStop', '{"stopReason":"end_turn"}')
            . $this->eventFrame('metadata', '{"usage":{"inputTokens":2,"outputTokens":1,"totalTokens":3},"metrics":{"latencyMs":1}}');
        $server = LoopbackHttpServer::start([
            static function (Socket $client) use ($first, $closed): void {
                $client->sendAll("HTTP/1.1 200 OK\r\nContent-Type: application/vnd.amazon.eventstream\r\nConnection: close\r\n\r\n" . $first);
                $closed->push($client->recv(2));
            },
            ['headers' => ['Content-Type' => 'application/vnd.amazon.eventstream'], 'body' => $next],
        ]);
        config(['ai.providers.bedrock' => [
            'driver' => 'bedrock',
            'region' => 'us-east-1',
            'access_key_id' => 'test-key',
            'secret_access_key' => 'test-secret',
        ]]);

        $this->withEnvironmentValue('AWS_ENDPOINT_URL_BEDROCK_RUNTIME', 'http://127.0.0.1:' . $server->port, function () use ($cancel, $closed, $received, $finished): void {
            $reader = Coroutine::create(static function () use ($cancel, $received, $finished): void {
                try {
                    foreach ((new OpenAiAgent)->stream('Hello', provider: 'bedrock', model: 'test-model') as $event) {
                        if ($event instanceof TextDelta) {
                            $received->push($event->delta);

                            if (! $cancel) {
                                break;
                            }
                        }
                    }

                    $finished->push(true);
                } catch (Throwable $exception) {
                    $finished->push($exception);
                }
            });

            try {
                $this->assertSame('Hello', $received->pop(2));

                if ($cancel) {
                    // Let the consumer enter the next event's I/O wait after its channel notification.
                    usleep(1000);
                    $this->assertTrue(Coroutine::cancelById($reader->getId()));
                    $this->assertInstanceOf(CanceledException::class, $finished->pop(2));
                } else {
                    $this->assertTrue($finished->pop(2));
                }

                $this->assertSame('', $closed->pop(3));
                $response = (new OpenAiAgent)->stream('Another request', provider: 'bedrock', model: 'test-model');
                iterator_to_array($response);
                $this->assertSame('Again', $response->text);
            } finally {
                if (Coroutine::exists($reader->getId())) {
                    Coroutine::cancelById($reader->getId());
                    Coroutine::join([$reader->getId()], 2);
                }

                $closed->close();
                $received->close();
                $finished->close();
            }
        });
    }

    /**
     * Provide consumer exits while the provider is quiet.
     */
    public static function interruptionModes(): array
    {
        return [
            'abandonment' => [false],
            'cancellation' => [true],
        ];
    }
}
